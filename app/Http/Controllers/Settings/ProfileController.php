<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Exceptions\Settings\AvatarDeleteFailedException;
use App\Exceptions\Settings\AvatarUploadFailedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DeleteAvatarRequest;
use App\Http\Requests\Settings\StoreAvatarRequest;
use App\Http\Requests\Settings\UpdatePasswordRequest;
use App\Http\Requests\Settings\UpdateProfileRequest;
use App\UseCases\Settings\DeleteAvatarAction;
use App\UseCases\Settings\StoreAvatarAction;
use App\UseCases\Settings\UpdatePasswordAction;
use App\UseCases\Settings\UpdateProfileAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user();
        $this->authorize('viewSettings', $user);

        return view('settings.profile', ['user' => $user]);
    }

    public function update(
        UpdateProfileRequest $request,
        UpdateProfileAction $action,
    ): RedirectResponse {
        $action($request->user(), $request->validated());

        return redirect()
            ->route('settings.profile.edit', ['tab' => 'profile'])
            ->with('success', 'プロフィールを更新しました。');
    }

    public function storeAvatar(
        StoreAvatarRequest $request,
        StoreAvatarAction $action,
    ): RedirectResponse {
        try {
            $action($request->user(), $request->file('avatar'));
        } catch (AvatarUploadFailedException $exception) {
            return redirect()
                ->route('settings.profile.edit', ['tab' => 'profile'])
                ->withErrors(['avatar' => $exception->getMessage()]);
        }

        return redirect()
            ->route('settings.profile.edit', ['tab' => 'profile'])
            ->with('success', 'アバター画像を更新しました。');
    }

    public function destroyAvatar(
        DeleteAvatarRequest $request,
        DeleteAvatarAction $action,
    ): RedirectResponse {
        try {
            $action($request->user());
        } catch (AvatarDeleteFailedException $exception) {
            return redirect()
                ->route('settings.profile.edit', ['tab' => 'profile'])
                ->withErrors(['avatar' => $exception->getMessage()]);
        }

        return redirect()
            ->route('settings.profile.edit', ['tab' => 'profile'])
            ->with('success', 'アバター画像を削除しました。');
    }

    public function updatePassword(
        UpdatePasswordRequest $request,
        UpdatePasswordAction $action,
    ): RedirectResponse {
        $action($request->user(), $request->validated());

        return redirect()
            ->route('settings.profile.edit', ['tab' => 'password'])
            ->with('success', 'パスワードを変更しました。');
    }
}
