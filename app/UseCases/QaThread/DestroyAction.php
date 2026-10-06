<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\UserRole;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DestroyAction
{
    public function __invoke(User $user, QaThread $thread): void
    {
        DB::transaction(function () use ($user, $thread): void {
            $lockedThread = QaThread::query()
                ->whereKey($thread->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($user->role !== UserRole::Admin && $lockedThread->replies()->exists()) {
                throw new ConflictHttpException('回答が付いている質問は削除できません。');
            }

            $lockedThread->delete();
        });
    }
}
