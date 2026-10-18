<?php

namespace Aurora\Filament\Resources\Forms;

use Aurora\Enums\SubmissionStatus;
use Aurora\Filament\Concerns\TranslatesResourceLabels;
use Aurora\Filament\Resources\Forms\Pages\CreateForm;
use Aurora\Filament\Resources\Forms\Pages\EditForm;
use Aurora\Filament\Resources\Forms\Pages\ListForms;
use Aurora\Filament\Resources\Forms\RelationManagers\SubmissionsRelationManager;
use Aurora\Models\Form;
use Aurora\Models\FormSubmission;
use Aurora\Models\Page;
use Aurora\Support\ContentLocale;
use Aurora\Support\FormFields;
use Aurora\Support\SearchThumbnail;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use LaraZeus\SpatieTranslatable\Resources\Concerns\Translatable;
use UnitEnum;

class FormResource extends Resource
{
    use Translatable;
    use TranslatesResourceLabels;

    protected static ?string $model = Form::class;

    protected static string|BackedEnum|null $navigationIcon = 'phosphor-clipboard-text';

    protected static string|BackedEnum|null $activeNavigationIcon = 'phosphor-clipboard-text-bold';

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationBadge(): ?string
    {
        // New submissions to this site's forms.
        $new = once(fn (): int => FormSubmission::query()->where('status', SubmissionStatus::New)->whereHas('form')->count());

        return $new > 0 ? (string) $new : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('New submissions');
    }

    public static function form(Schema $schema): Schema
    {
        $canBuild = fn (): bool => auth()->user()->can('update Form');

        return $schema
            ->columns(3)
            ->components([
                Group::make()->columnSpan(['lg' => 2])->schema([
                    // The fields are the same in every language: built in the language they
                    // were written in, shown only there. Hidden in the browser rather than by
                    // Filament, so switching languages keeps them in the form.
                    Section::make(__('Fields'))
                        ->icon('phosphor-rows')
                        ->description(__('What people fill in. Drag to reorder.'))
                        ->extraAttributes(fn (?Form $record): array => ['x-show' => static::inLanguage(static::writtenIn($record))])
                        ->disabled(fn (): bool => ! $canBuild())
                        ->schema([
                            Builder::make('fields')
                                ->hiddenLabel()
                                ->blocks(FormFields::builderBlocks())
                                ->blockPickerColumns(2)
                                ->blockNumbers(false)
                                ->collapsible()
                                ->collapsed()
                                ->cloneable()
                                ->addActionLabel(__('Add a field'))
                                ->minItems(1),
                        ]),
                    // In another language: the fields' text, one language at a time.
                    Section::make(__('Field text'))
                        ->icon('phosphor-translate')
                        ->description(fn (?Form $record): string => __('The fields are the same in every language; translate their text here. Empty boxes show the :language text.', ['language' => ContentLocale::all()[static::writtenIn($record)] ?? '']))
                        ->visible(fn (?Form $record): bool => $record !== null && static::otherLanguages($record) !== [])
                        ->extraAttributes(fn (?Form $record): array => ['x-show' => '! ('.static::inLanguage(static::writtenIn($record)).')', 'x-cloak' => true])
                        ->disabled(fn (): bool => ! $canBuild())
                        ->schema(fn (?Form $record): array => $record === null ? [] : collect(static::otherLanguages($record))
                            ->map(fn (string $name, string $code): Group => Group::make(FormFields::translationSchema($record, $code))
                                ->extraAttributes(['x-show' => static::inLanguage($code), 'x-cloak' => true]))
                            ->values()
                            ->all()),
                ]),
                Group::make()->columnSpan(['lg' => 1])->schema([
                    Section::make(__('Form'))
                        ->icon('phosphor-clipboard-text')
                        ->disabled(fn (): bool => ! $canBuild())
                        ->schema([
                            TextInput::make('name')->required()->maxLength(120)->placeholder(__('e.g. Contact')),
                            Toggle::make('is_active')->label(__('Accepting submissions'))->default(true),
                            // Required in the default language; another language falls back to it.
                            TextInput::make('submit_label')
                                ->label(__('Button label'))
                                ->default('Send')
                                ->required(fn ($livewire): bool => static::isWrittenLanguage($livewire))
                                ->placeholder(fn (?Form $record): ?string => $record?->getTranslation('submit_label', static::writtenIn($record), false))
                                ->maxLength(40),
                        ]),
                    Section::make(__('After sending'))
                        ->icon('phosphor-seal-check')
                        ->disabled(fn (): bool => ! $canBuild())
                        ->schema([
                            Textarea::make('success_message')->label(__('Thank-you message'))->rows(3)->placeholder(fn (?Form $record): string => $record?->getTranslation('success_message', static::writtenIn($record), false) ?: __('Thanks! We will reply within one working day.')),
                            Select::make('redirect_page_id')
                                ->label(__('Or go to a page'))
                                ->options(fn (): array => Page::options())
                                ->searchable()
                                ->placeholder(__('Stay on the page')),
                        ]),
                    Section::make(__('Notifications'))
                        ->icon('phosphor-bell-ringing')
                        ->disabled(fn (): bool => ! $canBuild())
                        ->schema([
                            TagsInput::make('notify')
                                ->label(__('Email every submission to'))
                                ->placeholder(__('name@company.com'))
                                ->nestedRecursiveRules(['email:rfc']),
                        ]),
                ]),
            ]);
    }

