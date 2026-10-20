<?php

namespace Aurora\Console\Commands;

use Aurora\Aurora;
use Aurora\Models\Layout;
use Aurora\Support\Layouts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

use function Laravel\Prompts\text;

/**
 * A new page layout of the website's own: its view at resources/views/layouts/{handle}.blade.php,
 * started from the site's standard layout (its head, styles, header and footer) to make this
 * one's own, and the layout itself, so editors can pick it for a page straight away. Its icon
 * is changed in Settings › Templates › Layouts.
 */
#[Signature('make:aurora-layout
    {name : The layout\'s name, e.g. "Campaign"}
    {--description= : What it\'s for, shown to editors when they pick a layout}
    {--force : Overwrite the view and update the layout if they exist}')]
#[Description('Create a page layout: its Blade view, registered as a layout editors can pick')]
class MakeLayout extends Command implements PromptsForMissingInput
{
    /**
     * The icon a new layout starts with.
     */
    public const ICON = 'phosphor-layout';

    public function handle(Filesystem $files): int
    {
        $name = trim((string) $this->argument('name'));
        $handle = Str::slug($name);

        if ($handle === '') {
            $this->components->error('Give the layout a name with letters or numbers, e.g. "Campaign".');

            return self::FAILURE;
        }

        if (! $this->hasLayoutsTable()) {
            $this->components->error('Set up the CMS first: php artisan aurora:install');

            return self::FAILURE;
        }

        $view = 'layouts.'.$handle;
        $file = resource_path('views/layouts/'.$handle.'.blade.php');
        $existing = Layout::query()->where('handle', $handle)->first();

        if (! $this->option('force') && ($existing !== null || $files->exists($file))) {
            $this->components->error($existing !== null
                ? "There is already a layout [{$handle}]. Use --force to overwrite its view and update it."
                : "[{$this->relative($file)}] already exists. Use --force to overwrite it.");

            return self::FAILURE;
        }

        $description = $this->description();

        $files->ensureDirectoryExists(dirname($file));
        $files->put($file, $this->viewFrom($files->get($this->startingPoint()), $name, $description));

        Layouts::put($existing, [
            'name' => $name,
            'handle' => $handle,
            'description' => $description,
            'icon' => $existing?->icon ?? self::ICON,
            'view' => $view,
        ]);

        $this->components->info("Layout [{$handle}] created.");
        $this->components->twoColumnDetail('View', $this->relative($file));
        $this->components->twoColumnDetail('Layout', "{$name} ({$view}), to pick for a page in the admin");

        return self::SUCCESS;
    }

    /**
     * What the layout is for: --description, or asked for (it may be left empty).
     */
    protected function description(): ?string
    {
        $given = $this->option('description');

        if ($given === null && $this->input->isInteractive()) {
            $given = text('What is it for?', placeholder: 'e.g. Campaign pages: no menu, a big hero', hint: 'Shown to editors when they pick a layout. Optional.');
        }

        return filled($given) ? trim((string) $given) : null;
    }

    /**
     * The view a new layout starts from: the site's own standard layout, else Aurora's.
     */
    protected function startingPoint(): string
    {
        $own = Layouts::path().'/standard.blade.php';

        return is_file($own) ? $own : Aurora::path('resources/views/layouts/standard.blade.php');
    }

    /**
     * The starting point with its first comment, which says what the standard layout is,
     * saying what this one is instead.
     */
    protected function viewFrom(string $source, string $name, ?string $description): string
    {
        $comment = "{{-- The {$name} layout".($description !== null ? ': '.rtrim($description, '.') : '').'. Made with'
            ." php artisan make:aurora-layout\n     from the standard layout: make it this layout's own. --}}";

        $replaced = preg_replace('/\{\{--.*?--\}\}/s', str_replace(['\\', '$'], ['\\\\', '\\$'], $comment), $source, 1, $count);

        return $count > 0 ? (string) $replaced : $comment."\n".$source;
    }

    protected function hasLayoutsTable(): bool
    {
        try {
            return Schema::hasTable((new Layout)->getTable());
        } catch (Throwable) {
            return false;
        }
    }

    protected function relative(string $path): string
    {
        return ltrim(Str::after($path, base_path()), DIRECTORY_SEPARATOR);
    }

    /**
     * @return array<string, mixed>
     */
    protected function promptForMissingArgumentsUsing(): array
    {
        return ['name' => fn (): string => text('What is the layout called?', placeholder: 'e.g. Campaign', required: true)];
    }
}
