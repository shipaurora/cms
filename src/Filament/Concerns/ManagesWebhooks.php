<?php

namespace Aurora\Filament\Concerns;

use Aurora\Models\Webhook;
use Aurora\Support\Webhooks;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\HtmlString;

/**
 * Settings › Integrations › Webhooks: the addresses told about what happens to the content
 * (Aurora\Support\Webhooks), a row each with how its last request went, changed in a dialog
 * of their own. Changes save straight away.
 */
trait ManagesWebhooks
{
    /**
     * @return array<int, Component>
     */
    protected function webhookRows(): array
    {
        $webhooks = Webhook::query()->orderBy('created_at')->get();

        return [
            Group::make($webhooks->map(fn (Webhook $webhook): Flex => Flex::make([
                Text::make(new HtmlString('<span class="cms-format-status '.match ($webhook->lastSucceeded()) {
                    true => 'cms-format-status-ready',
                    false => 'cms-format-status-missing',
                    null => 'cms-format-status-pending',
                }.'"></span>'))->grow(false),
                Group::make([
                    Text::make($webhook->url)->weight(FontWeight::Medium)->color('neutral'),
                    Text::make(collect([
                        collect($webhook->events)->map(fn (string $event): string => $event)->implode(', '),
                        $webhook->is_active ? null : __('Paused'),
                        match ($webhook->lastSucceeded()) {
                            true => __('Last sent :when', ['when' => $webhook->last_sent_at->diffForHumans()]),
                            false => __('Last try failed :when: :error', ['when' => $webhook->last_sent_at->diffForHumans(), 'error' => $webhook->last_error]),
                            null => __('Not sent yet'),
                        },
                    ])->filter()->implode(' · '))->size('sm'),
                ]),
                Actions::make([
                    $this->testWebhookAction($webhook),
                    $this->editWebhookAction($webhook),
                    $this->deleteWebhookAction($webhook),
                ])->key('webhook-'.$webhook->id)->grow(false),
            ])->verticallyAlignCenter()->extraAttributes(['class' => 'cms-format-row']))->all())
                ->extraAttributes(['class' => 'cms-format-list']),
            Actions::make([$this->editWebhookAction(null)])->key('add-webhook'),
        ];
    }

    protected function editWebhookAction(?Webhook $webhook): Action
    {
        $isNew = $webhook === null;

        return Action::make($isNew ? 'addWebhook' : 'editWebhook')
            ->label($isNew ? __('Add webhook') : __('Edit'))
            ->icon($isNew ? 'phosphor-plus-bold' : 'phosphor-pencil-simple')
            ->color('gray')
            ->when(! $isNew, fn (Action $action): Action => $action->iconButton())
            ->after(fn () => $this->refreshWebhookRows())
            ->modalHeading($isNew ? __('Add a webhook') : __('Edit webhook'))
            ->modalDescription(__('Tell another system when something happens to the content. It gets a POST with JSON, signed with the secret in the X-Aurora-Signature header.'))
            ->modalIcon('phosphor-plugs-connected')
            ->modalWidth('lg')
            ->modalSubmitActionLabel($isNew ? __('Add webhook') : __('Save'))
            ->fillForm(fn (): array => $isNew ? ['events' => ['page.published'], 'is_active' => true] : [
                'url' => $webhook->url,
                'events' => $webhook->events,
                'is_active' => $webhook->is_active,
                'secret' => $webhook->secret,
            ])
            ->schema([
                TextInput::make('url')
                    ->label(__('Address'))
                    ->url()
                    ->required()
                    ->placeholder('https://example.com/webhooks/aurora')
                    ->maxLength(255),
                CheckboxList::make('events')
                    ->label(__('Send it when'))
                    ->options(Webhooks::options())
                    ->required(),
                Toggle::make('is_active')->label(__('Send requests')),
                TextInput::make('secret')
                    ->label(__('Secret'))
                    ->helperText(__('Check the signature with it: an HMAC-SHA256 of the request body.'))
                    ->disabled()
                    ->copyable()
                    ->extraInputAttributes(['class' => 'font-mono'])
                    ->visible(! $isNew),
            ])
            ->action(function (array $data) use ($webhook, $isNew): void {
                $values = ['url' => $data['url'], 'events' => array_values($data['events']), 'is_active' => (bool) $data['is_active']];
                $isNew ? Webhook::query()->create($values) : $webhook->update($values);

                Notification::make()->title($isNew ? __('Webhook added') : __('Webhook saved'))->success()->send();
            });
    }

    protected function testWebhookAction(Webhook $webhook): Action
    {
        return Action::make('testWebhook')
            ->label(__('Send a test'))
            ->icon('phosphor-paper-plane-tilt')
            ->color('gray')
            ->iconButton()
            ->action(function () use ($webhook): void {
                $sent = Webhooks::deliver($webhook, 'test', ['message' => 'A test from Aurora.']);
                $this->refreshWebhookRows();

                Notification::make()
                    ->title($sent ? __('The test went through') : __('The test didn’t go through'))
                    ->body($sent ? null : $webhook->fresh()->last_error)
                    ->{$sent ? 'success' : 'danger'}()
                    ->send();
            });
    }

    protected function deleteWebhookAction(Webhook $webhook): Action
    {
        return Action::make('deleteWebhook')
            ->label(__('Delete'))
            ->icon('phosphor-trash')
            ->color('danger')
            ->iconButton()
            ->after(fn () => $this->refreshWebhookRows())
            ->requiresConfirmation()
            ->modalHeading(__('Delete this webhook?'))
            ->modalDescription(fn (): string => __(':url isn’t told about changes any more.', ['url' => $webhook->url]))
            ->action(fn () => $webhook->delete());
    }

    protected function refreshWebhookRows(): void
    {
        $this->rebuildForm();
    }
}
