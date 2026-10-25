<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read string $id
 */
class AiChatDailyUsage extends Model
{
    use HasFactory, HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'usage_date',
        'count',
    ];

    protected $casts = [
        'usage_date' => 'date',
        'count' => 'integer',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
