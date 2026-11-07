<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes files under tickets/ on the attachment disk that no attachments
 * row points to and that are older than --hours (default 24). Such files are
 * left behind when a transaction rolls back after StoreAttachments wrote to
 * disk and the in-process cleanup could not run (crash, killed worker, outer
 * transaction rolled back). The age threshold keeps uploads that are still
 * mid-transaction safe.
 */
class PurgeOrphanAttachments extends Command
{
    protected $signature = 'attachments:purge-orphans
        {--hours=24 : Only delete orphan files older than this}
        {--dry-run : List what would be deleted}';

    protected $description = 'Delete attachment files that have no database row';

    private const CHUNK = 500;

    public function handle(): int
    {
        $disk = Storage::disk((string) config('support.attachments.disk'));
        $cutoff = now()->subHours(max(1, (int) $this->option('hours')))->getTimestamp();
        $dryRun = (bool) $this->option('dry-run');
        $deleted = 0;

        foreach (array_chunk($disk->allFiles('tickets'), self::CHUNK) as $paths) {
            // One query per chunk, not per file.
            $known = Attachment::query()->whereIn('stored_name', $paths)->pluck('stored_name')->all();

            foreach (array_diff($paths, $known) as $path) {
                if ($disk->lastModified($path) > $cutoff) {
                    continue; // too new: may belong to a transaction still running
                }

                $deleted++;

                if ($dryRun) {
                    $this->line("Would delete {$path}");
                } else {
                    $disk->delete($path);
                }
            }
        }

        if (! $dryRun && $deleted > 0) {
            Log::info('Orphan attachment files purged.', ['count' => $deleted]);
        }

        $this->components->info(sprintf('%s %d orphan file(s).', $dryRun ? 'Would delete' : 'Deleted', $deleted));

        return self::SUCCESS;
    }
}
