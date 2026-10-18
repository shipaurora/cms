<?php

namespace Aurora\Support;

use Aurora\Mcp\Resources\SiteGuide;
use Aurora\Models\Asset;
use Aurora\Models\Locale;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Promptable;

/**
 * Writes the text for one form field ("Write with AI" on titles, summaries, SEO text, alt
 * text…), with the AI assistant's provider, model and key from Site settings. One request,
 * one answer: nothing is kept in the assistant's chat history.
 */
class AiWriter implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        $guide = rescue(fn (): string => (string) app(SiteGuide::class)->handle()->content(), '', report: false);

        return implode("\n\n", array_filter([
            'You write the text for a single field in the admin panel of '.config('app.name').", the website's content management system.",
            <<<'MARKDOWN'
            - Reply with the text for the field and nothing else: no quotes, no labels, no explanations, no Markdown.
            - Base it on the context you are given. Never invent facts, prices, names or quotes.
            - Write like a friendly expert: plain words, short sentences, active voice. No buzzwords, no exclamation marks.
            - Stay within the maximum length when one is given.
            MARKDOWN,
            filled($guide) ? "## Content guide\n".$guide : null,
        ]));
    }

    /**
     * The text for a field, from its label, current text, the rest of the form and an
     * optional instruction ("shorter", "more playful").
     *
     * @param  array<string, string>  $context  other fields and page content, by label
     * @param  array<int, Image>  $images  pictures the text is about (e.g. the image to write alt text for)
     * @param  string|null  $language  the language to write in (a code like "nb"); the website's by default
     */
    public static function write(string $field, ?string $current = null, array $context = [], ?string $instruction = null, ?int $maxLength = null, array $images = [], ?string $language = null): string
    {
        $code = filled($language) ? $language : ContentLocale::default();
        $language = Locale::options()[$code] ?? $code;
        $isWebsiteLanguage = $code === ContentLocale::default();

        $prompt = implode("\n\n", array_filter([
            "Write the text for the field \"{$field}\".",
            $isWebsiteLanguage
                ? "Write in {$language}, the website's language, unless the instruction asks for another."
                : "Write in {$language}.",
            $maxLength ? "Maximum length: {$maxLength} characters." : null,
            filled($current) ? "Its current text:\n{$current}" : 'It is empty.',
            filled($instruction) ? "Instruction from the editor:\n{$instruction}" : null,
            $images === [] ? null : 'The attached image is what the field is about: describe what you actually see in it.',
            $context === [] ? null : "Context:\n".collect($context)->map(fn (string $value, string $label): string => "{$label}: {$value}")->implode("\n"),
        ]));

        $text = trim((string) (new static)->prompt($prompt, attachments: $images, provider: Copilot::provider(), model: Copilot::model())->text);
        $text = trim($text, " \t\n\"'“”«»");

        return $maxLength ? mb_substr($text, 0, $maxLength) : $text;
    }

    /**
     * A media library image as the AI sees it: its 1280px copy (the original when there is
     * none), sent as data, since the AI provider can't reach this site's storage.
     */
    public static function imageOf(Asset $asset): ?Image
    {
        $media = $asset->file();

        if ($media === null || ! $asset->isImage()) {
            return null;
        }

        $conversion = $media->hasGeneratedConversion('md') ? 'md' : '';
        $disk = $conversion === '' ? $media->disk : $media->conversions_disk;
        $contents = rescue(fn (): ?string => Storage::disk($disk)->get($media->getPathRelativeToRoot($conversion)), null, report: false);

        if ($contents === null) {
            return null;
        }

        return Image::fromBase64(base64_encode($contents), $conversion === '' ? $media->mime_type : 'image/webp');
    }
}
