<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 掲示板の開発用サンプル質問と、受講生・コーチの回答を投入する。
 *
 * 依存順序: UserSeeder → CertificationSeeder(コーチ割当を含む) → 本 Seeder。
 */
class QaBoardSeeder extends Seeder
{
    public function run(): void
    {
        $certifications = Certification::query()
            ->whereIn('name', ['基本情報技術者試験', '応用情報技術者試験'])
            ->where('status', 'published')
            ->get()
            ->keyBy('name');

        $students = User::query()
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::InProgress->value)
            ->whereNotIn('email', ['student-noquota@certify-lms.test'])
            ->orderBy('email')
            ->orderBy('id')
            ->limit(4)
            ->get()
            ->values();

        if (
            ! $certifications->has('基本情報技術者試験')
            || ! $certifications->has('応用情報技術者試験')
            || $students->count() < 3
        ) {
            $this->command?->warn(
                'QaBoardSeeder: 公開中の基本情報・応用情報資格と受講中の受講生3名が必要です。先にUserSeederとCertificationSeederを実行してください。',
            );

            return;
        }

        $basicInformation = $certifications->get('基本情報技術者試験');
        $appliedInformation = $certifications->get('応用情報技術者試験');
        $basicCoach = $basicInformation->coaches()
            ->where('users.status', UserStatus::InProgress->value)
            ->orderBy('users.email')
            ->first();
        $appliedCoach = $appliedInformation->coaches()
            ->where('users.status', UserStatus::InProgress->value)
            ->orderBy('users.email')
            ->first();

        if ($basicCoach === null || $appliedCoach === null) {
            $this->command?->warn(
                'QaBoardSeeder: 基本情報・応用情報資格に受講中の担当コーチが必要です。先にCertificationSeederを実行してください。',
            );

            return;
        }

        $questions = [
            [
                'certification' => $basicInformation,
                'student' => $students[0],
                'title' => '負数を2の補数で表すとき、桁数をそろえる考え方が分かりません',
                'body' => "8ビットで表した負数を16ビットに拡張するとき、単純に左側を0で埋めてはいけない理由が分かりません。\n符号拡張のルールと、計算例を教えてください。",
                'status' => QaThreadStatus::Open,
                'replies' => [
                    ['user' => $basicCoach, 'body' => '負数は最上位ビットが1なので、桁を広げるときは左側を1で埋めます。たとえば8ビットの `11111101`（-3）は、16ビットでは `1111111111111101` です。'],
                    ['user' => $students[1], 'body' => '0で埋めると正の数として解釈されてしまうのですね。符号ビットを左にコピーすると覚えてみます。'],
                    ['user' => $basicCoach, 'body' => 'その理解で大丈夫です。元の値と拡張後の値を10進数に戻して同じになるか確認すると、符号拡張の意味を確かめられます。'],
                    ['user' => $students[2], 'body' => '私も最初は0埋めして混乱しました。負数の例を使って、拡張前後の値を見比べると覚えやすかったです。'],
                ],
            ],
            [
                'certification' => $basicInformation,
                'student' => $students[1],
                'title' => '2分探索の比較回数が log₂ n になるイメージをつかみたいです',
                'body' => "データ数が2倍になると比較回数が1回増える、と解説にありますが、なぜ対数になるのでしょうか。\n要素数8個と16個の場合で説明していただけると助かります。",
                'status' => QaThreadStatus::Open,
                'replies' => [
                    ['user' => $students[2], 'body' => '中央の要素と比較して探索区間を絞る標準的な2分探索では、8個なら最大4回、16個なら最大5回比較します。候補が1個になった後も、その要素との最後の比較が必要です。'],
                    ['user' => $basicCoach, 'body' => '補足すると、要素数 n が1以上の場合、最悪時の比較回数は floor(log₂ n) + 1 回です。比較回数の増え方は O(log n) で、要素数が2倍になると最悪時の比較回数が1回増えます。'],
                    ['user' => $students[0], 'body' => '探索区間を絞る回数と、最後の1個を比較する回数を分けて考えると理解できました。対数は比較回数の増え方を表しているのですね。'],
                ],
            ],
            [
                'certification' => $appliedInformation,
                'student' => $students[2],
                'title' => 'トランザクション分離レベルの使い分けを整理したいです',
                'body' => "READ COMMITTED と REPEATABLE READ の違いが、問題を解くときに混同してしまいます。\n同じトランザクション内で再読込した場合に何が見えるのか、具体例で教えてください。",
                'status' => QaThreadStatus::Resolved,
                'replies' => [
                    ['user' => $appliedCoach, 'body' => 'READ COMMITTED は、文を実行するたびにコミット済みの最新データを読みます。そのため同じSELECTを再実行すると結果が変わる（反復不能読取り）場合があります。'],
                    ['user' => $students[0], 'body' => 'READ COMMITTED では「読取りのたびに最新」と考えるのですね。'],
                    ['user' => $appliedCoach, 'body' => 'はい。REPEATABLE READ は、同じトランザクション内の再読込みで同じ行の値を保つことを重視します。試験では「同一トランザクション内で同じ行を再読込み」を手掛かりにしてください。'],
                    ['user' => $students[1], 'body' => 'ファントムリードとの違いも含めて表にしたら整理できました。説明ありがとうございます。'],
                    ['user' => $appliedCoach, 'body' => '整理できてよかったです。分離レベルごとに「発生しうる現象」を対応させておくと、選択肢を比較しやすくなります。'],
                ],
            ],
        ];

        foreach ($questions as $questionData) {
            $thread = QaThread::query()->firstOrCreate(
                [
                    'certification_id' => $questionData['certification']->id,
                    'user_id' => $questionData['student']->id,
                    'title' => $questionData['title'],
                ],
                [
                    'body' => $questionData['body'],
                    'status' => $questionData['status'],
                    'resolved_at' => $questionData['status'] === QaThreadStatus::Resolved ? now()->subHours(2) : null,
                ],
            );

            foreach ($questionData['replies'] as $index => $replyData) {
                $thread->replies()->firstOrCreate(
                    [
                        'user_id' => $replyData['user']->id,
                        'body' => $replyData['body'],
                    ],
                    [
                        'created_at' => now()->subMinutes(count($questionData['replies']) - $index),
                    ],
                );
            }
        }
    }
}
