<?php

namespace Aurora\Filament\Forms\Components;

use Aurora\Support\SettingSecrets;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * A secret (an API key, a token) on a settings tab, named by its setting key, which the tab
 * lists in SettingsTabs::register(secretKeys:). It starts empty: the saved secret is never sent
 * to the browser, only its last four characters as the placeholder. Saving with it empty keeps
 * the secret; the bin removes it. Read it with SettingSecrets::get().
 */
class SecretInput extends TextInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->password()
            ->autocomplete('off')
            // Settings saves it encrypted itself (SettingSecrets::put()), never as typed.
            ->dehydrated(false)
            ->placeholder(fn (SecretInput $component): string => SettingSecrets::masked($component->getName()) ?? __('Paste it here'))
            ->helperText(fn (SecretInput $component): string => SettingSecrets::has($component->getName())
                ? __('Saved and stored encrypted. Leave this empty to keep it.')
                : __('Stored encrypted, and never shown again after saving.'))
            ->suffixAction(
                Action::make('removeSecret')
                    ->icon('phosphor-trash')
                    ->color('danger')
                    ->tooltip(__('Remove'))
                    ->visible(fn (SecretInput $component): bool => SettingSecrets::has($component->getName()))
                    ->requiresConfirmation()
                    ->modalHeading(fn (SecretInput $component): string => __('Remove the saved :label?', ['label' => mb_strtolower((string) $component->getLabel())]))
                    ->action(function (SecretInput $component): void {
                        SettingSecrets::put($component->getName(), null);
                        Notification::make()->title(__('Removed'))->success()->send();
                    }),
            );
    }
}
