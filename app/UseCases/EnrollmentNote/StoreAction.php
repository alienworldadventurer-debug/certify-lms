<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;

final class StoreAction
{
    /**
     * @param array{body: string} $validated
     */
    public function __invoke(Enrollment $enrollment, array $validated, string $authorId): EnrollmentNote
    {
        return $enrollment->notes()->create([
            'body' => $validated['body'],
            'author_id' => $authorId,
        ]);
    }
}
