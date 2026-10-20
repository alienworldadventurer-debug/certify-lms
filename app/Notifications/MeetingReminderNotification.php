<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\MeetingReminderWindow;
use App\UseCases\Meeting\SendMeetingReminderAction;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 面談リマインダー通知。本文は「日時／資格／相談内容」のみで、挨拶や案内文を含めない。
 *
 * 差し込み値は配信時点の値を `data` に保存する（後日の変更で文面を書き換えない）。
 *
 * @see SendMeetingReminderAction
 */
final class MeetingReminderNotification extends Notification
{
    /**
     * @param array{
     *     title: string,
     *     message: string,
     *     body: string,
     *     schedule_label: string,
     *     certification_name: string,
     *     topic: string,
     *     counterpart_label: string,
     *     target_id: string,
     *     url: string,
     *     reminder_window: string,
     *     source_id: string
     * } $data
     */
    public function __construct(
        private readonly array $data,
        private readonly MeetingReminderWindow $window,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase($notifiable): array
    {
        return [
            'notification_type' => 'meeting_reminder',
            'title' => $this->data['title'],
            'message' => $this->data['message'],
            'body' => $this->data['body'],
            'target_type' => 'meeting',
            'target_id' => $this->data['target_id'],
            'source_type' => 'meeting',
            'source_id' => $this->data['source_id'],
            'url' => $this->data['url'],
            'reminder_window' => $this->window->value,
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        // MailMessage::line() は文中の改行を空白に潰すため、相談内容は行ごとに分けて改行を保持する
        $topicLines = array_values(array_filter(
            preg_split('/\R/u', $this->data['topic']) ?: [''],
            fn (string $line) => trim($line) !== '',
        ));

        // 仕様上、挨拶文は付けない。空文字だと Laravel 標準の「Hello!」が出るため空白を指定する
        $mail = (new MailMessage)
            ->subject($this->data['title'])
            ->greeting(' ')
            ->line('日時：'.$this->data['schedule_label'])
            ->line('資格：'.$this->escapeMarkdown($this->data['certification_name']))
            ->line('相談内容：'.$this->escapeMarkdown($topicLines[0] ?? ''));

        foreach (array_slice($topicLines, 1) as $line) {
            $mail->line($this->escapeMarkdown($line));
        }

        return $mail->action('内容を確認する', $this->data['url']);
    }

    /** Markdown の記法として解釈されないよう、記号をエスケープする(BusinessEventNotification と同方式)。 */
    private function escapeMarkdown(string $value): string
    {
        $escaped = '';
        $markdownPunctuation = '\\`*_{}[]()#+-.!|>';

        foreach (str_split($value) as $character) {
            $escaped .= str_contains($markdownPunctuation, $character)
                ? '\\'.$character
                : $character;
        }

        return $escaped;
    }
}
