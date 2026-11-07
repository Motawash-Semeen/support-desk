<?php

namespace App\Modules\Logs\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Stores errors for the admin error log (/admin/errors).
 *
 * - Grouped: one row per fingerprint (exception class + file + line, log
 *   message, or HTTP status), with an occurrence counter and the details of
 *   the latest occurrence. A recurring resolved error is reopened.
 * - Isolated: writes go through their own database connection, so an error
 *   raised inside a transaction that then rolls back is still kept.
 * - Safe: never throws and never logs (it is called from the logger itself);
 *   context values whose key looks secret are redacted; sizes are capped.
 */
class ErrorRecorder
{
    private const CONNECTION = 'error_tracker';

    private const SECRET_KEYS = '/pass|secret|token|authori[sz]ation|cookie|api[_-]?key|session|credit|card|cvv/i';

    /** Re-entrancy guard: an error while recording is not recorded again. */
    private static bool $recording = false;

    /** Id of the last error recorded in this request/job (shown on the 500 page). */
    private static ?int $lastId = null;

    public static function lastRecordedId(): ?int
    {
        return self::$lastId;
    }

    public static function forgetLastRecordedId(): void
    {
        self::$lastId = null;
    }

    public function recordException(Throwable $e, string $level = 'error', array $context = []): void
    {
        $file = $this->relativePath($e->getFile());

        $this->persist([
            'fingerprint' => sha1('exception|'.$e::class.'|'.$file.'|'.$e->getLine()),
            'level' => $level,
            'source' => 'exception',
            'message' => $e->getMessage() !== '' ? $e->getMessage() : class_basename($e),
            'exception_class' => $e::class,
            'file' => $file,
            'line' => $e->getLine(),
            'trace' => $this->trace($e),
            'context' => $context,
        ]);
    }

    public function recordLog(string $level, string $message, array $context = []): void
    {
        $this->persist([
            'fingerprint' => sha1('log|'.$level.'|'.$message),
            'level' => $level,
            'source' => 'log',
            'message' => $message,
            'context' => $context,
        ]);
    }

    /** 4xx responses, grouped by status code (one row per status, latest URL kept). */
    public function recordHttp(int $status, Request $request): void
    {
        $this->persist([
            'fingerprint' => sha1('http|'.$status),
            'level' => in_array($status, [401, 403, 419, 429], true) ? 'warning' : 'notice',
            'source' => 'http',
            'message' => 'HTTP '.$status.' '.(Response::$statusTexts[$status] ?? 'Error'),
            'status_code' => $status,
            'context' => array_filter(['route' => $request->route()?->getName(), 'referer' => $request->headers->get('referer')]),
        ], $request);
    }

    /** @param array<string, mixed> $error */
    private function persist(array $error, ?Request $request = null): void
    {
        if (self::$recording || ! config('support.error_tracker.enabled')) {
            return;
        }

        self::$recording = true;

        try {
            $now = now();
            $row = $error + $this->origin($request) + [
                'exception_class' => null, 'file' => null, 'line' => null, 'trace' => null, 'status_code' => null,
            ];
            $row['message'] = Str::limit((string) $row['message'], 2000);
            $row['context'] = $row['context'] ? json_encode($this->sanitize($row['context']), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : null;
            $row += ['occurrences' => 1, 'first_seen_at' => $now, 'last_seen_at' => $now, 'resolved_at' => null, 'resolved_by' => null, 'created_at' => $now, 'updated_at' => $now];

            $latest = ['level', 'message', 'exception_class', 'file', 'line', 'trace', 'context', 'url', 'method', 'status_code', 'ip', 'user_id', 'last_seen_at', 'updated_at'];

            $connection = $this->connection();
            $connection->table('error_logs')->upsert([$row], ['fingerprint'], [
                ...$latest,
                'occurrences' => DB::raw('occurrences + 1'),
                'resolved_at' => null, // it happened again: reopen
                'resolved_by' => null,
            ]);

            self::$lastId = (int) $connection->table('error_logs')->where('fingerprint', $row['fingerprint'])->value('id') ?: null;
        } catch (Throwable) {
            // Never let error tracking break the request; the file log still has the entry.
        } finally {
            self::$recording = false;
        }
    }

    /** @return array{url: ?string, method: ?string, ip: ?string, user_id: ?int} */
    private function origin(?Request $request): array
    {
        if ($request === null && app()->runningInConsole() && ! app()->runningUnitTests()) {
            return [
                'url' => Str::limit('artisan '.implode(' ', array_slice($_SERVER['argv'] ?? [], 1)), 2000),
                'method' => 'CLI',
                'ip' => null,
                'user_id' => null,
            ];
        }

        $request ??= app()->bound('request') ? request() : null;

        try {
            $userId = Auth::guard()->id();
        } catch (Throwable) {
            $userId = null;
        }

        return [
            'url' => $request ? Str::limit($this->redactQuery($request->fullUrl()), 2000) : null,
            'method' => $request?->method(),
            'ip' => $request?->ip(),
            'user_id' => is_numeric($userId) ? (int) $userId : null,
        ];
    }

    private function connection(): ConnectionInterface
    {
        $default = (string) config('database.default');
        $config = config("database.connections.{$default}");

        // An in-memory SQLite database cannot be shared by a second connection.
        if (! config('support.error_tracker.isolated') || ($config['database'] ?? null) === ':memory:') {
            return DB::connection($default);
        }

        if (config('database.connections.'.self::CONNECTION) === null) {
            config(['database.connections.'.self::CONNECTION => $config]);
        }

        return DB::connection(self::CONNECTION);
    }

    private function trace(Throwable $e): string
    {
        $trace = $e->getTraceAsString();

        for ($previous = $e->getPrevious(), $depth = 0; $previous !== null && $depth < 3; $previous = $previous->getPrevious(), $depth++) {
            $trace .= "\n\nCaused by ".$previous::class.': '.$previous->getMessage()
                .' in '.$this->relativePath($previous->getFile()).':'.$previous->getLine()."\n".$previous->getTraceAsString();
        }

        return Str::limit(str_replace(base_path().DIRECTORY_SEPARATOR, '', $trace), 60000);
    }

    /** Redact secrets, cap depth and size, turn objects into something readable. */
    private function sanitize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 4) {
            return '…';
        }

        if (is_array($value)) {
            $clean = [];
            foreach (array_slice($value, 0, 50, true) as $key => $item) {
                $clean[$key] = is_string($key) && preg_match(self::SECRET_KEYS, $key) ? '[redacted]' : $this->sanitize($item, $depth + 1);
            }

            return $clean;
        }

        return match (true) {
            $value instanceof Throwable => $value::class.': '.Str::limit($value->getMessage(), 300),
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof \JsonSerializable => $this->sanitize($value->jsonSerialize(), $depth + 1),
            $value instanceof \Stringable => Str::limit((string) $value, 1000),
            is_object($value) => $value::class,
            is_string($value) => Str::limit($value, 1000),
            default => $value,
        };
    }

    private function redactQuery(string $url): string
    {
        return (string) preg_replace_callback('/([?&])([^=&]+)=([^&]*)/', fn (array $m) => preg_match(self::SECRET_KEYS, urldecode($m[2]))
            ? $m[1].$m[2].'=[redacted]'
            : $m[0], $url);
    }

    private function relativePath(string $path): string
    {
        return Str::after($path, base_path().DIRECTORY_SEPARATOR);
    }
}
