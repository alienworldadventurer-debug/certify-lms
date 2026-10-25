<?php

declare(strict_types=1);

namespace App\UseCases\AiChatConversation;

use App\Models\AiChatConversation;

/**
 * 会話のタイトルを更新するユースケース。
 */
final class UpdateAction
{
    public function __invoke(AiChatConversation $conversation, string $title): AiChatConversation
    {
        $conversation->update(['title' => $title]);

        return $conversation->refresh();
    }
}
