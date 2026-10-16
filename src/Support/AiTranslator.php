<?php

namespace Aurora\Support;

use Aurora\Mcp\Resources\SiteGuide;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use RuntimeException;

/**
 * Translates website text with the AI assistant's provider and model: many short texts at
 * once, sent and returned as JSON keyed by an id, so each translation lands back where its
 * text came from (a block's field, a page's title).
 */
class AiTranslator implements Agent
{
    use Promptable;

    /**
     * Texts per request: enough to translate a page in a few calls, small enough to stay reliable.
     */
    public const BATCH = 40;

    public function instructions(): string
    {
        $guide = rescue(fn (): string => (string) app(SiteGuide::class)->handle()->content(), '', report: false);

        return implode("\n\n", array_filter([
            'You translate the text of '.config('app.name').'\'s website.',
            <<<'MARKDOWN'
            - You get a JSON object of texts by id. Reply with a JSON object with the same ids and each text translated, and nothing else: no explanations, no code fences.
            - Translate the meaning, naturally, the way a native copywriter would write it; keep the tone and length.
            - Keep HTML tags and attributes, Markdown, *asterisks* around highlighted words, :placeholders, URLs, e-mail addresses and product names exactly as they are. A text with | between its parts lists plural forms: translate each part and keep the |, and any {0} or [2,*] before a part.
            - Never add or leave out information.
            MARKDOWN,
            filled($guide) ? "## Content guide\n".$guide : null,
        ]));
    }

    /**
     * @param  array<string, string>  $texts  by id
     * @return array<string, string> the translations, by the same ids (any the AI skipped are left out)
     */
    public static function translate(array $texts, string $to, ?string $from = null): array
    {
        $languages = ContentLocale::all();
        $from = $languages[$from ?? ContentLocale::default()] ?? $from;
        $to = $languages[$to] ?? $to;
        $translated = [];

        foreach (array_chunk($texts, self::BATCH, preserve_keys: true) as $batch) {
            $reply = (string) (new static)->prompt(
                "Translate from {$from} into {$to}:\n\n".json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                provider: Copilot::provider(),
                model: Copilot::model(),
            )->text;

            $json = json_decode(Str::of($reply)->trim()->replaceMatches('/^```(?:json)?\s*|\s*```$/', '')->toString(), true);

            if (! is_array($json)) {
                throw new RuntimeException(__('The assistant’s answer could not be read. Try again.'));
            }

            foreach ($batch as $id => $text) {
                if (is_string($json[$id] ?? null) && filled($json[$id])) {
                    $translated[$id] = $json[$id];
                }
            }
        }

        return $translated;
    }
}
