<?php

namespace Aurora\Livewire;

use Aurora\Blocks\Fields;
use Aurora\Filament\Forms\Components\TranslatedField;
use Aurora\Models\MenuItem;
use Aurora\Models\Page;
use Aurora\Support\ContentLocale;
use Closure;
use Datlechin\FilamentMenuBuilder\FilamentMenuBuilderPlugin;
use Datlechin\FilamentMenuBuilder\Livewire\MenuItems as BaseMenuItems;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Arr;
use Livewire\Attributes\On;

/**
 * The menu builder's item list. Its "Edit" form opens as a dialog like the "Add" ones, takes
 * the title per language with the same language picker, picks a linked page by its title (not
 * its class and id), and has the icon picker the blocks use instead of a free-text icon field.
 */
class MenuItems extends BaseMenuItems
{
    /**
     * A dialog like the "Add" ones. The title is filled and saved per language here: the
     * package would put the stored translations, as an object, into a one-language field, and
     * saving keeps the titles of languages that are switched off for now.
     */
    /**
     * The language the menu page is showing (its language switcher); null for the default.
     */
    public ?string $locale = null;

    #[On('menu:locale')]
    public function useLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    /**
     * Titles read in the language picked on the menu page.
     */
    public function booted(): void
    {
        if ($this->locale !== null) {
            ContentLocale::set($this->locale);
        }
    }

    public function rendering(): void
    {
        $this->booted();
    }

    public function editAction(): Action
    {
        return parent::editAction()
            ->slideOver(false)
            ->modalWidth('lg')
            // Named after the item, with the icon and words of the "Add" dialog for its kind.
            ->modalHeading(fn (MenuItem $record): string => __('Edit “:title”', ['title' => $record->title]))
            ->modalIcon(fn (MenuItem $record): string => match (static::kind($record)) {
                'page' => 'phosphor-file-text',
                'link' => 'phosphor-link',
                default => 'phosphor-text-align-left',
            })
            ->modalDescription(fn (MenuItem $record): string => match (static::kind($record)) {
                'page' => __('Links to a page, and follows its address when it changes.'),
                'link' => __('To another site, a file, or an address on this site that isn’t a page.'),
                default => __('A heading without a link, for example to group the items nested under it.'),
            })
            ->modalSubmitActionLabel(__('Save'))
            ->fillForm(fn (MenuItem $record): array => [
                ...$record->toArray(),
                'title' => static::isTranslatable()
                    ? $record->getTranslations('title')
                    : ($record->getTranslations('title')[ContentLocale::default()] ?? null),
                // Opens in the language the menu page is showing.
                'title_language' => $this->locale ?? (static::isTranslatable() ? FilamentMenuBuilderPlugin::get()->getTranslatableLocales()[0] : null),
            ])
            ->action(function (array $data, array $arguments): void {
                $item = MenuItem::query()->findOrFail($arguments['id']);
                $typed = is_array($data['title'] ?? null) ? $data['title'] : [ContentLocale::default() => $data['title'] ?? null];

                $item->fill(Arr::except($data, ['title']));
                $item->setTranslations('title', array_filter([...$item->getTranslations('title'), ...$typed], filled(...)));
                $item->save();
            });
    }

    /**
     * What the item is: a page's item, a custom link, or a text label (neither).
     */
    protected static function kind(MenuItem $item): string
    {
        return match (true) {
            filled($item->linkable_type) => 'page',
            filled($item->url) => 'link',
            default => 'text',
        };
    }

    protected static function isTranslatable(): bool
    {
        $plugin = FilamentMenuBuilderPlugin::get();

        return $plugin->isTranslatable() && in_array('title', $plugin->getTranslatableMenuItemFields(), true);
    }

    /**
     * Laid out like the "Add" dialogs: what the item is and where it goes, then the rarely
     * changed rest folded away under "Advanced". A text label has nowhere to open.
     *
     * @return array<int, mixed>
     */
    protected function getEditFormSchema(): array
    {
        $link = [];
        $advanced = [];
        $title = null;

        foreach (parent::getEditFormSchema() as $component) {
            $name = $component instanceof Field ? $component->getName() : null;

            match (true) {
                $component instanceof Tabs, $name === 'title' => $title = static::titleField(),
                // The linked record as something people know: the page, picked by its title.
                $name === 'linkable_type' => $link[] = Hidden::make('linkable_type')->dehydrated(false),
                $name === 'linkable_id' => $link[] = static::pageField(),
                $name === 'url' => $link[] = $component,
                $name === 'target' => $link[] = $component->visible(fn (Get $get): bool => filled($get('linkable_type')) || filled($get('url'))),
                $name === 'icon' => $advanced[] = static::iconField(),
                default => $advanced[] = $component,
            };
        }

        // The language first, then the page, then the title (its placeholder is the page's title).
        $at = (int) array_search('linkable_id', array_map(fn (mixed $field): ?string => $field instanceof Field ? $field->getName() : null, $link), true) + 1;
        array_splice($link, $at, 0, [$title['fields']]);

        if ($title['picker'] !== null) {
            array_unshift($link, $title['picker']);
        }

        return [
            ...$link,
            Section::make(__('Advanced'))
                ->collapsed()
                ->compact()
                ->schema($advanced),
        ];
    }

    /**
     * A page's item may leave its title blank: it then shows the page's title, which is the
     * placeholder (in the language picked). Other items need one. With more than one
     * language, the language picker comes apart, to go first in the form.
     *
     * @return array{picker: ?Field, fields: mixed}
     */
    public static function titleField(): array
    {
        $field = TextInput::make('title')
            ->label(__('filament-menu-builder::menu-builder.form.title'))
            ->required(fn (Get $get): bool => blank($get('linkable_type')))
            ->helperText(fn (Get $get): ?string => filled($get('linkable_id')) ? __('Leave it blank to use the page’s title.') : null)
            ->maxLength(255);
        $placeholder = fn (string $locale): Closure => fn (Get $get): ?string => Page::query()->find($get('linkable_id'))?->getTranslation('title', $locale);

        if (! static::isTranslatable()) {
            return ['picker' => null, 'fields' => $field->placeholder($placeholder(ContentLocale::default()))];
        }

        return TranslatedField::parts($field, FilamentMenuBuilderPlugin::get()->getTranslatableLocales(), $placeholder);
    }

    /**
     * The page an item links to, for items that link to one: searchable by title, and the
     * item follows the page's address.
     */
    public static function pageField(): Field
    {
        return Select::make('linkable_id')
            ->label(__('Page'))
            // In the language picked for the title, if there is a picker.
            ->options(function (Get $get): array {
                $locale = $get('title_language') ?? ContentLocale::default();

                return Page::query()->get()
                    ->mapWithKeys(fn (Page $page): array => [$page->getKey() => $page->getTranslation('title', $locale)])
                    ->sort()
                    ->all();
            })
            ->searchable()
            ->required()
            ->live()
            // Drawn again in another language, so the chosen page's name follows too.
            ->key(fn (Get $get): string => 'menu-item-page-'.($get('title_language') ?? 'default'))
            ->visible(fn (Get $get): bool => $get('linkable_type') === (new Page)->getMorphClass());
    }

    public static function iconField(): Field
    {
        return Fields::icon('icon', 'Icon')
            ->helperText(__('Shown next to the item in dropdown menus.'));
    }
}
