<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * AI 相談の開発用サンプル会話を投入する。
 *
 * 対象は固定の受講生 `student@certify-lms.test`。今日・過去7日・過去30日にまたがる会話、
 * 教材由来・資格由来・全般の会話、AI 応答がエラー状態の会話を含む。
 *
 * 依存順序: UserSeeder → EnrollmentSeeder → LearningSeeder（教材の取得）→ 本 Seeder。
 * 同じタイトルの会話が既にあれば作成しないため、何度実行しても重複しない。
 */
class AiChatSeeder extends Seeder
{
    public function run(): void
    {
        $student = User::query()
            ->where('email', 'student@certify-lms.test')
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::InProgress->value)
            ->first();

        if ($student === null) {
            $this->command?->warn('AiChatSeeder: 学習中の student@certify-lms.test が必要です。先に UserSeeder を実行してください。');

            return;
        }

        $enrollment = Enrollment::query()
            ->where('user_id', $student->id)
            ->where('status', EnrollmentStatus::Learning->value)
            ->with('certification')
            ->first();

        if ($enrollment === null) {
            $this->command?->warn('AiChatSeeder: 受講中の資格がありません。先に EnrollmentSeeder を実行してください。');

            return;
        }

        $section = Section::query()
            ->published()
            ->whereHas('chapter.part', fn ($q) => $q->where('certification_id', $enrollment->certification_id))
            ->orderBy('id')
            ->first();

        $this->seedConversation(
            $student,
            title: '合格ラインの考え方を知りたい',
            lastMessageAt: now()->subHours(2),
            enrollment: $enrollment,
            section: $section,
            exchanges: [
                ['question' => '合格ラインの考え方を教えてください。', 'answer' => '一般に、全体の 6 割程度の正答が目安とされています。'],
            ],
        );

        $this->seedConversation(
            $student,
            title: '過去問の解き方',
            lastMessageAt: now()->subDays(3),
            enrollment: $enrollment,
            section: null,
            exchanges: [
                ['question' => '過去問はどの順番で解くのがよいですか。', 'answer' => '苦手分野を把握してから、分野ごとに解くのがおすすめです。'],
            ],
        );

        $this->seedConversation(
            $student,
            title: '学習の進め方について',
            lastMessageAt: now()->subDays(20),
            enrollment: null,
            section: null,
            exchanges: [
                ['question' => '毎日どれくらい勉強すればよいですか。', 'answer' => '無理のない時間を毎日続けることが大切です。'],
            ],
        );

        $this->seedConversation(
            $student,
            title: '模擬試験の見直し方',
            lastMessageAt: now()->subDay(),
            enrollment: $enrollment,
            section: $section,
            exchanges: [],
            failedQuestion: '模擬試験の間違えた問題はどう見直せばよいですか。',
        );
    }

    /**
     * @param array<int, array{question: string, answer: string}> $exchanges
     */
    private function seedConversation(
        User $student,
        string $title,
        \DateTimeInterface $lastMessageAt,
        ?Enrollment $enrollment,
        ?Section $section,
        array $exchanges,
        ?string $failedQuestion = null,
    ): void {
        $conversation = AiChatConversation::query()->firstOrCreate(
            ['user_id' => $student->id, 'title' => $title],
            [
                'enrollment_id' => $enrollment?->id,
                'section_id' => $section?->id,
                'last_message_at' => $lastMessageAt,
            ],
        );

        if ($conversation->messages()->exists()) {
            return;
        }

        foreach ($exchanges as $exchange) {
            $this->addMessage($conversation, AiChatMessageRole::User, AiChatMessageStatus::Completed, $exchange['question']);
            $this->addMessage($conversation, AiChatMessageRole::Assistant, AiChatMessageStatus::Completed, $exchange['answer'], 'gemini-2.5-flash');
        }

        if ($failedQuestion !== null) {
            $this->addMessage($conversation, AiChatMessageRole::User, AiChatMessageStatus::Completed, $failedQuestion);
            $this->addMessage(
                $conversation,
                AiChatMessageRole::Assistant,
                AiChatMessageStatus::Error,
                '',
                errorDetail: 'Gemini API がエラーを返しました: HTTP 503',
            );
        }
    }

    private function addMessage(
        AiChatConversation $conversation,
        AiChatMessageRole $role,
        AiChatMessageStatus $status,
        string $content,
        ?string $model = null,
        ?string $errorDetail = null,
    ): AiChatMessage {
        return AiChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => $role->value,
            'status' => $status->value,
            'content' => $content,
            'error_detail' => $errorDetail,
            'model' => $model,
            'input_tokens' => $model !== null ? 120 : null,
            'output_tokens' => $model !== null ? 80 : null,
            'response_time_ms' => $model !== null ? 900 : null,
        ]);
    }
}
