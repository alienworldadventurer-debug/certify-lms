<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureEnrollmentGoalStudentStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user?->role === UserRole::Student
            && $user->status !== UserStatus::InProgress
        ) {
            abort(403);
        }

        return $next($request);
    }
}
