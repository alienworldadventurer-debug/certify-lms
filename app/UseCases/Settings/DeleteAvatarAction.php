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
        $path = $this->storage->pathFromUrl($user->avatar_url);

        DB::transaction(function () use ($user, $path): void {
            $user->update(['avatar_url' => null]);

            if ($path !== null) {
                $this->storage->delete($path);
            }
        });

        return $user->refresh();
    }
}
