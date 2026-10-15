<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-time copy of images stored on this server into Supabase Storage, keeping the same paths
 * so the database references stay valid. Safe to re-run: existing objects are skipped.
 */
class UploadMediaToStorage extends Command
{
    protected $signature = 'dgs:upload-media {--from-public=public} {--from-private=local} {--to-public=media} {--to-private=media_private} {--dry-run}';

    protected $description = 'Copy local uploads (public + private) into the Supabase Storage buckets';

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function handle(): int
    {
        $this->copyAll($this->option('from-public'), $this->option('to-public'));
        // Only uploaded files live under private/ on the local disk (e.g. payment receipts).
        $this->copyAll($this->option('from-private'), $this->option('to-private'), prefix: 'private');

        return self::SUCCESS;
    }

    private function copyAll(string $from, string $to, string $prefix = ''): void
    {
        $source = Storage::disk($from);
        $target = Storage::disk($to);
        $copied = $skipped = 0;

        foreach ($source->allFiles($prefix) as $path) {
            if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true)) {
                continue;
            }
            if ($target->exists($path)) {
                $skipped++;

                continue;
            }
            if (! $this->option('dry-run')) {
                $target->put($path, $source->get($path), [
                    'ContentType' => $source->mimeType($path) ?: 'application/octet-stream',
                ]);
            }
            $copied++;
            $this->line(($this->option('dry-run') ? '[dry-run] ' : '')."{$from}:{$path} → {$to}");
        }

        $this->info("{$from} → {$to}: {$copied} copied, {$skipped} already present.");
    }
}
