<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\User;

/**
 * 追加面談購入(決済)に関する認可。
 */
class PaymentPolicy
{
    /**
     * 追加面談の購入開始。受講生のみ可。
     */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Student;
    }

    /**
     * 決済結果の閲覧。決済を行った本人のみ可。
     */
    public function view(User $user, Payment $payment): bool
    {
        return $user->id === $payment->user_id;
    }
}
