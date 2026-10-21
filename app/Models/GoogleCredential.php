<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GoogleCredentialStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * コーチの Google アカウント連携情報を表す Model(1 ユーザー : 1 件)。
 *
 * トークンはシリアライズ時に出力しないよう $hidden で除外する。
 *
 * 関連: User(coach)
 */
class GoogleCredential extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'calendar_id',
        'status',
        'connected_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'status' => GoogleCredentialStatus::class,
        'connected_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
