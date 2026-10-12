<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class BusinessEventNotification extends Notification
{
    /**
     * @param array{
     *     notification_type: string,
     *     title: string,
     *     message: string,
     *     body: string,
     *     greeting: string,
     *     target_type: string,
     *     target_id: string,
     *     url: string,
     *     meeting_start_at?: string,
     *     source_type?: string,
     *     source_id?: string
     * } $data
     */
    public function __construct(
        private readonly array $data,
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
        return $this->data;
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->data['title'])
            ->greeting($this->escapeMarkdown($this->data['greeting']))
            ->line($this->escapeMarkdown($this->data['message']));

        if (isset($this->data['meeting_start_at'])) {
            $mail->line('面談開始日時：'.$this->data['meeting_start_at'].'（日本時間）');
        }

        return $mail->action('内容を確認する', $this->data['url']);
    }

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
