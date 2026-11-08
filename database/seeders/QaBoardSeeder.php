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
            || ! $certifications->has('TOEIC L&R 800 点コース')
            || ! $certifications->has('日商簿記 2 級')
            || ! $certifications->has('PMP')
            || $students->count() < 3
        ) {
            $this->command?->warn(
                'QaBoardSeeder: 公開中の5資格(基本情報・応用情報・TOEIC・日商簿記2級・PMP)と受講中の受講生3名が必要です。先にUserSeederとCertificationSeederを実行してください。',
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

        $coachOf = fn (Certification $certification): ?User => $certification->coaches()
            ->where('users.status', UserStatus::InProgress->value)
            ->orderBy('users.email')
            ->first();

        $toeicInformation = $certifications->get('TOEIC L&R 800 点コース');
        $bookkeepingInformation = $certifications->get('日商簿記 2 級');
        $pmpInformation = $certifications->get('PMP');
        $toeicCoach = $coachOf($toeicInformation);
        $bookkeepingCoach = $coachOf($bookkeepingInformation);
        $pmpCoach = $coachOf($pmpInformation);

        if ($toeicCoach === null || $bookkeepingCoach === null || $pmpCoach === null) {
            $this->command?->warn(
                'QaBoardSeeder: TOEIC・日商簿記・PMP資格に受講中の担当コーチが必要です。先にCertificationSeederを実行してください。',
            );

            return;
        }

        $fixedStudent = User::query()
            ->where('email', 'student@certify-lms.test')
            ->first() ?? $students[0];

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
                'student' => $fixedStudent,
                'title' => '2分探索の比較回数が log₂ n になるイメージをつかみたいです',
                'body' => "データ数が2倍になると比較回数が1回増える、と解説にありますが、なぜ対数になるのでしょうか。\n要素数8個と16個の場合で説明していただけると助かります。",
                'status' => QaThreadStatus::Resolved,
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
            [
                'certification' => $basicInformation,
                'student' => $fixedStudent,
                'title' => 'ハフマン符号の作り方で、同じ頻度の文字が並んだ場合の扱いは？',
                'body' => "出現頻度が同じ文字が複数あるとき、木の組み立て順序はどちらを先にすればよいのでしょうか。\n最終的な符号長が変わるのか知りたいです。",
                'status' => QaThreadStatus::Open,
                'replies' => [],
                'days_ago' => 0,
            ],
            [
                'certification' => $appliedInformation,
                'student' => $students[1],
                'title' => 'ER図の多対多関係を中間テーブルにする理由',
                'body' => "多対多の関係を中間テーブルに分解する理由を、試験の問題文から読み取る方法が分かりません。",
                'status' => QaThreadStatus::Open,
                'replies' => [
                    ['user' => $appliedCoach, 'body' => '1対多に分解できない（両側とも複数のレコードを参照する）ため、関連を表す中間テーブルを置きます。問題文に「複数の」「それぞれ」といった語があれば手掛かりです。'],
                ],
                'days_ago' => 3,
            ],
            [
                'certification' => $toeicInformation,
                'student' => $students[0],
                'title' => 'Part 5 の語彙問題で、品詞の見分け方を知りたいです',
                'body' => "空所の前後の語から品詞を判断する練習をしていますが、時間内に絞り込めません。\nコツがあれば教えてください。",
                'status' => QaThreadStatus::Open,
                'replies' => [],
                'days_ago' => 1,
            ],
            [
                'certification' => $toeicInformation,
                'student' => $students[1],
                'title' => 'Part 7 は設問を先に読むべきか、本文を先に読むべきか迷っています',
                'body' => "設問を先に読むと本文を探しやすい気がしますが、長文だと内容が頭に残りません。\n時間配分の目安も含めて教えてください。",
                'status' => QaThreadStatus::Open,
                'replies' => [
                    ['user' => $toeicCoach, 'body' => '特定の情報を探す設問は先に読むと時間を短縮できます。全体の内容を問う設問は、本文を一通り読んでから解く方が正確です。'],
                ],
                'days_ago' => 4,
            ],
            [
                'certification' => $toeicInformation,
                'student' => $students[2],
                'title' => 'リスニングで数字が聞き取れません',
                'body' => "時刻や金額の数字が早口になると取りこぼします。\n聞き取りのコツがあれば知りたいです。",
                'status' => QaThreadStatus::Resolved,
                'replies' => [
                    ['user' => $students[0], 'body' => '時刻・金額・数量のように、聞き取る数字の種類を先に決めておくと集中しやすくなりました。'],
                    ['user' => $toeicCoach, 'body' => '読み上げ前に設問と選択肢を確認しておくと、聞き取る対象が絞れます。'],
                    ['user' => $students[1], 'body' => '選択肢を先に読む練習をしてから、正答率が上がりました。'],
                ],
                'days_ago' => 10,
            ],
            [
                'certification' => $bookkeepingInformation,
                'student' => $students[0],
                'title' => '仕訳で借方と貸方のどちらに記入するか迷います',
                'body' => "売掛金や買掛金の増減が出てくると、どちらの側に書くのか混乱します。\n覚え方を教えてください。",
                'status' => QaThreadStatus::Open,
                'replies' => [],
                'days_ago' => 2,
            ],
            [
                'certification' => $bookkeepingInformation,
                'student' => $students[2],
                'title' => '減価償却の定額法と定率法の計算の違いを整理したいです',
                'body' => "同じ取得原価でも、毎期の償却額が変わる理由が分かりません。\n計算例で教えてください。",
                'status' => QaThreadStatus::Open,
                'replies' => [
                    ['user' => $bookkeepingCoach, 'body' => '定額法は取得原価に対して毎期同額を償却します。定率法は未償却残高に一定率を掛けるため、初期の償却額が大きくなります。'],
                ],
                'days_ago' => 3,
            ],
            [
                'certification' => $bookkeepingInformation,
                'student' => $students[1],
                'title' => '決算の手続きの順番を教えてください',
                'body' => "決算整理仕訳から繰越試算表までの流れを、試験でどう使うのか知りたいです。",
                'status' => QaThreadStatus::Resolved,
                'replies' => [
                    ['user' => $bookkeepingCoach, 'body' => '決算整理仕訳、帳簿の締め切り、繰越試算表の作成の順に進めます。'],
                    ['user' => $students[2], 'body' => '順番を表にまとめて覚えると整理しやすいです。'],
                ],
                'days_ago' => 12,
            ],
            [
                'certification' => $pmpInformation,
                'student' => $students[1],
                'title' => 'ステークホルダー分析はどのタイミングで行うのでしょうか',
                'body' => "プロジェクト計画の中で行うのか、実行中に行うのか迷っています。",
                'status' => QaThreadStatus::Open,
                'replies' => [],
                'days_ago' => 1,
            ],
            [
                'certification' => $pmpInformation,
                'student' => $students[0],
                'title' => 'アーンドバリュー(EV)の計算式を忘れてしまいました',
                'body' => "EV の求め方と、PV や AC との関係を整理したいです。",
                'status' => QaThreadStatus::Open,
                'replies' => [
                    ['user' => $pmpCoach, 'body' => 'EV は完成した作業の計画価値です。EV = BAC × 進捗率で求められます。'],
                ],
                'days_ago' => 2,
            ],
            [
                'certification' => $pmpInformation,
                'student' => $students[2],
                'title' => 'スプリントレビューとレトロスペクティブの違いが分かりません',
                'body' => "どちらも振り返りのイベントに見えて、目的の違いが説明できません。",
                'status' => QaThreadStatus::Resolved,
                'replies' => [
                    ['user' => $pmpCoach, 'body' => 'レビューは成果物をステークホルダーと確認する場、レトロスペクティブはチームの進め方を振り返る場です。'],
                    ['user' => $students[0], 'body' => '確認対象が成果物か、進め方かで覚えると整理しやすいです。'],
                ],
                'days_ago' => 9,
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
                    'body' => $questionData['body'],
                    'status' => $questionData['status'],
                    'resolved_at' => $questionData['status'] === QaThreadStatus::Resolved ? now()->subHours(2) : null,
                ],
            );

            if ($thread->wasRecentlyCreated) {
                QaThread::query()->whereKey($thread->id)->update([
                    'created_at' => now()->subDays($questionData['days_ago'] ?? 7),
                ]);
            }

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
