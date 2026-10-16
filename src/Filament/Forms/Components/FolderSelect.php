<?php

namespace Aurora\Filament\Forms\Components;

use Aurora\Models\AssetFolder;
use Closure;
use Filament\Forms\Components\Select;

/**
 * Choose a media library folder. In the list, folders show their own name, indented under
 * their parent; the chosen folder and search results show the whole path ("People / Team",
 * the parents muted), where there is no indentation to tell. Empty means the top level.
 */
class FolderSelect extends Select
{
    /**
     * @var array<int, string>|Closure
     */
    protected array|Closure $unavailableFolders = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('Folder'))
            ->placeholder(__('Library (top level)'))
            ->searchable()
            ->allowHtml()
            ->options(fn (): array => $this->folderOptions())
            ->getSearchResultsUsing(fn (string $search): array => $this->folderOptions($search))
            ->disableOptionWhen(fn (string $value): bool => in_array($value, $this->evaluate($this->unavailableFolders) ?? [], true));
    }

    /**
     * Show these folders but don't let them be picked (greyed out): a folder can't move into
     * itself or one of its own folders.
     *
     * @param  array<int, string>|Closure  $folders
     */
    public function unavailable(array|Closure $folders): static
    {
        $this->unavailableFolders = $folders;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    protected function folderOptions(?string $search = null): array
    {
        $paths = AssetFolder::paths();

        if (filled($search)) {
            $paths = array_filter($paths, fn (array $path): bool => str_contains(
                mb_strtolower(implode(' / ', [...$path['parents'], $path['name']])),
                mb_strtolower(trim($search)),
            ));
        }

        $icon = svg('phosphor-folder-fill', 'cms-folder-option-icon')->toHtml();

        return array_map(fn (array $path): string => sprintf(
            '<span class="cms-folder-option%s" style="--depth: %d">%s<span class="cms-folder-option-path">%s</span><span class="cms-folder-option-name">%s</span></span>',
            filled($search) ? ' cms-folder-option-searched' : '',
            filled($search) ? 0 : $path['depth'],
            $icon,
            $path['parents'] === [] ? '' : e(implode(' / ', $path['parents'])).' / ',
            e($path['name']),
        ), $paths);
    }
}
