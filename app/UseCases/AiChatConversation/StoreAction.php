<?php

declare(strict_types=1);

namespace App\UseCases\AiChatConversation;

use App\Enums\EnrollmentStatus;
use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Exceptions\AiChat\AiChatLlmFailedException;
use App\Exceptions\AiChat\AiChatUnavailableException;
use App\Models\AiChatConversation;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;

/**
 * 新しい相談を開始するユースケース。
 *
 * - `section_id` があり同一 Section の会話が既にあれば、新規作成せずその会話を返す（created = false）。
 * - なければ会話を作成する。本文があれば最初の質問として送信する。
 * - 最初の質問が送信できなかった（API キー未設定・上限超過）場合は、作成した会話を削除して例外を投げる。
 */
final class StoreAction
{
    public function __construct(
        private readonly StoreMessageAction $storeMessage,
    ) {}

    /**
     * @return array{conversation: AiChatConversation, created: bool, error: AiChatLlmFailedException|null}
     *
     * @throws AiChatUnavailableException
     * @throws AiChatDailyLimitExceededException
     */
    public function __invoke(User $user, ?string $message, ?string $sectionId): array
    {
        $section = $sectionId !== null ? Section::query()->find($sectionId) : null;

        $existing = $section !== null
            ? AiChatConversation::query()
                ->where('user_id', $user->id)
                ->where('section_id', $section->id)
                ->latest('last_message_at')
                ->first()
            : null;

        if ($existing !== null) {
            $conversation = $existing;
            $created = false;
        } else {
            $conversation = AiChatConversation::query()->create([
                'user_id' => $user->id,
                'enrollment_id' => $this->resolveEnrollment($user, $section)?->id,
                'section_id' => $section?->id,
                'title' => $this->provisionalTitle($message),
            ]);
            $created = true;
        }

        $error = null;

        if ($message !== null) {
            try {
                $this->storeMessage->__invoke($conversation, $message);
            } catch (AiChatLlmFailedException $e) {
                $error = $e;
            } catch (AiChatUnavailableException|AiChatDailyLimitExceededException $e) {
                if ($created) {
                    $conversation->delete();
                }

                throw $e;
            }
        }

        return [
            'conversation' => $conversation->refresh(),
            'created' => $created,
            'error' => $error,
        ];
    }

    private function resolveEnrollment(User $user, ?Section $section): ?Enrollment
    {
        $query = Enrollment::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [EnrollmentStatus::Learning->value, EnrollmentStatus::Passed->value])
            ->orderByRaw('status = ? desc', [EnrollmentStatus::Learning->value])
            ->latest();

        if ($section !== null) {
            $certificationId = $section->chapter->part->certification_id;
            $query->where('certification_id', $certificationId);
        }

        return $query->first();
    }

    /**
     * 仮タイトル。最初の質問の先頭 30 文字、本文なしのときは「新しい相談」。
     */
    private function provisionalTitle(?string $message): string
    {
        return $message !== null ? mb_substr($message, 0, 30) : '新しい相談';
    }
}
