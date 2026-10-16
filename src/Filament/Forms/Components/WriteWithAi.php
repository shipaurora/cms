<?php

namespace Aurora\Filament\Forms\Components;

use Aurora\Models\Asset;
use Aurora\Models\Page;
use Aurora\Support\AiWriter;
use Aurora\Support\Copilot;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Tables\Columns\TextInputColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;
use Throwable;
use WeakMap;

/**
 * "Write with AI" for a text field: a sparkle next to its label that writes (or rewrites)
 * the field from the rest of the form and, on a page, the page's content. Shown only when
 * the AI assistant is set up and the person may use it.
 *
 * Added with ->withAi() on any TextInput or Textarea; chain ->withAiImage() to show the
 * assistant a picture as well (an image's alt text, say), and ->withoutAiDialog() to write
 * straight away on click, without asking for an instruction first (for text with one right
 * answer: a translation, alt text). Registered in AppServiceProvider:
 *
 *     TextInput::make('alt')->withAi('Alt text for screen readers')->withAiImage(fn (Asset $record) => $record)->withoutAiDialog()
 */
class WriteWithAi
{
    /**
     * The image each field shows the assistant, set with ->withAiImage().
     *
     * @var WeakMap<Field, mixed>|null
     */
    protected static ?WeakMap $images = null;

    /**
     * Fields that write on click, set with ->withoutAiDialog().
     *
     * @var WeakMap<Field, bool>|null
     */
    protected static ?WeakMap $withoutDialog = null;

    public static function register(): void
    {
        foreach ([TextInput::class, Textarea::class] as $field) {
            $field::macro('withAi', function (?string $guidance = null, Closure|string|null $language = null): Field {
                /** @var Field $this */
                return $this->hintAction(WriteWithAi::make($guidance, $language));
            });

            // An Asset, a file path, an uploaded file or an AI Image, or a closure returning
            // one (the record, $get and the rest of Filament's injections are available).
            $field::macro('withAiImage', function (mixed $image): Field {
                /** @var Field $this */
                WriteWithAi::imageFor($this, $image);

                return $this;
            });

            $field::macro('withoutAiDialog', function (bool $condition = true): Field {
                /** @var Field $this */
                WriteWithAi::withoutDialogFor($this, $condition);

                return $this;
            });
        }
    }

    /**
     * The settings of each table column made writable with ->withAi().
     *
     * @var WeakMap<TextInputColumn, array{guidance: ?string, language: Closure|string|null, dialog: bool}>|null
     */
    protected static ?WeakMap $columns = null;

    /**
     * ->withAi() on an editable table column: a sparkle in each cell writes that row's value,
     * through the page's writeColumnWithAi action (Aurora\Filament\Concerns\WritesColumnsWithAi).
     */
    public static function registerColumn(): void
    {
        TextInputColumn::macro('withAi', function (?string $guidance = null, Closure|string|null $language = null): TextInputColumn {
            /** @var TextInputColumn $this */
            WriteWithAi::columnSettings($this, ['guidance' => $guidance, 'language' => $language, 'dialog' => true]);

            return $this->suffix(fn (TextInputColumn $column, Model $record): ?HtmlString => WriteWithAi::cellButton($column, $record), isInline: true);
        });

        TextInputColumn::macro('withoutAiDialog', function (bool $condition = true): TextInputColumn {
            /** @var TextInputColumn $this */
            WriteWithAi::columnSettings($this, ['dialog' => ! $condition] + (WriteWithAi::columnSettings($this) ?? ['guidance' => null, 'language' => null]));

            return $this;
        });
    }

    /**
     * @param  array{guidance?: ?string, language?: Closure|string|null, dialog?: bool}|null  $settings
     * @return array{guidance: ?string, language: Closure|string|null, dialog: bool}|null
     */
    public static function columnSettings(TextInputColumn $column, ?array $settings = null): ?array
    {
        static::$columns ??= new WeakMap;

        if ($settings !== null) {
            static::$columns[$column] = $settings;
        }

        return static::$columns[$column] ?? null;
    }

    public static function isAvailable(): bool
    {
        return Copilot::isConfigured() && (bool) auth()->user()?->can('use-assistant');
    }

    public static function cellButton(TextInputColumn $column, Model $record): ?HtmlString
    {
        if (! static::isAvailable()) {
            return null;
        }

        $arguments = Js::from(['column' => $column->getName(), 'record' => (string) $record->getKey()]);

        return new HtmlString(sprintf(
            '<button type="button" class="cms-ai-cell-button" title="%s" aria-label="%1$s" x-on:click.stop.prevent="$wire.mountAction(\'writeColumnWithAi\', %s)">%s</button>',
            e(__('Write with AI')),
            e($arguments),
            svg('phosphor-sparkle', 'size-4')->toHtml(),
        ));
    }

