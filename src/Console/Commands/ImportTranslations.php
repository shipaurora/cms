<?php

namespace Aurora\Console\Commands;

use Aurora\Support\TranslationSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aurora:import-translations')]
#[Description('Import every label from the lang files (app, Laravel and packages) into the database, keeping edits made in the admin')]
class ImportTranslations extends Command
{
    public function handle(TranslationSync $sync): int
    {
        $result = $sync->import();

        $this->components->info("Imported {$result['found']} labels: {$result['added']} new, {$result['updated']} updated.");

        if ($result['unused'] > 0) {
            $this->components->warn("{$result['unused']} labels in the database are no longer in the lang files (kept).");
        }

        return self::SUCCESS;
    }
}
