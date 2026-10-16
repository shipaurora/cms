<?php

namespace Aurora\Console\Commands;

use Aurora\Support\TranslationSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aurora:export-translations')]
#[Description('Write labels edited in the admin back into the lang files, so they can be committed')]
class ExportTranslations extends Command
{
    public function handle(TranslationSync $sync): int
    {
        $result = $sync->export();

        $this->components->info("Wrote {$result['labels']} edited labels to {$result['files']} lang files.");

        return self::SUCCESS;
    }
}
