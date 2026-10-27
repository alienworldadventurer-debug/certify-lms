<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MeetingPackStatus;
use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * 追加面談購入(Payment)の初期データシーダー。
 *
 * **設計思想(Seeder 業界標準: 状態網羅 + 固定受講生 + 残数反映確認)**:
 *
 * 1. **状態網羅**:
 *    固定受講生(`student@certify-lms.test`)に対し、全ステータス
 *    (succeeded / pending / failed / refunded)の購入記録を投入し、
 *    履歴画面のステータスバッジや詳細表示を確認できるようにする。
 *
 * 2. **残数整合性**:
 *    - succeeded 分のみ `MeetingQuotaTransaction` (Purchased) を起票し、面談残数に純増反映する。
 *    - pending / failed は取引レコードを作らず、残数に影響を与えない。
 *    - refunded は購入時(Purchased)と返金時(Refunded, 負の値)の両方を起票し、残数が相殺される。
 *
 * 3. **面談残数なし受講生(`student-noquota@certify-lms.test`)への投入**:
 *    追加購入の検証用として pending レコード等を投入。
 */
final class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        $publishedPacks = MeetingPack::query()
            ->where('status', MeetingPackStatus::Published)
            ->orderBy('sort_order')
            ->get();

        if ($publishedPacks->isEmpty()) {
            $this->command?->warn('PaymentSeeder: 公開中の MeetingPack が存在しません。先に MeetingPackSeeder を実行してください。');

            return;
        }

        $pack1 = $publishedPacks->firstWhere('meeting_count', 1) ?? $publishedPacks->first();
        $pack5 = $publishedPacks->firstWhere('meeting_count', 5) ?? $publishedPacks->first();
        $pack10 = $publishedPacks->firstWhere('meeting_count', 10) ?? $publishedPacks->last();

        $this->seedFixedStudentPayments($pack1, $pack5, $pack10);
        $this->seedNoQuotaStudentPayments($pack1);
        $this->seedDemoStudentPayments($pack5);
    }

    /**
     * 固定受講生(student@certify-lms.test)向けに全状態(4種)の購入記録を投入。
     */
    private function seedFixedStudentPayments(MeetingPack $pack1, MeetingPack $pack5, MeetingPack $pack10): void
    {
        $student = User::query()->where('email', 'student@certify-lms.test')->first();
        if ($student === null) {
            return;
        }

        // 1. 完了(Succeeded): 5回パックを購入(残数 +5)
        $paidAt = now()->subDays(5);
        $paymentSucceeded = Payment::create([
            'id' => (string) Str::ulid(),
            'user_id' => $student->id,
            'meeting_pack_id' => $pack5->id,
            'quantity' => $pack5->meeting_count,
            'amount' => $pack5->price,
            'status' => PaymentStatus::Succeeded,
            'stripe_checkout_session_id' => 'cs_test_seed_succeeded_'.Str::random(10),
            'stripe_payment_intent_id' => 'pi_test_seed_succeeded_'.Str::random(10),
            'paid_at' => $paidAt,
            'created_at' => $paidAt->copy()->subMinutes(3),
            'updated_at' => $paidAt,
        ]);

        MeetingQuotaTransaction::create([
            'id' => (string) Str::ulid(),
            'user_id' => $student->id,
            'type' => MeetingQuotaTransactionType::Purchased,
            'amount' => $pack5->meeting_count,
            'related_payment_id' => $paymentSucceeded->id,
            'occurred_at' => $paidAt,
        ]);

        // 2. 処理中(Pending): 10回パックの決済開始中(残数変化なし)
        Payment::create([
            'id' => (string) Str::ulid(),
            'user_id' => $student->id,
            'meeting_pack_id' => $pack10->id,
            'quantity' => $pack10->meeting_count,
            'amount' => $pack10->price,
            'status' => PaymentStatus::Pending,
            'stripe_checkout_session_id' => 'cs_test_seed_pending_'.Str::random(10),
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        // 3. 失敗(Failed): 1回パックの決済失敗(残数変化なし)
        Payment::create([
            'id' => (string) Str::ulid(),
            'user_id' => $student->id,
            'meeting_pack_id' => $pack1->id,
            'quantity' => $pack1->meeting_count,
            'amount' => $pack1->price,
            'status' => PaymentStatus::Failed,
            'stripe_checkout_session_id' => 'cs_test_seed_failed_'.Str::random(10),
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        // 4. 返金(Refunded): 過去に1回パックを購入したが全額返金(残数 +1 - 1 = 0)
        $refundPurchasedAt = now()->subDays(15);
        $refundedAt = now()->subDays(14);
        $paymentRefunded = Payment::create([
            'id' => (string) Str::ulid(),
            'user_id' => $student->id,
            'meeting_pack_id' => $pack1->id,
            'quantity' => $pack1->meeting_count,
            'amount' => $pack1->price,
            'status' => PaymentStatus::Refunded,
            'stripe_checkout_session_id' => 'cs_test_seed_refunded_'.Str::random(10),
            'stripe_payment_intent_id' => 'pi_test_seed_refunded_'.Str::random(10),
            'paid_at' => $refundPurchasedAt,
            'created_at' => $refundPurchasedAt->copy()->subMinutes(2),
            'updated_at' => $refundedAt,
        ]);

        MeetingQuotaTransaction::create([
            'id' => (string) Str::ulid(),
            'user_id' => $student->id,
            'type' => MeetingQuotaTransactionType::Purchased,
            'amount' => $pack1->meeting_count,
            'related_payment_id' => $paymentRefunded->id,
            'occurred_at' => $refundPurchasedAt,
        ]);

        MeetingQuotaTransaction::create([
            'id' => (string) Str::ulid(),
            'user_id' => $student->id,
            'type' => MeetingQuotaTransactionType::Refunded,
            'amount' => -$pack1->meeting_count,
            'related_payment_id' => $paymentRefunded->id,
            'occurred_at' => $refundedAt,
        ]);
    }

    /**
     * 面談残数なし受講生(student-noquota@certify-lms.test)向けに決済試行データを投入。
     */
    private function seedNoQuotaStudentPayments(MeetingPack $pack1): void
    {
        $student = User::query()->where('email', 'student-noquota@certify-lms.test')->first();
        if ($student === null) {
            return;
        }

        Payment::create([
            'id' => (string) Str::ulid(),
            'user_id' => $student->id,
            'meeting_pack_id' => $pack1->id,
            'quantity' => $pack1->meeting_count,
            'amount' => $pack1->price,
            'status' => PaymentStatus::Pending,
            'stripe_checkout_session_id' => 'cs_test_seed_noquota_'.Str::random(10),
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);
    }

    /**
     * デモ受講生向けに購入完了データを投入。
     */
    private function seedDemoStudentPayments(MeetingPack $pack5): void
    {
        $demoStudents = User::factory()->student()->inProgress()->count(2)->create();

        foreach ($demoStudents as $student) {
            $paidAt = now()->subDays(fake()->numberBetween(1, 7));
            $payment = Payment::create([
                'id' => (string) Str::ulid(),
                'user_id' => $student->id,
                'meeting_pack_id' => $pack5->id,
                'quantity' => $pack5->meeting_count,
                'amount' => $pack5->price,
                'status' => PaymentStatus::Succeeded,
                'stripe_checkout_session_id' => 'cs_test_seed_demo_'.Str::random(10),
                'stripe_payment_intent_id' => 'pi_test_seed_demo_'.Str::random(10),
                'paid_at' => $paidAt,
                'created_at' => $paidAt->copy()->subMinutes(5),
                'updated_at' => $paidAt,
            ]);

            MeetingQuotaTransaction::create([
                'id' => (string) Str::ulid(),
                'user_id' => $student->id,
                'type' => MeetingQuotaTransactionType::Purchased,
                'amount' => $pack5->meeting_count,
                'related_payment_id' => $payment->id,
                'occurred_at' => $paidAt,
            ]);
        }
    }
}
