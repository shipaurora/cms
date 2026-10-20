<?php

namespace Aurora\Filament\Pages;

use Aurora\Filament\Concerns\TranslatesPageLabels;
use Aurora\Mcp\Capabilities;
use Aurora\Models\McpActivity;
use Aurora\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use RuntimeException;
use UnitEnum;

/**
 * Where an editor connects their own AI assistant to the CMS.
 *
 * Every connection is a Passport token, whichever way it was made: a Claude custom
 * connector signs in through OAuth, while Claude Code and code editors use a personal
 * access token created here. Either way the assistant acts as the person who connected it,
 * with exactly their permissions, and every change it makes is signed with their name.
 */
class AiAssistant extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageLabels;

    protected string $view = 'aurora::filament.pages.ai-assistant';

    protected static string|BackedEnum|null $navigationIcon = 'phosphor-sparkle';

    protected static string|BackedEnum|null $activeNavigationIcon = 'phosphor-sparkle-bold';

    protected static string|UnitEnum|null $navigationGroup = 'AI';

    protected static ?string $navigationLabel = 'Connect AI apps';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Connect your AI assistant';

    /**
     * The token just created, shown once and never stored in plain text.
     */
    public ?string $plainToken = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('use-mcp');
    }

    public function getSubheading(): ?string
    {
        return __('Let Claude (or any MCP client) read and edit this website with your permissions. Every change it makes shows up in the page history with your name.');
    }

    /**
     * The address assistants connect to. Claude custom connectors reach it from Anthropic's
     * cloud, so in local development set MCP_PUBLIC_URL to a public tunnel (`herd share`).
     */
    public function endpoint(): string
    {
        $base = config('services.mcp.public_url') ?: config('app.url');

        return rtrim($base, '/').'/mcp';
    }

    public function endpointIsPrivate(): bool
    {
        $host = (string) parse_url($this->endpoint(), PHP_URL_HOST);

        return str_ends_with($host, '.test') || str_ends_with($host, '.local') || in_array($host, ['localhost', '127.0.0.1'], true);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createToken')
                ->label(__('Create access token'))
                ->icon('phosphor-key')
                ->modalHeading(__('Create a personal access token'))
                ->modalDescription(__('For Claude Code, VS Code, Cursor and other clients without connector support. Anyone with this token can act as you in the CMS: treat it like a password. It expires after a year.'))
                ->modalSubmitActionLabel(__('Create token'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('Used by'))
                        ->placeholder(__('e.g. Claude Code on my laptop'))
                        ->required()
                        ->maxLength(80),
                ])
                ->action(function (array $data): void {
                    static::ensurePersonalAccessClient(auth()->user());

                    $this->plainToken = auth()->user()->createToken($data['name'], [Registrar::OAUTH_SCOPE])->accessToken;
                    $this->refreshConnections();

                    Notification::make()->title(__('Token created'))->body(__('Copy it now: it will not be shown again.'))->success()->send();
                }),
        ];
    }

    /**
     * Passport makes personal access tokens through a client of their own, which a new site
     * doesn't have yet (nothing creates it on install): made the first time someone asks for
     * a token, for their user provider.
     */
    public static function ensurePersonalAccessClient(User $user): void
    {
        $clients = app(ClientRepository::class);
        $provider = $user->getProviderName();

        try {
            $clients->personalAccessClient($provider);
        } catch (RuntimeException) {
            $clients->createPersonalAccessGrantClient('Personal access tokens', $provider);
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Connected apps'))
            ->description(__('Everything that can currently act as you. Disconnect anything you no longer use.'))
            ->records(fn (): Collection => $this->connections())
            ->columns([
                TextColumn::make('name')
                    ->weight(FontWeight::SemiBold)
                    ->icon(fn (array $record): string => $record['kind'] === 'connector' ? 'phosphor-puzzle-piece' : 'phosphor-key')
                    ->description(fn (array $record): ?string => $record['detail']),
                TextColumn::make('kind')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'connector' ? __('Connector (OAuth)') : __('Access token'))
                    ->color(fn (string $state): string => $state === 'connector' ? 'primary' : 'gray'),
                TextColumn::make('connected_at')->label(__('Connected'))->since(),
                TextColumn::make('expires_at')
                    ->label(__('Expires'))
                    ->date('M j, Y')
                    ->placeholder(__('Renews automatically')),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('disconnect')
                        ->label(__('Disconnect'))
                        ->icon('phosphor-prohibit')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(fn (array $record): string => __('Disconnect :name?', ['name' => $record['name']]))
                        ->modalDescription(__('It loses access immediately. You can connect it again at any time.'))
                        ->action(function (array $record): void {
                            $this->revoke($record['token_ids']);
                            $this->refreshConnections();
                            Notification::make()->title(__(':name disconnected', ['name' => $record['name']]))->success()->send();
                        }),
                ]),
            ])
            ->emptyStateIcon('phosphor-sparkle')
            ->emptyStateHeading(__('Nothing connected yet'))
            ->emptyStateDescription(__('Add the connector in Claude, or create an access token.'))
            ->paginated(false);
    }

    /**
     * The signed-in user's live connections (Claude connectors and personal tokens), for
     * the dashboard: once something is connected, it stops suggesting to connect.
     *
     * @return Collection<string, array<string, mixed>>
     */
    public static function currentConnections(): Collection
    {
        return app(static::class)->connections();
    }

    /**
     * Live connections: one per OAuth client (its tokens rotate on every refresh), and
     * one per personal access token.
     *
     * @return Collection<string, array<string, mixed>>
     */
    protected function connections(): Collection
    {
        $tokens = Token::query()
            ->with('client')
            ->where('user_id', auth()->id())
            ->where('revoked', false)
            ->latest()
            ->get()
            ->toBase();

        $personal = $tokens
            ->filter(fn (Token $token): bool => (bool) $token->client?->hasGrantType('personal_access') && $token->expires_at?->isFuture())
            ->mapWithKeys(fn (Token $token): array => [$token->id => [
                'key' => $token->id,
                'kind' => 'token',
                'name' => $token->name ?: __('Access token'),
                'detail' => __('Personal access token'),
                'connected_at' => $token->created_at,
                'expires_at' => $token->expires_at,
                'token_ids' => [$token->id],
            ]]);

        // A connector stays connected for as long as it can refresh, not just for the
        // lifetime of its current (hour-long) access token.
        $refreshable = RefreshToken::query()
            ->whereIn('access_token_id', $tokens->pluck('id'))
            ->where('revoked', false)
            ->where('expires_at', '>', now())
            ->pluck('access_token_id')
            ->all();

        $connectors = $tokens
            ->reject(fn (Token $token): bool => (bool) $token->client?->hasGrantType('personal_access'))
            ->filter(fn (Token $token): bool => $token->expires_at?->isFuture() || in_array($token->id, $refreshable, true))
            ->groupBy('client_id')
            ->mapWithKeys(function (Collection $clientTokens, string $clientId): array {
                $client = $clientTokens->first()->client;
                $host = parse_url((string) collect($client?->redirect_uris)->first(), PHP_URL_HOST);

                return ['client-'.$clientId => [
                    'key' => 'client-'.$clientId,
                    'kind' => 'connector',
                    'name' => $client?->name ?: 'MCP client',
                    'detail' => $host ? __('Signed in via :host', ['host' => $host]) : __('Signed in with OAuth'),
                    'connected_at' => $clientTokens->min('created_at'),
                    'expires_at' => null,
                    'token_ids' => Token::query()->where('user_id', auth()->id())->where('client_id', $clientId)->where('revoked', false)->pluck('id')->all(),
                ]];
            });

        return $connectors->merge($personal);
    }

    /**
     * The table caches its rows for the request (it loaded them to find the clicked one).
     * Eloquent-backed tables are reloaded after an action; array-backed ones are not.
     */
    protected function refreshConnections(): void
    {
        $this->cachedTableRecords = null;
    }

    /**
     * @param  array<int, string>  $tokenIds
     */
    protected function revoke(array $tokenIds): void
    {
        $tokens = Token::query()->where('user_id', auth()->id())->whereIn('id', $tokenIds);

        RefreshToken::query()->whereIn('access_token_id', (clone $tokens)->pluck('id'))->update(['revoked' => true]);
        $tokens->update(['revoked' => true]);
    }

    /**
     * @return array<int, array{name: string, description: string, writes: bool}>
     */
    public function tools(): array
    {
        return Capabilities::tools();
    }

    /**
     * @return Collection<int, McpActivity>
     */
    public function recentActivity(): Collection
    {
        return McpActivity::query()->whereBelongsTo(auth()->user())->with('subject')->latest('created_at')->limit(8)->get();
    }

    public function serverKey(): string
    {
        return Str::slug(config('aurora.name'));
    }
}
