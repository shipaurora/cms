<?php

namespace Aurora\Console\Commands;

use Aurora\Blocks\Blocks;
use Aurora\Support\BlockScreenshots;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Each block's picture in the visual editor's block list, a screenshot committed with the
 * site's code (resources/block-previews/{type}.webp), which Block::preview() finds on its own.
 * Lists where each is taken from: the block alone, drawn from its example data
 * (Block::sample()), at a signed address a browser opens without signing in. A coding agent
 * takes the screenshot with its own browser and hands it over with --from, which makes it the
 * block list's size and kind; --generate takes them itself with Browsershot, when installed.
 */
#[Signature('aurora:block-previews
    {type? : One block}
    {--missing : Only the blocks with no picture in the site\'s code}
    {--json : As JSON, for scripts and coding agents}
    {--from= : Save this image (PNG, JPEG or WebP) as the block\'s screenshot}
    {--generate : Take the screenshots with Browsershot (spatie/browsershot)}
    {--minutes=120 : How long the signed addresses work}')]
#[Description('List, save or take the screenshots of the website\'s blocks for the visual editor\'s block list')]
class BlockPreviews extends Command
{
    public function handle(): int
    {
        $type = $this->argument('type');

        if ($type !== null && Blocks::find($type) === null) {
            $this->components->error("There's no [{$type}] block. php artisan aurora:block-previews lists them.");

            return self::FAILURE;
        }

        if (filled($this->option('from'))) {
            return $this->saveFrom($type, (string) $this->option('from'));
        }

        $types = $type !== null ? [$type] : array_values(array_filter(
            array_keys(Blocks::all()),
            fn (string $type): bool => ! $this->option('missing') || Blocks::find($type)::preview() === null,
        ));

        if ($this->option('generate')) {
            return $this->generate($types);
        }

        $rows = array_map(fn (string $type): array => [
            'type' => $type,
            'label' => Blocks::find($type)::label(),
            'sample_url' => BlockScreenshots::sampleUrl($type, (int) $this->option('minutes')),
            'file' => "resources/block-previews/{$type}.webp",
            'has_screenshot' => Blocks::screenshotFile($type) !== null,
        ], $types);

        if ($this->option('json')) {
            $this->line(json_encode(['window' => BlockScreenshots::WINDOW, 'blocks' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->components->info('Every block has a screenshot.');

            return self::SUCCESS;
        }

        $this->table(['Block', 'Screenshot', 'Sample page'], array_map(fn (array $row): array => [
            $row['type'], $row['has_screenshot'] ? $row['file'] : 'none', $row['sample_url'],
        ], $rows));
        $this->newLine();
        $this->line('  Screenshot each sample page in a 1600×900 window, then save it with');
        $this->line('  php artisan aurora:block-previews {block} --from=screenshot.png');
        $this->line('  (or let Aurora take them: --generate, with spatie/browsershot installed).');

        return self::SUCCESS;
    }

    protected function saveFrom(?string $type, string $image): int
    {
        if ($type === null) {
            $this->components->error('Name the block the screenshot is of: aurora:block-previews hero --from=hero.png');

            return self::FAILURE;
        }

        if (! is_file($image)) {
            $this->components->error("There's no file [{$image}].");

            return self::FAILURE;
        }

        try {
            $file = BlockScreenshots::saveToSite($type, BlockScreenshots::toWebp((string) file_get_contents($image)));
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Saved '.str_replace(base_path().'/', '', $file).'. Commit it with the block.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $types
     */
    protected function generate(array $types): int
    {
        if (! BlockScreenshots::canCapture()) {
            $this->components->error('Taking screenshots needs spatie/browsershot: composer require spatie/browsershot --dev, and Chrome or Chromium. Or take them with a browser of your own and save each with --from.');

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($types as $type) {
            try {
                $file = BlockScreenshots::saveToSite($type, BlockScreenshots::capture($type));
                $this->components->twoColumnDetail($type, str_replace(base_path().'/', '', $file));
            } catch (Throwable $e) {
                $failed++;
                $this->components->twoColumnDetail($type, '<fg=red>'.$e->getMessage().'</>');
            }
        }

        if ($types === []) {
            $this->components->info('Every block has a screenshot.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
