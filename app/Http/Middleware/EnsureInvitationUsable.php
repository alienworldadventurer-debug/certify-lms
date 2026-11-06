<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Invitation;
use App\Services\InvitationTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInvitationUsable
{
    public function __construct(
        private readonly InvitationTokenService $tokenService,
    ) {}

    /**
     * Ensure invalid invitations are rejected before onboarding form validation runs.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $invitation = $request->route('invitation');

        if (! $invitation instanceof Invitation || ! $this->tokenService->verify($request, $invitation)) {
            return response()->view('auth.invitation-invalid', [], Response::HTTP_GONE);
        }

        return $next($request);
    }
}
