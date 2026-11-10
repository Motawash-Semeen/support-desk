<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every web response. CSP allows scripts from our origin and
 * inline scripts with the per-request nonce; 'unsafe-eval' is needed by Alpine and
 * 'unsafe-inline' styles by x-show/x-cloak. Fonts are self-hosted. The Vite dev
 * server is allowed while `npm run dev` runs.
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
        // A response with its own stricter policy (attachment downloads) keeps it.
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

    /** The Vite dev server origin while it runs, otherwise "". */
    private function viteDevServer(): string
    {
        $hot = public_path('hot');

        return is_file($hot) ? ' '.rtrim(trim((string) file_get_contents($hot)), '/') : '';
    }
}
