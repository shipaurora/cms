<?php

namespace Aurora\Filament\Concerns;

use Aurora\Filament\Forms\Components\WriteWithAi;
use Aurora\Support\AiWriter;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextInputColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * For pages whose table has ->withAi() columns: the "Write with AI" dialog a cell's
 * sparkle opens, writing that row's value and saving it the way the column saves edits.
 */
trait WritesColumnsWithAi
{
    public function writeColumnWithAiAction(): Action
    {
        return Action::make('writeColumnWithAi')
            ->visible(fn (): bool => WriteWithAi::isAvailable())
            ->modalHeading(fn (array $arguments): string => __('Write “:field” with AI', ['field' => strip_tags((string) $this->aiColumn($arguments)?->getLabel())]))
            ->modalDescription(__('The assistant writes it from the rest of the row. You can still edit it afterwards.'))
            ->modalIcon('phosphor-sparkle')
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('Write it'))
            ->modalHidden(fn (array $arguments): bool => ! (($column = $this->aiColumn($arguments)) && (WriteWithAi::columnSettings($column)['dialog'] ?? true)))
            ->schema([
                Textarea::make('instruction')
                    ->label(__('Instruction (optional)'))
                    ->placeholder(__('e.g. shorter, more formal, keep the placeholders'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (array $arguments, array $data, Action $action): void {
                $column = $this->aiColumn($arguments);
                $settings = $column ? WriteWithAi::columnSettings($column) : null;

                if ($column === null || $settings === null) {
                    return;
                }

                try {
                    $text = AiWriter::write(
                        field: strip_tags((string) $column->getLabel()),
                        current: is_string($state = $column->getState()) ? $state : null,
                        context: array_filter([
                            'About this field' => $settings['guidance'],
                            ...$this->aiRowContext($column),
                        ]),
                        instruction: $data['instruction'] ?? null,
                        language: $column->evaluate($settings['language']),
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

                $column->updateState($text);
            });
    }

    /**
     * The ->withAi() column and row the dialog is for, ready to read and save.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function aiColumn(array $arguments): ?TextInputColumn
    {
        $column = $this->getTable()->getColumn((string) ($arguments['column'] ?? ''));
        $record = $this->getTableRecord((string) ($arguments['record'] ?? ''));

        if (! $column instanceof TextInputColumn || ! $record instanceof Model || WriteWithAi::columnSettings($column) === null) {
            return null;
        }

        return $column->record($record);
    }

    /**
     * The row's other text, by column label: what the assistant writes from.
     *
     * @return array<string, string>
     */
    protected function aiRowContext(TextInputColumn $current): array
    {
        $context = [];

        foreach ($this->getTable()->getColumns() as $column) {
            if ($column->getName() === $current->getName() || ! $column instanceof Column) {
                continue;
            }

            $state = $column->record($current->getRecord())->getState();

            if (is_string($state) && filled($state) && ! Str::isUuid($state)) {
                $context[strip_tags((string) $column->getLabel()) ?: $column->getName()] = Str::limit($state, 2000);
            }
        }

        return $context;
    }
}