    /**
     * The language the form's fields are written in (the default site language, for a new form).
     */
    protected static function writtenIn(?Form $record): string
    {
        return $record?->sourceLocale() ?? ContentLocale::default();
    }

    /**
     * Languages besides the one the fields are written in.
     *
     * @return array<string, string>
     */
    protected static function otherLanguages(Form $form): array
    {
        return array_diff_key(ContentLocale::all(), [$form->sourceLocale() => true]);
    }

    /**
     * Whether the edit page shows the language the fields are written in.
     */
    protected static function isWrittenLanguage(mixed $livewire): bool
    {
        $record = $livewire->record ?? null;

        return ($livewire->activeLocale ?? ContentLocale::default()) === static::writtenIn($record instanceof Form ? $record : null);
    }

    /**
     * The delete confirmation: which live pages show the form, if any.
     */
    public static function deleteDescription(Form $form): string
    {
        $pages = $form->livePages();

        if ($pages->isEmpty()) {
            return __('It goes to the trash, where it can be restored. No live page shows it.');
        }

        return trans_choice(
            'It is on a live page: :pages. Visitors there won’t see the form until it is restored.|It is on :count live pages: :pages. Visitors there won’t see the form until it is restored.',
            $pages->count(),
            ['pages' => $pages->pluck('title')->map(fn (string $title): string => '“'.$title.'”')->join(', ')],
        );
    }

    /**
     * Alpine: whether the edit page shows a language (the language switcher's choice).
     */
    protected static function inLanguage(string $code): string
    {
        // Single quotes: the expression goes inside a double-quoted HTML attribute.
        return "(\$wire.activeLocale ?? '".ContentLocale::default()."') === '{$code}'";
    }

    /**
     * @return array<int, string>
     */
    public static function getTranslatableLocales(): array
    {
        return array_keys(ContentLocale::all()) ?: [ContentLocale::default()];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (EloquentBuilder $query) => $query->withCount(['submissions', 'newSubmissions']))
            ->columns([
                TextColumn::make('name')
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Form $record): string => trans_choice(':count field|:count fields', count($record->fields ?? [])))
                    ->searchable(),
                TextColumn::make('new_submissions_count')
                    ->label(__('New'))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'primary' : 'gray'),
                TextColumn::make('submissions_count')->label(__('Total'))->color('gray'),
                ToggleColumn::make('is_active')
                    ->label(__('Accepting'))
                    ->disabled(fn (): bool => ! auth()->user()->can('update Form')),
                TextColumn::make('updated_at')->label(__('Changed'))->since()->color('gray'),
            ])
            ->recordActions([
                EditAction::make()->label(__('Open')),
                DeleteAction::make()->modalDescription(fn (Form $record): string => static::deleteDescription($record)),
            ])
            ->emptyStateIcon('phosphor-clipboard-text')
            ->emptyStateHeading(__('No forms yet'))
            ->emptyStateDescription(__('Build a form, then place it on any page with the Form block.'));
    }

    public static function getRelations(): array
    {
        return [
            SubmissionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListForms::route('/'),
            'create' => CreateForm::route('/create'),
            'edit' => EditForm::route('/{record}/edit'),
        ];
    }

    /**
     * In global search, with the same icon as in the sidebar.
     */
    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return SearchThumbnail::title(strip_tags((string) static::getRecordTitle($record)), null, icon: static::getNavigationIcon());
    }
}
