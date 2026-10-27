<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 追加面談パックの購入記録。Stripe Checkout Session 単位で 1 件作成し、通知で状態を遷移させる。
 *
 * quantity / amount は購入開始時の値を保持し、完了時に決済完了時点の面談回数で確定する。
 * パック名は複製せず meetingPack リレーションで参照する。
 *
 * 関連: User(購入者) / MeetingPack(購入パック) / MeetingQuotaTransaction(残数への加算・減算)
 */
class Payment extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'meeting_pack_id',
        'quantity',
        'amount',
        'status',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'paid_at',
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
        'quantity' => 'integer',
        'amount' => 'integer',
        'paid_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<MeetingPack, $this>
     */
    public function meetingPack(): BelongsTo
    {
        return $this->belongsTo(MeetingPack::class);
    }

    /**
     * @return HasMany<MeetingQuotaTransaction, $this>
     */
    public function quotaTransactions(): HasMany
    {
        return $this->hasMany(MeetingQuotaTransaction::class, 'related_payment_id');
    }
}
