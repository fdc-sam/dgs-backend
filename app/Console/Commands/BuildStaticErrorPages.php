<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Saves the DGS error views (resources/views/errors) as plain HTML in public/errors/, for the errors Nginx answers
 * itself, before Laravel runs: 403 for blocked files such as /.env, 404 for missing build files, 413 for uploads over
 * the size limit, and 502/504 when PHP is down or too slow. Nginx serves them with `error_page` (see
 * deploy/aws/nginx-error-pages.conf). Run it after changing an error view; a test fails while the files are stale.
 */
class BuildStaticErrorPages extends Command
{
    /** The codes Nginx can answer with its own page. */
    public const CODES = [403, 404, 413, 500, 502, 503, 504];

    protected $signature = 'errors:static';

    protected $description = 'Save the DGS error pages as static HTML in public/errors for Nginx';

    public function handle(): int
    {
        $dir = public_path('errors');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        foreach (self::CODES as $code) {
            file_put_contents("{$dir}/{$code}.html", self::render($code));
            $this->line("public/errors/{$code}.html");
        }

        $this->info('Saved '.count(self::CODES).' error pages.');

        return self::SUCCESS;
    }

    /** The static page for [code], exactly as saved. */
    public static function render(int $code): string
    {
        return view("errors.{$code}")->render();
    }
}
