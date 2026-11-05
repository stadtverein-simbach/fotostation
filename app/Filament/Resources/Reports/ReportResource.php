<?php

namespace App\Filament\Resources\Reports;

use App\Enums\ReportStatus;
use App\Enums\SubmissionStatus;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Submissions\SubmissionActions;
use App\Filament\Resources\Submissions\SubmissionResource;
use App\Models\Report;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Meldungen nach Art. 16 DSA.
 */
class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $navigationLabel = 'Meldungen';

    protected static ?string $modelLabel = 'Meldung';

    protected static ?string $pluralModelLabel = 'Meldungen';

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $count = Report::where('status', ReportStatus::Open)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('submission.thumb_path')
                    ->label('Foto')
                    ->disk(config('fotostation.disks.public'))
                    ->checkFileExistence(false)
                    ->imageHeight(56),
                TextColumn::make('reason')->label('Grund')->limit(80)->wrap(),
                TextColumn::make('contact')->label('Kontakt')->placeholder('–'),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->label('Eingegangen')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(ReportStatus::class)->default(ReportStatus::Open->value),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Foto ansehen')
                    ->url(fn (Report $record): string => SubmissionResource::getUrl('view', ['record' => $record->submission_id])),
                Action::make('remove')
                    ->label('Foto entfernen')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Report $record): bool => $record->status === ReportStatus::Open)
                    ->action(function (Report $record): void {
                        $submission = $record->submission;

                        if ($submission->status === SubmissionStatus::Published) {
                            SubmissionActions::markRemoved($submission, 'Auf Meldung entfernt: '.str($record->reason)->limit(200));
                        }

                        $record->submission->reports()
                            ->where('status', ReportStatus::Open)
                            ->update(['status' => ReportStatus::Removed, 'handled_by' => auth()->id(), 'handled_at' => now()]);

                        Notification::make()->title('Foto entfernt')->success()->send();
                    }),
                Action::make('dismiss')
                    ->label('Zurückweisen')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (Report $record): bool => $record->status === ReportStatus::Open)
                    ->action(function (Report $record): void {
                        $record->forceFill([
                            'status' => ReportStatus::Dismissed,
                            'handled_by' => auth()->id(),
                            'handled_at' => now(),
                        ])->save();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReports::route('/'),
        ];
    }
}
