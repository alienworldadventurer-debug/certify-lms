<?php

declare(strict_types=1);

namespace App\UseCases\AiChatConversation;

use App\Models\AiChatConversation;

/**
 * 会話とそのメッセージを削除するユースケース。
 *
 * メッセージは外部キーの連動削除で消える。送信回数（ai_chat_daily_usages）は戻さない。
 */
final class DestroyAction
{
    public function __invoke(AiChatConversation $conversation): void
    {
        $conversation->delete();
    }
}
