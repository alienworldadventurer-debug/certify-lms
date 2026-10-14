<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Models\User;
use App\Services\AvatarStorageService;
use Illuminate\Support\Facades\DB;

final class DeleteAvatarAction
{
    public function __construct(
        private readonly AvatarStorageService $storage,
    ) {}

    public function __invoke(User $user): User
    {
        DB::transaction(function () use ($user): void {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $path = $this->storage->pathFromUrl($lockedUser->avatar_url);
            $lockedUser->update(['avatar_url' => null]);

            if ($path !== null) {
                $this->storage->delete($path);
            }
        });

        return $user->refresh();
    }
}
