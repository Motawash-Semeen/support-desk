<?php

namespace App\Modules\Logs\Logging;

use App\Modules\Logs\Services\ErrorRecorder;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Throwable;

/**
 * Monolog handler behind the `tracker` log channel (part of the default
 * stack). Every log entry at or above the channel level, which includes every
 * exception Laravel reports, is stored in the admin error log as well as in
 * the log file.
 */
class ErrorTrackerHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        try {
            $recorder = app(ErrorRecorder::class);
            $context = $record->context;
            $level = strtolower($record->level->getName());

            if (($context['exception'] ?? null) instanceof Throwable) {
                $exception = $context['exception'];
                unset($context['exception']);
                $recorder->recordException($exception, $level, $context);

                return;
            }

            $recorder->recordLog($level, $record->message, $context);
        } catch (Throwable) {
            // The logger must never throw.
        }
    }
}
