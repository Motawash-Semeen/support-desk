<?php

namespace App\Http\Middleware;

use App\Services\ErrorTracking\ErrorRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records 4xx responses in the admin error log, grouped by status code.
 * Laravel does not report these exceptions (404, 403, 419, 429…), so they
 * would never reach the log otherwise. 5xx are already recorded through the
 * logger; 409 (assignment conflict) and 422 (validation) are normal outcomes.
 * Runs after the response has been sent (terminate).
 *
 * The status in the error log is the final response status, so a 404 that
 * Laravel turned into a redirect (e.g. unauthenticated → login) is not counted.
 */
class TrackHttpErrors
{
    private const IGNORED = [409, 422];

    /** Files browsers and crawlers probe on their own: a 404 here is not an application error. */
    private const PROBED_PATHS = ['favicon.ico', 'robots.txt', 'apple-touch-icon*', '.well-known/*', 'sitemap.xml', 'browserconfig.xml'];

    public function __construct(private readonly ErrorRecorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The 500 page shows this request's error reference, never an earlier one.
        ErrorRecorder::forgetLastRecordedId();

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $status = $response->getStatusCode();

        if ($status < 400 || $status >= 500 || in_array($status, self::IGNORED, true) || ! config('support.error_tracker.track_http')) {
            return;
        }

        if ($status === 404 && $request->is(...self::PROBED_PATHS)) {
            return;
        }

        $this->recorder->recordHttp($status, $request);
    }
}
