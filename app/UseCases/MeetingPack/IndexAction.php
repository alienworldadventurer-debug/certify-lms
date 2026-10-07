<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class IndexAction
{
    public function __invoke(?string $keyword, ?MeetingPackStatus $status): LengthAwarePaginator
    {
        return MeetingPack::query()
            ->when($keyword !== null, fn ($query) => $query->where('name', 'like', '%'.$keyword.'%'))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [MeetingPackStatus::Published->value])
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }
}
