<?php

namespace Aurora\Console\Commands;

use Aurora\Blocks\Blocks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

/**
 * What it takes to give each block its picture in the visual editor's block list: the
 * address that draws the block alone from its example data (Block::sample()), signed so a
 * screenshot tool needn't sign in, and the file to save its screenshot as
 * (resources/block-previews/{type}.webp), which Block::preview() then finds on its own.
 */
#[Signature('aurora:block-previews
    {--missing : Only the blocks that have no screenshot yet}
    {--minutes=120 : How long the signed addresses work}')]
#[Description('List each block\'s sample page and the file its screenshot goes in')]
class BlockPreviews extends Command
{
    public function handle(): int
    {
        $expires = now()->addMinutes(max(1, (int) $this->option('minutes')));
        $rows = [];

        foreach (Blocks::all() as $type => $block) {
            $file = Blocks::screenshotFile($type);

            if ($file !== null && $this->option('missing')) {
                continue;
            }

            $rows[] = [
                $type,
                $file === null ? 'none' : str_replace(base_path().'/', '', $file),
                URL::temporarySignedRoute('aurora.blocks.sample', $expires, ['type' => $type]),
            ];
        }

        if ($rows === []) {
            $this->components->info('Every block has a screenshot.');

            return self::SUCCESS;
        }

        $this->table(['Block', 'Screenshot', 'Sample page'], $rows);
        $this->newLine();
        $this->line('  Open each sample page in a 1600×900 window and save its screenshot, 800×450 WebP,');
        $this->line('  as resources/block-previews/{block}.webp. The visual editor shows it from then on.');

        return self::SUCCESS;
    }
}
