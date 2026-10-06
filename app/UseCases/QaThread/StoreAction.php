<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\CertificationStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StoreAction
{
    /**
     * @param array{certification_id: string, title: string, body: string} $validated
     */
    public function __invoke(User $student, array $validated): QaThread
    {
        return DB::transaction(function () use ($student, $validated): QaThread {
            $certification = Certification::query()
                ->whereKey($validated['certification_id'])
                ->lockForUpdate()
                ->first();

            if ($certification === null || $certification->status !== CertificationStatus::Published) {
                throw ValidationException::withMessages([
                    'certification_id' => '選択した資格は選択できません。',
                ]);
            }

            return $certification->qaThreads()->create([
                'user_id' => $student->id,
                'title' => $validated['title'],
                'body' => $validated['body'],
            ]);
        });
    }
}
