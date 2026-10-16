<?php

namespace Aurora\Console\Commands;

use Aurora\Support\TranslationSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aurora:sync-translations')]
#[Description('Add new labels from the code to the default lang file, then import the lang files into the database')]
class SyncTranslations extends Command
{
    public function handle(TranslationSync $sync): int
    {
        $scan = $sync->scan();
        $this->components->info("Found {$scan['found']} labels in the code: {$scan['added']} new, added to the default lang file.");

        $this->call('aurora:import-translations');

        return self::SUCCESS;
    }
}
