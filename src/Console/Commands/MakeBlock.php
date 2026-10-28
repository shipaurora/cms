<?php

namespace Aurora\Console\Commands;

use Aurora\Blocks\Blocks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

/**
 * A new page block: its class in aurora.block_path (found there on its own) and its Blade
 * component at resources/views/components/blocks/{type}.blade.php, from Aurora's stubs or
 * the site's own in stubs/aurora (php artisan vendor:publish --tag=aurora-stubs).
 */
#[Signature('aurora:make-block
    {name : The block\'s name, e.g. "Pricing table"}
    {--container : A block that holds other blocks}
    {--category=content : Palette group: layout, content, marketing, media, design or developer}
    {--icon= : A Phosphor icon, e.g. phosphor-star}
    {--force : Overwrite the block if it exists}')]
#[Description('Create a page block: its class and its Blade view')]
class MakeBlock extends Command implements PromptsForMissingInput
{
    /**
     * @var array<int, string>
     */
    public const CATEGORIES = ['layout', 'content', 'marketing', 'media', 'design', 'developer'];

    public function handle(Filesystem $files): int
    {
        $base = Str::of((string) $this->argument('name'))->trim()->replaceMatches('/\s*block$/i', '');

        if ($base->isEmpty() || ! $base->studly()->isMatch('/^[A-Za-z][A-Za-z0-9]*$/')) {
            $this->components->error('Use letters and numbers for the name, e.g. "Pricing table".');

            return self::FAILURE;
        }

        $category = (string) $this->option('category');

        if (! in_array($category, self::CATEGORIES, true)) {
            $this->components->error('The category is one of: '.implode(', ', self::CATEGORIES).'.');

            return self::FAILURE;
        }

        $path = config('aurora.block_path') ?: app_path('Blocks');
        $namespace = config('aurora.block_namespace') ?: Blocks::namespaceFor($path);
        $class = $base->studly().'Block';
        $type = (string) $base->kebab()->replace(' ', '-');
        $label = (string) $base->snake(' ')->ucfirst();
        $container = (bool) $this->option('container');

        $classFile = $path.'/'.$class.'.php';
        $viewFile = resource_path('views/components/blocks/'.$type.'.blade.php');

        foreach ([$classFile, $viewFile] as $file) {
            if ($files->exists($file) && ! $this->option('force')) {
                $this->components->error("[{$file}] already exists. Use --force to overwrite it.");

                return self::FAILURE;
            }
        }

        $replace = [
            '{{ namespace }}' => $namespace,
            '{{ class }}' => $class,
            '{{ type }}' => $type,
            '{{ label }}' => $label,
            '{{ lowerLabel }}' => Str::lower($label),
            '{{ category }}' => $category,
            '{{ icon }}' => $this->option('icon') ?: ($container ? 'phosphor-square-split-vertical' : 'phosphor-stack'),
        ];

        $suffix = $container ? '.container' : '';
        $files->ensureDirectoryExists(dirname($classFile));
        $files->ensureDirectoryExists(dirname($viewFile));
        $files->put($classFile, strtr($files->get($this->stub("block{$suffix}.stub")), $replace));
        $files->put($viewFile, strtr($files->get($this->stub("block-view{$suffix}.stub")), $replace));

        $this->components->info("Block [{$type}] created.");
        $this->components->twoColumnDetail('Class', $this->relative($classFile));
        $this->components->twoColumnDetail('View', $this->relative($viewFile));
        $this->components->twoColumnDetail('Screenshot', "resources/block-previews/{$type}.webp (php artisan aurora:block-previews)");

        // Outside the folder Aurora looks in: it has to be registered by hand.
        if (blank(config('aurora.block_path'))) {
            $this->newLine();
            $this->components->warn('Block discovery is off (aurora.block_path). Register it in a service provider:');
            $this->line("    Blocks::register([\\{$namespace}\\{$class}::class]);");
        }

        return self::SUCCESS;
    }

    /**
     * The site's own copy of a stub, or Aurora's.
     */
    protected function stub(string $name): string
    {
        $own = base_path('stubs/aurora/'.$name);

        return is_file($own) ? $own : __DIR__.'/../../../stubs/'.$name;
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
        return ['name' => fn (): string => text('What is the block called?', placeholder: 'e.g. Pricing table', required: true)];
    }
}
