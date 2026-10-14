<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Exceptions\Settings\AvatarUploadFailedException;
use App\Models\User;
use App\Services\AvatarStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class StoreAvatarAction
{
    public function __construct(
        private readonly AvatarStorageService $storage,
    ) {}

    public function __invoke(User $user, UploadedFile $avatar): User
    {
        $newUrl = null;

        try {
            $oldUrl = DB::transaction(function () use ($user, $avatar, &$newUrl): ?string {
                $lockedUser = User::query()
                    ->whereKey($user->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $oldUrl = $lockedUser->avatar_url;
                $newUrl = $this->storage->store($avatar);

                $lockedUser->update(['avatar_url' => $newUrl]);

                return $oldUrl;
            });
        } catch (Throwable $exception) {
            $newPath = $this->storage->pathFromUrl($newUrl);

            if ($newPath !== null) {
                try {
                    $this->storage->delete($newPath);
                } catch (Throwable $cleanupException) {
                    Log::warning('Failed to remove an avatar file after profile update failed.', [
                        'user_id' => $user->getKey(),
                        'path' => $newPath,
                        'exception' => $cleanupException,
                    ]);
                }
            }

            throw new AvatarUploadFailedException($exception);
        }

        $oldPath = $this->storage->pathFromUrl($oldUrl);
        if ($oldPath !== null) {
            try {
                $this->storage->delete($oldPath);
            } catch (Throwable $exception) {
                Log::warning('Failed to remove the previous avatar after replacement.', [
                    'user_id' => $user->getKey(),
                    'path' => $oldPath,
                    'exception' => $exception,
                ]);
            }
        }

        return $user->refresh();
    }
}
