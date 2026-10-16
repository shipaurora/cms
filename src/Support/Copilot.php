<?php

namespace Aurora\Support;

use Aurora\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;

/**
 * The in-panel AI copilot (eslam-reda-div/filament-copilot), configured in Site settings
 * rather than .env: which provider and model it talks to, and the API key, stored
 * encrypted. Until it is set up (isConfigured(): a provider and model, an API key unless the
 * provider runs without one, and an address for a custom endpoint) the copilot is switched
 * off and nobody sees it.
 */
class Copilot
{
    /**
     * Providers people can pick, with an example model for each.
     *
     * @var array<string, array{label: string, example: string}>
     */
    public const PROVIDERS = [
        'anthropic' => ['label' => 'Anthropic (Claude)', 'example' => 'claude-sonnet-5-5'],
        'openai' => ['label' => 'OpenAI', 'example' => 'gpt-5'],
        'gemini' => ['label' => 'Google Gemini', 'example' => 'gemini-2.5-pro'],
        'mistral' => ['label' => 'Mistral', 'example' => 'mistral-large-latest'],
        'groq' => ['label' => 'Groq', 'example' => 'llama-3.3-70b-versatile'],
        'xai' => ['label' => 'xAI (Grok)', 'example' => 'grok-4'],
        'deepseek' => ['label' => 'DeepSeek', 'example' => 'deepseek-chat'],
        'custom' => ['label' => 'Custom endpoint', 'example' => 'the model id your endpoint expects'],
    ];

    /**
     * The APIs a custom endpoint can speak (Laravel AI SDK drivers that take a base URL).
     *
     * @var array<string, string>
     */
    public const CUSTOM_APIS = [
        'openai-compatible' => 'OpenAI-compatible (Chat Completions): OpenRouter, Together, vLLM, LM Studio, LiteLLM …',
        'openai' => 'OpenAI (Responses API)',
        'anthropic' => 'Anthropic (Messages API)',
        'gemini' => 'Google Gemini',
        'ollama' => 'Ollama',
    ];

    public const KEY_SETTING = 'copilot_api_key';

    /**
     * The assistant's instructions until someone changes them in Site settings: who it is,
     * how it talks and how careful it is. {site} and {user} are filled in when it runs.
     * The CMS tool guide, the content guide and the current screen are always added after.
     */
    public const DEFAULT_INSTRUCTIONS = <<<'MARKDOWN'
        You are the assistant in the admin panel of {site}, the website's content management system. You work for {user}, the person signed in, with exactly their permissions.

        ## How you work
        - Keep answers short: the user can see the result on screen.
        - Act on clear requests straight away ("change the heading to …"), without asking for confirmation. Ask first before deleting anything, publishing, or replacing a whole page.
        - Make the smallest change that does what was asked: prefer `update_block` over rewriting a page.
        - After changing a page, say what you changed in one sentence. The editor the user has open reloads by itself.
        - Use `remember` for preferences the user wants you to keep (tone, names, recurring choices).

        ## How you write for the website
        - Write like a friendly expert: plain words, short sentences, active voice. No buzzwords, no exclamation marks.
        - Headlines say what the reader gets; body text answers the questions a visitor would ask.
        - Every image needs alt text that describes what is in it.
        - Never invent facts, prices, names or quotes. If something is missing, ask or leave a clearly marked placeholder.
        MARKDOWN;

    /**
     * The instructions from Site settings, or the default, ready for the person signed in.
     */
    public static function instructions(string $userName): string
    {
        $instructions = Setting::get('copilot.instructions');

        return strtr(filled($instructions) ? $instructions : self::DEFAULT_INSTRUCTIONS, [
            '{site}' => config('app.name'),
            '{user}' => $userName,
        ]);
    }

    public static function provider(): ?string
    {
        $provider = Setting::get('copilot.provider');

        return array_key_exists((string) $provider, self::PROVIDERS) ? $provider : null;
    }

    public static function model(): ?string
    {
        return filled($model = Setting::get('copilot.model')) ? trim($model) : null;
    }

    /**
     * For the custom provider: which API the endpoint speaks.
     */
    public static function customApi(): ?string
    {
        $api = Setting::get('copilot.custom_api');

        return array_key_exists((string) $api, self::CUSTOM_APIS) ? $api : null;
    }

    public static function customUrl(): ?string
    {
        return filled($url = Setting::get('copilot.custom_url')) ? rtrim(trim($url), '/') : null;
    }

    /**
     * Ollama usually runs without a key; every other provider needs one.
     */
    public static function needsApiKey(): bool
    {
        return ! (self::provider() === 'custom' && self::customApi() === 'ollama');
    }

    public static function apiKey(): ?string
    {
        $encrypted = Setting::get(self::KEY_SETTING);

        if (blank($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            // Encrypted with another APP_KEY: treat it as missing so it can be entered again.
            return null;
        }
    }

    public static function hasApiKey(): bool
    {
        return self::apiKey() !== null;
    }

    /**
     * The last four characters, to recognise which key is saved without showing it.
     */
    public static function maskedKey(): ?string
    {
        $key = self::apiKey();

        return $key === null ? null : '••••'.substr($key, -4);
    }

    public static function storeApiKey(?string $key): void
    {
        Setting::put([self::KEY_SETTING => blank($key) ? null : Crypt::encryptString(trim($key))]);
    }

    /**
     * Set up and switched on: what everything else asks before showing the assistant.
     */
    public static function isConfigured(): bool
    {
        return static::isSetUp() && static::isSwitchedOn();
    }

    /**
     * Switched on in Site settings (on unless switched off), independent of its setup: switching
     * it off keeps the provider, model and key.
     */
    public static function isSwitchedOn(): bool
    {
        try {
            return Setting::get('copilot.enabled') !== false;
        } catch (QueryException) {
            // No settings table yet (fresh install, migrations running).
            return false;
        }
    }

    /**
     * A provider and model chosen (and a key, an address for a custom endpoint) — whether or
     * not it's switched on.
     */
    public static function isSetUp(): bool
    {
        try {
            $provider = self::provider();

            if ($provider === 'custom' && (self::customApi() === null || self::customUrl() === null)) {
                return false;
            }

            return $provider !== null && self::model() !== null && (self::hasApiKey() || ! self::needsApiKey());
        } catch (QueryException) {
            // No settings table yet (fresh install, migrations running).
            return false;
        }
    }

    /**
     * Point the copilot and the Laravel AI SDK at the configured provider, model and key.
     */
    public static function applyConfig(): void
    {
        if (! self::isConfigured()) {
            return;
        }

        $provider = self::provider();

        if ($provider === 'custom') {
            config(['ai.providers.custom' => [
                'driver' => self::customApi(),
                'key' => self::apiKey() ?? '',
                'url' => self::customUrl(),
            ]]);
        } else {
            config(["ai.providers.{$provider}.key" => self::apiKey()]);
        }

        config([
            'filament-copilot.provider' => $provider,
            'filament-copilot.model' => self::model(),
            'ai.default' => $provider,
        ]);
    }
}
