<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read string $id
 */
class AiChatMessage extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'conversation_id',
        'role',
        'status',
        'content',
        'error_detail',
        'model',
        'input_tokens',
        'output_tokens',
        'response_time_ms',
    ];

    protected $casts = [
        'role' => AiChatMessageRole::class,
        'status' => AiChatMessageStatus::class,
    ];

    /**
     * @return BelongsTo<AiChatConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiChatConversation::class, 'conversation_id');
    }
}
