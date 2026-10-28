<?php

namespace Aurora\Filament\Resources\Forms\RelationManagers;

use Aurora\Enums\SubmissionStatus;
use Aurora\Filament\Resources\Forms\FormResource;
use Aurora\Models\FormSubmission;
use Aurora\Support\FormFields;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'submissions';

    protected static ?string $title = 'Submissions';

    protected static string|\BackedEnum|null $icon = 'phosphor-tray';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __(static::$title);
    }

    protected static function getModelLabel(): ?string
    {
        return __('submission');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('submissions');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $new = $ownerRecord->newSubmissions()->count();

        return $new > 0 ? __(':count new', ['count' => $new]) : null;
    }

    /**
     * Links can open a submission straight away (the admin's notifications do):
     * ?actionComponent=submissions&tableAction=view&tableActionRecord={id}.
     */
    public function getDefaultActionUrlIdentifier(): ?string
    {
        return 'submissions';
    }

    /**
     * Where a submission opens: its form, with the submission's view on top.
     */
    public static function submissionUrl(FormSubmission $submission): string
    {
        return FormResource::getUrl('edit', [
            'record' => $submission->form,
            // The Submissions tab (the form's only relation manager), not the form itself.
            'relation' => '0',
            'actionComponent' => 'submissions',
            'tableAction' => 'view',
            'tableActionRecord' => $submission->getKey(),
        ], panel: 'admin', tenant: $submission->form->site);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            ViewEntry::make('submission')->hiddenLabel()->view('aurora::filament.infolists.submission'),
        ])->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('page'))
            ->recordTitle(fn (FormSubmission $record): string => $record->summary() ?: 'Submission')
            ->columns([
                TextColumn::make('status')->badge(),
                TextColumn::make('summary')
                    ->label(__('From'))
                    ->state(fn (FormSubmission $record): string => $record->summary())
                    ->weight(fn (FormSubmission $record): string => $record->status === SubmissionStatus::New ? 'semibold' : 'normal')
                    ->description(fn (FormSubmission $record): ?string => str($record->answers()[2]['value'] ?? '')->limit(80)->toString() ?: null)
                    ->wrap(),
                TextColumn::make('page.title')->label(__('Page'))->color('gray')->placeholder('–'),
                TextColumn::make('created_at')->label(__('Received'))->since()->dateTimeTooltip()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(SubmissionStatus::class)->default(null),
            ])
            ->headerActions([
                Action::make('export')
                    ->label(__('Export CSV'))
                    ->icon('phosphor-download-simple')
                    ->color('gray')
                    ->action(fn (): StreamedResponse => $this->export($this->getOwnerRecord()->submissions()->where('status', '!=', SubmissionStatus::Spam)->get())),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('Open'))
                    ->slideOver()
                    ->modalHeading(fn (FormSubmission $record): string => $record->summary() ?: __('Submission'))
                    ->modalWidth('2xl')
                    // Opening it reads it: the "new" counts in the sidebar and on the tab go down.
                    ->mountUsing(function (FormSubmission $record): void {
                        if ($record->status === SubmissionStatus::New) {
                            $record->update(['status' => SubmissionStatus::Read]);
                            $this->dispatch('refresh-sidebar');
                        }
                    })
                    ->extraModalFooterActions(fn (FormSubmission $record): array => array_filter([
                        filled($email = $record->replyAddress()) ? Action::make('reply')
                            ->label(__('Reply'))
                            ->icon('phosphor-arrow-bend-up-left')
                            ->url('mailto:'.$email) : null,
                    ])),
                Action::make('archive')
                    ->label(__('Archive'))
                    ->icon('phosphor-archive')
                    ->color('gray')
                    ->visible(fn (FormSubmission $record): bool => $record->status !== SubmissionStatus::Archived)
                    ->action(function (FormSubmission $record): void {
                        $record->update(['status' => SubmissionStatus::Archived]);
                        $this->dispatch('refresh-sidebar');
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ...collect([SubmissionStatus::Read, SubmissionStatus::Archived, SubmissionStatus::Spam])->map(fn (SubmissionStatus $status): BulkAction => BulkAction::make('mark'.$status->name)
                        ->label(__('Mark as :status', ['status' => mb_strtolower($status->getLabel())]))
                        ->icon($status->getIcon())
                        ->action(function (Collection $records) use ($status): void {
                            $records->each->update(['status' => $status]);
                            $this->dispatch('refresh-sidebar');
                        })
                        ->deselectRecordsAfterCompletion())->all(),
                    BulkAction::make('exportSelected')
                        ->label(__('Export selected'))
                        ->icon('phosphor-download-simple')
                        ->action(fn (Collection $records): StreamedResponse => $this->export($records)),
                    // Triage is for everyone who sees submissions; deleting them is for form builders.
                    DeleteBulkAction::make()->authorize(fn (): bool => (bool) auth()->user()?->can('update Form')),
                ]),
            ])
            ->emptyStateIcon('phosphor-tray')
            ->emptyStateHeading(__('No submissions yet'))
            ->emptyStateDescription(__('Place this form on a page with the Form block.'));
    }

    /**
     * @param  Collection<int, FormSubmission>  $submissions
     */
    protected function export(Collection $submissions): StreamedResponse
    {
        $fields = collect($this->getOwnerRecord()->definitions())->filter(fn (array $field): bool => FormFields::find($field['type'])::stores());

        return response()->streamDownload(function () use ($submissions, $fields): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [__('Received'), __('Status'), __('Page'), ...$fields->pluck('label')->all()]);

            foreach ($submissions as $submission) {
                fputcsv($out, [
                    $submission->created_at->toDateTimeString(),
                    $submission->status->getLabel(),
                    $submission->page?->title,
                    ...$fields->map(function (array $field) use ($submission): string {
                        $value = $submission->data[$field['name']]['value'] ?? '';

                        return is_array($value) ? implode(', ', $value) : (string) $value;
                    })->all(),
                ]);
            }

            fclose($out);
        }, str($this->getOwnerRecord()->name)->slug().'-submissions-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }
}
