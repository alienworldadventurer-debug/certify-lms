<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureQaBoardAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user === null, 403);

        if ($request->routeIs('admin.qa-board.*')) {
            abort_unless($user->role === UserRole::Admin, 403);
        } else {
            abort_unless(
                in_array($user->role, [UserRole::Student, UserRole::Coach], true)
                && $user->status === UserStatus::InProgress,
                403,
            );
        }

        return $next($request);
    }
}
