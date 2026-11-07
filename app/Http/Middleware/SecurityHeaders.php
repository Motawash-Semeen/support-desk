<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every web response.
 *
 * CSP: scripts only from our origin, plus inline scripts carrying the
 * per-request nonce (Vite::useCspNonce adds it to @vite tags; the two inline
 * Alpine helpers use Vite::cspNonce()). 'unsafe-eval' is required by the
 * standard Alpine.js build, which evaluates x-* expressions with Function().
 * Styles allow 'unsafe-inline' for Alpine's x-show/x-cloak style toggling.
 * Fonts are self-hosted (bundled by Vite), so no third-party origin is
 * allowed. While `npm run dev` is running, the Vite dev server origin is too.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        // A response with its own, stricter policy keeps it (attachment downloads
        // send "default-src 'none'; sandbox").
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->policy($nonce));
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        $dev = $this->viteDevServer();

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'".$dev,
            "style-src 'self' 'unsafe-inline'".$dev,
            "font-src 'self' data:".$dev,
            "img-src 'self' data:",
            "connect-src 'self'".($dev === '' ? '' : $dev.' '.str_replace('http', 'ws', trim($dev))),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /** " http://localhost:5173" while the Vite dev server runs (public/hot), else "". */
    private function viteDevServer(): string
    {
        $hot = public_path('hot');

        return is_file($hot) ? ' '.rtrim(trim((string) file_get_contents($hot)), '/') : '';
    }
}
