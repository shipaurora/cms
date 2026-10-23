<?php

namespace Aurora\Console\Commands;

use Aurora\Aurora;
use Aurora\Blocks\Blocks;
use Aurora\Models\Layout;
use Aurora\Support\Layouts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

use function Laravel\Prompts\text;

/**
 * A new page layout of the website's own, defined in code: its class in aurora.layout_path
 * (found there on its own, like blocks) from Aurora's stub or the site's own in stubs/aurora
 * (php artisan vendor:publish --tag=aurora-stubs), and its view at
 * resources/views/layouts/{handle}.blade.php, started from the site's standard layout (its
 * head, styles, header and footer) to make this one's own. Editors can pick it for a page
 * straight away.
 */
#[Signature('make:aurora-layout
    {name : The layout\'s name, e.g. "Campaign"}
    {--description= : What it\'s for, shown to editors when they pick a layout}
    {--icon= : A Phosphor icon, e.g. phosphor-megaphone}
    {--force : Overwrite the class and view if they exist}')]
#[Description('Create a page layout: its class and its Blade view, offered for editors to pick')]
class MakeLayout extends Command implements PromptsForMissingInput
{
    /**
     * The icon a new layout starts with.
     */
    public const ICON = 'phosphor-layout';

    public function handle(Filesystem $files): int
    {
        $name = Str::of((string) $this->argument('name'))->trim()->replaceMatches('/\s*layout$/i', '');
        $handle = (string) $name->slug();

        if ($handle === '' || ! $name->studly()->isMatch('/^[A-Za-z][A-Za-z0-9]*$/')) {
            $this->components->error('Use letters and numbers for the name, e.g. "Campaign".');

            return self::FAILURE;
        }

        $path = config('aurora.layout_path') ?: app_path('Layouts');
        $namespace = config('aurora.layout_namespace') ?: Blocks::namespaceFor($path);
        $class = $name->studly().'Layout';
        $view = 'layouts.'.$handle;

        $classFile = $path.'/'.$class.'.php';
        $viewFile = resource_path('views/layouts/'.$handle.'.blade.php');

        foreach ([$classFile, $viewFile] as $file) {
            if ($files->exists($file) && ! $this->option('force')) {
                $this->components->error("[{$this->relative($file)}] already exists. Use --force to overwrite it.");

                return self::FAILURE;
            }
        }

        $description = $this->description();

        $files->ensureDirectoryExists(dirname($classFile));
        $files->ensureDirectoryExists(dirname($viewFile));
        $files->put($classFile, strtr($files->get($this->stub('layout.stub')), [
            '{{ namespace }}' => $namespace,
            '{{ class }}' => $class,
            '{{ handle }}' => $handle,
            '{{ name }}' => (string) $name,
            '{{ purpose }}' => $description ?? 'what this layout is for, in a sentence.',
            '{{ description }}' => $description === null ? 'null' : sprintf('__(%s)', var_export($description, true)),
            '{{ icon }}' => $this->option('icon') ?: self::ICON,
            '{{ view }}' => $view,
        ]));
        $files->put($viewFile, $this->viewFrom($files->get($this->startingPoint()), (string) $name, $description));

        $this->components->info("Layout [{$handle}] created.");
        $this->components->twoColumnDetail('Class', $this->relative($classFile));
        $this->components->twoColumnDetail('View', $this->relative($viewFile));

        // Outside the folder Aurora looks in: it has to be registered by hand.
        if (blank(config('aurora.layout_path'))) {
            $this->newLine();
            $this->components->warn('Layout discovery is off (aurora.layout_path). Register it in a service provider:');
            $this->line("    Layouts::register([\\{$namespace}\\{$class}::class]);");
        }

        // One added in the admin keeps its handle: this one isn't offered until that's gone.
        if ($this->addedInTheAdmin($handle)) {
            $this->newLine();
            $this->components->warn("A layout added in the admin already has the handle [{$handle}]: it stays, and this one isn't offered until it's deleted.");
        }

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

    /**
     * Whether a layout added in the admin has the handle (the table there to ask, and the
     * column that tells them apart).
     */
    protected function addedInTheAdmin(string $handle): bool
    {
        try {
            return Layout::storesClasses() && Layout::query()->where('handle', $handle)->whereNull('class')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The site's own copy of a stub, or Aurora's.
     */
    protected function stub(string $name): string
    {
        $own = base_path('stubs/aurora/'.$name);

        return is_file($own) ? $own : Aurora::path('stubs/'.$name);
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
