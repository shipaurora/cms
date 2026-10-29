<?php

namespace Aurora\Console\Commands;

use Aurora\Support\Permissions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The permissions a website added (Permissions::register()) since the site was installed: created,
 * so Users & roles can give them, and given to the built-in roles they're granted to. Roles an
 * administrator changed are left as they are. Run after deploying a website's new permissions.
 */
#[Signature('aurora:sync-permissions')]
#[Description('Create the website\'s new permissions and give them to the roles they\'re granted to')]
class SyncPermissions extends Command
{
    public function handle(): int
    {
        Permissions::sync();

        $this->components->info(count(Permissions::all()).' permissions in Users & roles.');

        return self::SUCCESS;
    }
}
