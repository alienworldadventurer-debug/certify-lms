<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class UpdatePasswordAction
{
    /**
     * @param array{password: string} $validated
     */
    public function __invoke(User $user, array $validated): void
    {
        DB::transaction(function () use ($user, $validated): void {
            $user->update(['password' => $validated['password']]);
        });

        Auth::logoutOtherDevices($validated['password']);
    }
}
