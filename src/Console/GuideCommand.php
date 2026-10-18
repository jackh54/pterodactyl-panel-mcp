<?php

namespace PanelMcp\Console;

use Illuminate\Console\Command;

/**
 * Installs the operations guide served by the get_ops_guide tool.
 *
 *   php artisan panel-mcp:guide /path/to/guide.md     copy a Markdown file into place
 *   php artisan panel-mcp:guide                        show where the guide lives and whether it exists
 *   php artisan panel-mcp:guide --remove               delete it (the tool then says there is none)
 */
class GuideCommand extends Command
{
    protected $signature = 'panel-mcp:guide {file? : Markdown file to install} {--remove : Delete the installed guide}';

    protected $description = 'Install, inspect or remove the operations guide AI clients read with get_ops_guide.';

    public function handle(): int
    {
        $target = (string) config('panel-mcp.ops_guide_path', storage_path('app/panel-mcp/ops-guide.md'));

        if ($this->option('remove')) {
            if (is_file($target)) {
                unlink($target);
                $this->info("Removed {$target}.");
            } else {
                $this->line('No guide installed.');
            }

            return self::SUCCESS;
        }

        $file = $this->argument('file');
        if ($file === null) {
            $this->line("Guide path: {$target}");
            $this->line(is_file($target)
                ? sprintf('Installed, %s KB, updated %s UTC.', number_format(filesize($target) / 1024, 1), gmdate('Y-m-d H:i', (int) filemtime($target)))
                : 'Not installed.');

            return self::SUCCESS;
        }

        if (! is_file($file) || ! is_readable($file)) {
            $this->error("Cannot read {$file}.");

            return self::FAILURE;
        }
        $text = (string) file_get_contents($file);
        if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
            $this->error('The guide must be a UTF-8 text (Markdown) file.');

            return self::FAILURE;
        }

        $dir = dirname($target);
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            $this->error("Cannot create {$dir}.");

            return self::FAILURE;
        }
        $tmp = $target.'.tmp';
        file_put_contents($tmp, $text);
        rename($tmp, $target);
        $this->info(sprintf('Installed %s (%s KB). Root admins can now read it with get_ops_guide.', $target, number_format(strlen($text) / 1024, 1)));
        $this->line('Never put passwords, tokens or keys in the guide: every root admin\'s AI client can read it.');

        return self::SUCCESS;
    }
}
