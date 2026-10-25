<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI 相談の機能スイッチ（config/ai-chat.php の enabled）が OFF のとき、ルートを 404 にする。
 */
class EnsureAiChatEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('ai-chat.enabled', false), 404);

        return $next($request);
    }
}
