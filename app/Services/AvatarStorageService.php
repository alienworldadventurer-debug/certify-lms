<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\Settings\AvatarDeleteFailedException;
use App\Exceptions\Settings\AvatarUploadFailedException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class AvatarStorageService
{
    public function store(UploadedFile $file): string
    {
        $disk = Storage::disk('public');
        $extension = strtolower($file->guessExtension() ?: 'bin');
        $path = 'avatars/'.Str::ulid().'.'.$extension;

        try {
            $storedPath = $disk->putFileAs('avatars', $file, basename($path));
        } catch (Throwable $exception) {
            throw new AvatarUploadFailedException($exception);
        }

        if ($storedPath === false) {
            throw new AvatarUploadFailedException;
        }

        return $disk->url($path);
    }

    public function pathFromUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $disk = Storage::disk('public');
        $baseUrl = rtrim($disk->url('avatars'), '/').'/';

        if (! Str::startsWith($url, $baseUrl)) {
            return null;
        }

        $path = substr($url, strlen($baseUrl));

        if ($path === '' || str_contains($path, '/')) {
            return null;
        }

        return 'avatars/'.$path;
    }

    public function delete(string $path): void
    {
        try {
            $deleted = Storage::disk('public')->delete($path);
        } catch (Throwable $exception) {
            throw new AvatarDeleteFailedException($exception);
        }

        if (! $deleted) {
            throw new AvatarDeleteFailedException;
        }
    }
}
