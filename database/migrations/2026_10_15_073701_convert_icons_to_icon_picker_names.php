<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Icons are now picked from every Heroicon and stored by their full name. Blocks stored a
 * bare Heroicon name ("sparkles"), and menu items the admin-style name
 * ("phosphor-house"); both become the website's names ("hero-o-sparkles", "hero-o-home").
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pages')->whereNotNull('blocks')->orderBy('id')->each(function (object $page): void {
            $blocks = json_decode($page->blocks, true);

            if (! is_array($blocks)) {
                return;
            }

            $converted = $this->convertBlocks($blocks);

            if ($converted !== $blocks) {
                DB::table('pages')->where('id', $page->id)->update(['blocks' => json_encode($converted)]);
            }
        });

        DB::table(config('filament-menu-builder.tables.menu_items', 'menu_items'))
            ->where('icon', 'like', 'heroicon-%')
            ->update(['icon' => DB::raw("CONCAT('hero-', SUBSTRING(icon, 10))")]);
    }

    /**
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    protected function convertBlocks(array $node): array
    {
        $style = match ($node['type'] ?? null) {
            'feature' => 'o',
            'button' => 'm',
            default => null,
        };

        $icon = $node['data']['icon'] ?? null;

        if ($style !== null && is_string($icon) && is_file(base_path("vendor/blade-ui-kit/blade-heroicons/resources/svg/{$style}-{$icon}.svg"))) {
            $node['data']['icon'] = "hero-{$style}-{$icon}";
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->convertBlocks($value);
            }
        }

        return $node;
    }
};
