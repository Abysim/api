<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyApiToken
{
    /**
     * Guard internal API endpoints with a shared bearer token.
     *
     * The token lives in `.env` (TQA_API_TOKEN) on bigcats and is sent by the
     * translation-qa haiku agents as `Authorization: Bearer <token>`. Fail-closed
     * (rejects when the server token is unset) and uses a timing-safe compare so
     * the token can't be recovered byte-by-byte from response timing.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.tqa.token');
        $provided = $request->bearerToken();

        if (!is_string($expected) || $expected === ''
            || !is_string($provided)
            || !hash_equals($expected, $provided)
        ) {
            Log::warning('translation-qa auth rejected: ' . json_encode([
                'ip' => $request->ip(),
                'ua' => $request->userAgent(),
                'path' => $request->path(),
            ], JSON_UNESCAPED_UNICODE));

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
