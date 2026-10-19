<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Announcement;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AdminAnnouncementNotification extends Notification
{
    private const PREVIEW_LENGTH = 80;

    public function __construct(
        private readonly Announcement $announcement,
        private readonly string $notificationId,
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
            'notification_type' => 'admin_announcement',
            'title' => $this->announcement->title,
            'message' => $this->preview(),
            'body' => $this->announcement->body,
            'announcement_id' => $this->announcement->id,
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('【Certify LMS】'.$this->announcement->title)
            ->greeting($this->escapeMarkdown("{$notifiable->name}さん、いつもCertify LMSをご利用いただきありがとうございます。"));

        foreach (preg_split('/\r\n|\r|\n/', $this->announcement->body) ?: [] as $line) {
            $mail->line($line === '' ? ' ' : $this->escapeMarkdown($line));
        }

        return $mail->action('通知を確認する', route('notifications.show', $this->notificationId));
    }

    private function preview(): string
    {
        $flat = str_replace(["\r\n", "\r", "\n"], ' ', $this->announcement->body);

        if (mb_strlen($flat) <= self::PREVIEW_LENGTH) {
            return $flat;
        }

        return mb_substr($flat, 0, self::PREVIEW_LENGTH).'…';
    }

    private function escapeMarkdown(string $value): string
    {
        $escaped = '';
        $markdownPunctuation = '\\`*_{}[]()#+-.!|><&';

        foreach (mb_str_split($value) as $character) {
            $escaped .= str_contains($markdownPunctuation, $character)
                ? '\\'.$character
                : $character;
        }

        return $escaped;
    }
}
