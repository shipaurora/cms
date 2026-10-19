<?php

namespace Aurora\Livewire;

use Aurora\Enums\ApiScope;
use Aurora\Models\ApiKey;
use Aurora\Support\TableHeading;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The keys apps read the website's APIs with, REST and GraphQL alike (Aurora\Models\ApiKey):
 * in Settings › Security › API keys, and in the "Manage keys" dialog on each API's tab.
 * A new key is named for what uses it and shown once; revoking one stops it straight away.
 */
class ApiKeysTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    /**
     * Shown in the "Manage keys" dialog, which has the heading and description already.
     */
    public bool $inDialog = false;

    public function mount(): void
    {
        abort_unless(Gate::allows('manage-settings'), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ApiKey::query()->with('creator'))
            ->heading($this->inDialog ? null : TableHeading::make('phosphor-key', __('API keys')))
            ->description($this->inDialog ? null : __('Apps read the REST and GraphQL APIs with one of these keys, when an API is set to need one. Make one for each app, and revoke it when the app stops using it.'))
            ->defaultSort('created_at')
            ->paginated(false)
            ->emptyStateIcon('phosphor-key')
            ->emptyStateHeading(__('No API keys yet'))
            ->emptyStateDescription(__('Create one for each app that reads the website’s APIs.'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (ApiKey $record): string => $record->hint.'…'),
                TextColumn::make('scopes')
                    ->label(__('May'))
                    ->state(fn (ApiKey $record): string => count($record->scopes ?? []) === count(ApiScope::cases())
                        ? __('Everything')
                        : collect($record->scopes ?? [])->map(fn (string $scope): ?string => ApiScope::tryFrom($scope)?->getLabel())->filter()->implode(', '))
                    ->placeholder(__('Nothing'))
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label(__('Made'))
                    ->since()
                    ->description(fn (ApiKey $record): ?string => $record->creator?->name),
                TextColumn::make('last_used_at')
                    ->label(__('Last used'))
                    ->since()
                    ->placeholder(__('Not used yet')),
            ])
            ->headerActions([$this->createAction()])
            ->recordActions([
                EditAction::make()
                    ->modalHeading(__('Edit API key'))
                    ->modalWidth('md')
                    ->schema(fn (): array => $this->fields()),
                DeleteAction::make()
                    ->label(__('Revoke'))
                    ->icon('phosphor-trash')
                    ->modalHeading(__('Revoke this key?'))
                    ->modalDescription(fn (ApiKey $record): string => __('Apps using “:name” can’t read the APIs any more, straight away.', ['name' => $record->name]))
                    ->modalSubmitActionLabel(__('Revoke'))
                    ->successNotificationTitle(__('Key revoked')),
            ]);
    }

    protected function createAction(): Action
    {
        return Action::make('createApiKey')
            ->label(__('Create a key'))
            ->icon('phosphor-plus')
            ->modalHeading(__('Create an API key'))
            ->modalDescription(__('Apps send it with each request to the REST or GraphQL API. Make one for each app, so you can revoke one without stopping the others.'))
            ->modalIcon('phosphor-key')
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('Create key'))
            ->schema(fn (): array => $this->fields())
            ->fillForm(['scopes' => ApiScope::all()])
            ->action(function (array $data): void {
                [, $key] = ApiKey::issue($data['name'], auth()->user(), $data['scopes']);

                $this->replaceMountedAction('showApiKey', ['name' => $data['name'], 'key' => $key]);
            });
    }

    /**
     * A key's name and what it may do.
     *
     * @return array<int, mixed>
     */
    protected function fields(): array
    {
        return [
            TextInput::make('name')
                ->label(__('Name'))
                ->placeholder(__('e.g. Website frontend'))
                ->helperText(__('What uses it, so you know which key to revoke later.'))
                ->required()
                ->maxLength(120)
                ->autofocus(),
            CheckboxList::make('scopes')
                ->label(__('What it may do'))
                ->helperText(__('Only what the app needs: a request for anything else is turned away.'))
                ->options(ApiScope::class)
                ->descriptions(collect(ApiScope::cases())->mapWithKeys(fn (ApiScope $scope): array => [$scope->value => $scope->getDescription()])->all())
                ->required(),
        ];
    }

    /**
     * The new key, shown once: only its hash is kept.
     */
    public function showApiKeyAction(): Action
    {
        return Action::make('showApiKey')
            ->modalHeading(fn (array $arguments): string => __('Your new key for :name', ['name' => $arguments['name'] ?? '']))
            ->modalDescription(__('Copy it now: it isn’t shown again. Apps send it as Authorization: Bearer <key>.'))
            ->modalIcon('phosphor-key')
            ->modalWidth('lg')
            ->fillForm(fn (array $arguments): array => ['key' => $arguments['key'] ?? ''])
            ->schema([
                TextInput::make('key')
                    ->hiddenLabel()
                    ->readOnly()
                    ->copyable(copyMessage: __('Copied'))
                    ->extraInputAttributes(['class' => 'font-mono']),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Done'));
    }

    public function render(): View
    {
        return view('aurora::livewire.api-keys-table');
    }
}
