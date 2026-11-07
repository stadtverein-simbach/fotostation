<?php

namespace App\Filament\Resources\Submissions;

use App\Enums\SubmissionStatus;
use App\Jobs\PublishSubmission;
use App\Models\Submission;
use App\Services\PhotoStorage;
use App\Support\Pipeline;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * Moderationsaktionen, gemeinsam genutzt von Tabelle und Detailseite.
 */
class SubmissionActions
{
    public const REJECTION_REASONS = [
        'Personen erkennbar' => 'Personen erkennbar',
        'Nicht von der Station' => 'Nicht von der Station',
        'Schlechte Qualität' => 'Schlechte Qualität (unscharf, dunkel, verdeckt)',
        'Unangemessener Inhalt' => 'Unangemessener Inhalt',
        'Duplikat' => 'Duplikat',
    ];

    public static function approve(): Action
    {
        return Action::make('approve')
            ->label('Freigeben')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Submission $record): bool => $record->status === SubmissionStatus::PendingReview)
            ->modalHeading('Foto freigeben')
            ->modalDescription(fn (): string => (Pipeline::workerEnabled()
                ? 'Erkannte Gesichter werden automatisch verpixelt. Weitere Bereiche (z. B. Kennzeichen) hier'
                : 'Keine automatische Gesichtserkennung aktiv: Gesichter, Kennzeichen usw. bitte selbst prüfen und hier')
                .' in Prozent des Bildes ergänzen: x/y = linke obere Ecke, b/h = Breite/Höhe.')
            ->fillForm(fn (Submission $record): array => ['manual_boxes' => $record->manual_boxes ?? []])
            ->schema([
                Repeater::make('manual_boxes')
                    ->label('Zusätzlich verpixeln')
                    ->schema([
                        TextInput::make('x')->label('x %')->numeric()->minValue(0)->maxValue(100)->required(),
                        TextInput::make('y')->label('y %')->numeric()->minValue(0)->maxValue(100)->required(),
                        TextInput::make('w')->label('b %')->numeric()->minValue(1)->maxValue(100)->required(),
                        TextInput::make('h')->label('h %')->numeric()->minValue(1)->maxValue(100)->required(),
                    ])
                    ->columns(4)
                    ->defaultItems(0)
                    ->reorderable(false)
                    ->addActionLabel('Bereich hinzufügen'),
            ])
            ->action(function (Submission $record, array $data): void {
                static::markApproved($record, $data['manual_boxes'] ?? []);

                Notification::make()
                    ->title('Freigegeben, wird veröffentlicht')
                    ->success()
                    ->send();
            });
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label('Ablehnen')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Submission $record): bool => in_array($record->status, [
                SubmissionStatus::PendingReview,
                SubmissionStatus::AutoRejected,
                SubmissionStatus::Failed,
            ], true))
            ->modalHeading('Foto ablehnen')
            ->modalDescription('Die Dateien werden sofort gelöscht.')
            ->schema([
                Select::make('reason')
                    ->label('Grund')
                    ->options(self::REJECTION_REASONS)
                    ->required(),
            ])
            ->action(function (Submission $record, array $data): void {
                static::markRejected($record, $data['reason']);

                Notification::make()->title('Abgelehnt und gelöscht')->success()->send();
            });
    }

    /**
     * Automatisch abgelehnte Fotos (Stichprobe) doch in die Freigabe holen.
     */
    public static function rescue(): Action
    {
        return Action::make('rescue')
            ->label('Zur Freigabe holen')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->visible(fn (Submission $record): bool => Pipeline::workerEnabled()
                && $record->status === SubmissionStatus::AutoRejected && filled($record->homography))
            ->requiresConfirmation()
            ->modalDescription('Das Foto wird trotz schwachem Bildabgleich ausgerichtet und kommt in die Freigabe. Ist die Ausrichtung schlecht, die Schwelle der Station anpassen.')
            ->action(function (Submission $record): void {
                $record->transitionTo(SubmissionStatus::Processing, ['rejection_reason' => null]);

                Pipeline::dispatchFromAlignment($record);

                Notification::make()->title('Wird neu verarbeitet')->success()->send();
            });
    }

    /**
     * Technisch fehlgeschlagene Verarbeitung oder Veröffentlichung neu anstoßen.
     */
    public static function retry(): Action
    {
        return Action::make('retry')
            ->label('Erneut verarbeiten')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->visible(fn (Submission $record): bool => $record->status === SubmissionStatus::Failed
                || ($record->status === SubmissionStatus::Approved && $record->reviewed_at?->lt(now()->subMinutes(10))))
            ->requiresConfirmation()
            ->action(function (Submission $record): void {
                if ($record->status === SubmissionStatus::Approved) {
                    PublishSubmission::dispatch($record)->onQueue(config('fotostation.queue'));
                } else {
                    $record->transitionTo(SubmissionStatus::Received, ['error' => null]);

                    Pipeline::dispatch($record);
                }

                Notification::make()->title('Neu eingereiht')->success()->send();
            });
    }

    /**
     * Unbearbeitetes Original (privat, unverpixelt) über eine kurzlebige URL öffnen.
     */
    public static function openOriginal(): Action
    {
        return Action::make('openOriginal')
            ->label('Original')
            ->icon('heroicon-o-photo')
            ->color('gray')
            // Ältere Einreichungen haben nur die neu kodierte Arbeitsfassung.
            ->visible(fn (Submission $record): bool => filled($record->source_path ?? $record->original_path))
            ->url(fn (Submission $record): string => app(PhotoStorage::class)->readUrl($record->source_path ?? $record->original_path), shouldOpenInNewTab: true);
    }

    /**
     * Veröffentlichtes Foto wieder entfernen (z. B. auf eine Meldung hin).
     */
    public static function unpublish(): Action
    {
        return Action::make('unpublish')
            ->label('Entfernen')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Submission $record): bool => $record->status === SubmissionStatus::Published)
            ->schema([
                TextInput::make('reason')->label('Grund')->required()->maxLength(255),
            ])
            ->action(function (Submission $record, array $data): void {
                static::markRemoved($record, $data['reason']);

                Notification::make()->title('Entfernt, Zeitraffer wird heute Nacht neu erstellt')->success()->send();
            });
    }

    /**
     * Mehrere Fotos ohne erkannte Gesichter auf einmal freigeben.
     */
    public static function bulkApprove(): BulkAction
    {
        return BulkAction::make('bulkApprove')
            ->label('Freigeben (ohne Gesichter)')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            // Ohne Gesichtserkennung wäre „keine Gesichter“ nicht geprüft.
            ->visible(fn (): bool => Pipeline::workerEnabled())
            ->requiresConfirmation()
            ->modalDescription('Freigegeben werden nur Fotos, die auf Freigabe warten, in denen keine Gesichter erkannt wurden und deren Abdeckung ausreicht (sonst einzeln prüfen).')
            ->action(function (Collection $records): void {
                $count = static::approveUnproblematic($records);

                Notification::make()->title("{$count} Fotos freigegeben")->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Sammelfreigabe: nur wartende Fotos ohne erkannte Gesichter und mit
     * ausreichender Abdeckung. Gibt die Anzahl freigegebener Fotos zurück.
     *
     * @param  iterable<Submission>  $records
     */
    public static function approveUnproblematic(iterable $records): int
    {
        $count = 0;

        foreach ($records as $record) {
            if ($record->status === SubmissionStatus::PendingReview
                && empty($record->face_boxes)
                && ! $record->hasLowCoverage()) {
                static::markApproved($record, []);
                $count++;
            }
        }

        return $count;
    }

    public static function markApproved(Submission $record, array $manualBoxes): void
    {
        $record->transitionTo(SubmissionStatus::Approved, [
            'manual_boxes' => array_values($manualBoxes),
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        PublishSubmission::dispatch($record)->onQueue(config('fotostation.queue'));
    }

    public static function markRejected(Submission $record, string $reason): void
    {
        $record->deleteAllFiles();

        $record->transitionTo(SubmissionStatus::Rejected, [
            'rejection_reason' => $reason,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);
    }

    public static function markRemoved(Submission $record, string $reason): void
    {
        static::markRejected($record, $reason);

        $record->station->forceFill(['needs_render' => true])->save();
    }
}
