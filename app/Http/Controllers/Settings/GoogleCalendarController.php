<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Exceptions\GoogleCalendar\GoogleCalendarException;
use App\Http\Controllers\Controller;
use App\Http\Requests\GoogleCalendar\CallbackRequest;
use App\Http\Requests\GoogleCalendar\ConnectRequest;
use App\Models\GoogleCredential;
use App\Services\GoogleCalendarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GoogleCalendarController extends Controller
{
    private const DEFAULT_REDIRECT_PATH = '/settings/availability';

    private const FLASH_CONNECTED = 'Googleカレンダーと連携しました。';

    private const FLASH_FAILED = 'Googleカレンダーとの連携に失敗しました。';

    private const FLASH_DISCONNECTED = 'Googleカレンダーとの連携を解除しました。';

    public function redirect(ConnectRequest $request, GoogleCalendarService $googleCalendar): RedirectResponse
    {
        $user = $request->user();

        if (GoogleCredential::query()->where('user_id', $user->id)->exists()) {
            return redirect(self::DEFAULT_REDIRECT_PATH);
        }

        $state = Str::random(40);

        $request->session()->put(CallbackRequest::SESSION_STATE_KEY, $state);
        $request->session()->put(
            CallbackRequest::SESSION_REDIRECT_KEY,
            $request->safeRedirectPath() ?? self::DEFAULT_REDIRECT_PATH,
        );

        return redirect()->away($googleCalendar->authorizationUrl($state));
    }

    public function callback(CallbackRequest $request, GoogleCalendarService $googleCalendar): RedirectResponse
    {
        $redirectPath = $request->session()->pull(CallbackRequest::SESSION_REDIRECT_KEY, self::DEFAULT_REDIRECT_PATH);
        $request->session()->forget(CallbackRequest::SESSION_STATE_KEY);

        if ($request->filled('error')) {
            return redirect($redirectPath)->with('error', self::FLASH_FAILED);
        }

        try {
            $googleCalendar->connect($request->user(), (string) $request->input('code'));
        } catch (GoogleCalendarException) {
            return redirect($redirectPath)->with('error', self::FLASH_FAILED);
        }

        return redirect($redirectPath)->with('success', self::FLASH_CONNECTED);
    }

    public function destroy(Request $request, GoogleCalendarService $googleCalendar): RedirectResponse
    {
        $this->authorize('delete', GoogleCredential::class);

        $googleCalendar->disconnect($request->user());

        return redirect()->route('settings.availability.index')->with('success', self::FLASH_DISCONNECTED);
    }
}
