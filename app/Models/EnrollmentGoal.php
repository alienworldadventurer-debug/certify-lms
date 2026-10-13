<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentGoal extends Model
{
    use HasUlids;

    protected $fillable = [
        'enrollment_id',
        'title',
        'description',
        'target_date',
        'achieved_at',
    ];

    protected $casts = [
        'target_date' => 'date',
        'achieved_at' => 'datetime',
    ];

    /**
     * 未達成を先頭、目標期日が近い順(期日なしは末尾)、同条件は新しい作成順。
     *
     * @param Builder<EnrollmentGoal> $query
     */
    public function scopeDisplayOrder(Builder $query): void
    {
        $query->orderByRaw('achieved_at IS NOT NULL')
            ->orderByRaw('target_date IS NULL')
            ->orderBy('target_date')
            ->orderByDesc('created_at');
    }

    public function isAchieved(): bool
    {
        return $this->achieved_at !== null;
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
