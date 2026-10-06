<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Models\Certification;
use Illuminate\Database\Eloquent\Collection;

final class CreateAction
{
    /**
     * @return Collection<int, Certification>
     */
    public function __invoke(): Collection
    {
        return Certification::query()
            ->published()
            ->orderBy('name')
            ->get();
    }
}