    /**
     * Whether a field asks for an instruction before writing (unless ->withoutAiDialog()).
     */
    public static function hasDialog(Field $field): bool
    {
        return ! (static::$withoutDialog[$field] ?? false);
    }

    public static function withoutDialogFor(Field $field, bool $condition = true): void
    {
        static::$withoutDialog ??= new WeakMap;
        static::$withoutDialog[$field] = $condition;
    }

    public static function imageFor(Field $field, mixed $image): void
    {
        static::$images ??= new WeakMap;
        static::$images[$field] = $image;
    }

    /**
     * The image set for a field with ->withAiImage(), ready to send, or null.
     */
    protected static function image(Field $component): ?Image
    {
        $image = isset(static::$images[$component]) ? $component->evaluate(static::$images[$component]) : null;

        return match (true) {
            $image instanceof Image => $image,
            $image instanceof Asset => AiWriter::imageOf($image),
            $image instanceof UploadedFile => Image::fromUpload($image),
            is_string($image) && is_file($image) => Image::fromPath($image),
            default => null,
        };
    }

    /**
     * @param  string|null  $guidance  what this field is for, beyond its label ("50-60 characters, for search results")
     */
    public static function make(?string $guidance = null, Closure|string|null $language = null): Action
    {
        return Action::make('writeWithAi')
            ->label(__('Write with AI'))
            ->icon('phosphor-sparkle')
            ->iconButton()
            ->tooltip(__('Write with AI'))
            ->color('primary')
            ->visible(fn (): bool => static::isAvailable())
            ->modalHeading(fn (Field $component): string => __('Write “:field” with AI', ['field' => strip_tags((string) $component->getLabel())]))
            ->modalDescription(__('The assistant writes it from the rest of the form and the page content. You can still edit it before saving.'))
            ->modalIcon('phosphor-sparkle')
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('Write it'))
            ->modalHidden(fn (Field $component): bool => ! static::hasDialog($component))
            ->schema(fn (Field $component): array => [
                Textarea::make('instruction')
                    ->label(__('Instruction (optional)'))
                    ->placeholder(__('e.g. shorter, more playful, mention the spring workshops'))
                    ->rows(2)
                    ->maxLength(500),
                Text::make(__('The assistant sees the image too.'))
                    ->visible(static::image($component) !== null),
            ])
            ->action(function (array $data, Field $component, Action $action) use ($guidance, $language): void {
                try {
                    $text = AiWriter::write(
                        field: strip_tags((string) $component->getLabel()),
                        current: is_string($component->getState()) ? $component->getState() : null,
                        context: static::context($component, $guidance),
                        instruction: $data['instruction'] ?? null,
                        maxLength: method_exists($component, 'getMaxLength') ? $component->getMaxLength() : null,
                        images: array_values(array_filter([static::image($component)])),
                        language: $component->evaluate($language),
                    );
                } catch (Throwable $exception) {
                    report($exception);
                    Notification::make()->title(__('The assistant couldn’t write it'))->body(Str::limit($exception->getMessage(), 200))->danger()->send();
                    $action->halt();
                }

                if ($text === '') {
                    Notification::make()->title(__('The assistant didn’t suggest anything'))->warning()->send();

                    return;
                }

                $component->state($text);
                $component->callAfterStateUpdated();
            });
    }

    /**
     * What the assistant gets to go on: the field's purpose, the form's other text fields,
     * and the page's content when the form is a page's.
     *
     * @return array<string, string>
     */
    protected static function context(Field $component, ?string $guidance): array
    {
        $context = array_filter(['About this field' => $guidance]);

        foreach ($component->getRootContainer()->getFlatFields(withHidden: false) as $field) {
            $state = $field->getState();

            if ($field === $component || ! is_string($state) || blank($state) || Str::isUuid($state) || mb_strlen($state) > 2000) {
                continue;
            }

            $context[strip_tags((string) $field->getLabel()) ?: $field->getName()] = $state;
        }

        $record = $component->getRecord();

        if ($record instanceof Page && filled($content = static::pageText($record->blocks ?? []))) {
            $context['Page content'] = $content;
        }

        return $context;
    }

    /**
     * The words on a page, from its blocks, in order: enough to write about it.
     */
    protected static function pageText(array $blocks): string
    {
        $words = [];

        array_walk_recursive($blocks, function (mixed $value, int|string $key) use (&$words): void {
            if (! is_string($value) || Str::isUuid($value) || in_array($key, ['type', 'id', 'parent', 'slot', 'style', 'variant', 'tone', 'size', 'icon'], true) || str_starts_with($value, 'http') || str_starts_with($value, '/')) {
                return;
            }

            $text = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['*'], '', $value))));

            if (mb_strlen($text) > 2 && str_contains($text, ' ')) {
                $words[] = $text;
            }
        });

        return Str::limit(implode("\n", array_unique($words)), 4000);
    }
}
