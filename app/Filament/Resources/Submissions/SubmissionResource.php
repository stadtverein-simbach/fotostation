<?php

namespace App\Filament\Resources\Submissions;

use App\Enums\SubmissionStatus;
use App\Filament\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Resources\Submissions\Pages\ViewSubmission;
use App\Models\Submission;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SubmissionResource extends Resource
{
    protected static ?string $model = Submission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $navigationLabel = 'Einreichungen';

    protected static ?string $modelLabel = 'Einreichung';

    protected static ?string $pluralModelLabel = 'Einreichungen';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $count = Submission::where('status', SubmissionStatus::PendingReview)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Bild')
                ->schema([
                    ViewEntry::make('preview')
                        ->hiddenLabel()
                        ->view('filament.submission-preview'),
                ])
                ->columnSpanFull(),
            Section::make('Details')
                ->columns(3)
                ->schema([
                    TextEntry::make('status')->badge(),
                    TextEntry::make('station.name')->label('Station'),
                    TextEntry::make('taken_at')->label('Aufgenommen')->dateTime('d.m.Y H:i')->placeholder('unbekannt'),
                    TextEntry::make('created_at')->label('Hochgeladen')->dateTime('d.m.Y H:i'),
                    TextEntry::make('display_name')->label('Name')->placeholder('anonym'),
                    TextEntry::make('match_inliers')->label('Bildabgleich (Inlier)')
                        ->helperText(fn (Submission $record): string => 'Schwelle der Station: '.$record->station->min_inliers),
                    TextEntry::make('coverage')->label('Abdeckung durch das Foto')
                        ->formatStateUsing(fn (?float $state): string => static::percent($state))
                        ->placeholder('–')
                        ->color(fn (Submission $record): ?string => $record->hasLowCoverage() ? 'danger' : null)
                        ->helperText(fn (Submission $record): ?string => $record->coverage === null ? null
                            : ($record->hasLowCoverage()
                                ? 'Großer Teil des Rands stammt aus dem Referenzbild (Foto weicht stark vom Rahmen ab). Prüfen, ob das Ergebnis noch gut aussieht.'
                                : 'Rest am Rand aus dem Referenzbild ergänzt.')),
                    TextEntry::make('face_boxes')->label('Erkannte Gesichter')
                        ->state(fn (Submission $record): int => count($record->face_boxes ?? [])),
                    TextEntry::make('consentVersion.version')->label('Zustimmung (Version)'),
                    TextEntry::make('consent_at')->label('Zugestimmt am')->dateTime('d.m.Y H:i'),
                    TextEntry::make('rejection_reason')->label('Ablehnungsgrund')->placeholder('–'),
                    TextEntry::make('reviewer.name')->label('Geprüft von')->placeholder('–'),
                    TextEntry::make('error')->label('Fehler')->placeholder('–')->columnSpanFull(),
                ]),
            Section::make('Kamera (EXIF)')
                ->description('Aus den Bilddaten übernommen, soweit vorhanden. Viele Browser entfernen sie beim Hochladen.')
                ->columns(3)
                ->collapsible()
                ->schema([
                    TextEntry::make('exif.model')->label('Gerät')
                        ->state(fn (Submission $record): ?string => static::device($record->exif))
                        ->placeholder('unbekannt'),
                    TextEntry::make('focal_length_35mm')->label('Brennweite (KB)')
                        ->formatStateUsing(fn (?int $state, Submission $record): string => $state === null ? '–' : $state.' mm · '.static::lensLabel($record->lensType()))
                        ->color(fn (Submission $record): ?string => in_array($record->lensType(), ['ultrawide', 'tele'], true) ? 'warning' : null)
                        ->helperText(fn (Submission $record): ?string => in_array($record->lensType(), ['ultrawide', 'tele'], true)
                            ? 'Nicht mit der Hauptkamera aufgenommen, das erklärt oft einen schlechten Bildabgleich.'
                            : null)
                        ->placeholder('–'),
                    TextEntry::make('exif.digital_zoom')->label('Digitalzoom')
                        ->formatStateUsing(fn (?float $state): string => $state === null ? '–' : number_format($state, 1, ',', '.').'×')
                        ->color(fn (?float $state): ?string => $state > 1.05 ? 'warning' : null)
                        ->placeholder('–'),
                    TextEntry::make('exif.exposure_time')->label('Belichtung')
                        ->state(fn (Submission $record): ?string => static::exposure($record->exif))
                        ->placeholder('–'),
                    TextEntry::make('exif.flash')->label('Blitz')
                        ->formatStateUsing(fn (?bool $state): string => match ($state) {
                            true => 'ausgelöst', false => 'nein', null => '–'
                        })
                        ->placeholder('–'),
                    TextEntry::make('exif.software')->label('Software')
                        ->color(fn (Submission $record): ?string => ($record->exif['modified_after_capture'] ?? false) ? 'warning' : null)
                        ->helperText(fn (Submission $record): ?string => ($record->exif['modified_after_capture'] ?? false)
                            ? 'Nach der Aufnahme geändert, möglicherweise bearbeitet.'
                            : null)
                        ->placeholder('–'),
                    TextEntry::make('gps_distance')->label('Abstand zur Station (GPS)')
                        ->formatStateUsing(fn (?int $state): string => static::distance($state))
                        ->color(fn (Submission $record): ?string => $record->takenNearStation() === false ? 'danger' : null)
                        ->helperText(fn (Submission $record): ?string => $record->takenNearStation() === false
                            ? 'Laut GPS nicht an der Station aufgenommen.'
                            : null)
                        ->placeholder('keine Position im Foto'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('review_thumb_path')
                    ->label('Vorschau')
                    ->disk(config('fotostation.disks.private'))
                    ->visibility('private')
                    ->checkFileExistence(false)
                    ->imageHeight(64),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('station.name')->label('Station')->toggleable(),
                TextColumn::make('match_inliers')->label('Inlier')->numeric()->sortable(),
                TextColumn::make('coverage')
                    ->label('Abdeckung')
                    ->formatStateUsing(fn (?float $state): string => static::percent($state))
                    ->placeholder('–')
                    ->color(fn (Submission $record): ?string => $record->hasLowCoverage() ? 'danger' : null)
                    ->icon(fn (Submission $record): ?string => $record->hasLowCoverage() ? 'heroicon-o-exclamation-triangle' : null)
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('focal_length_35mm')
                    ->label('Brennweite')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '–' : $state.' mm')
                    ->color(fn (Submission $record): ?string => in_array($record->lensType(), ['ultrawide', 'tele'], true) ? 'warning' : null)
                    ->tooltip(fn (Submission $record): ?string => $record->lensType() ? static::lensLabel($record->lensType()) : null)
                    ->placeholder('–')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('gps_distance')
                    ->label('GPS')
                    ->formatStateUsing(fn (?int $state): string => static::distance($state))
                    ->color(fn (Submission $record): ?string => $record->takenNearStation() === false ? 'danger' : 'success')
                    ->placeholder('–')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('face_boxes')
                    ->label('Gesichter')
                    ->state(fn (Submission $record): int => count($record->face_boxes ?? [])),
                TextColumn::make('taken_at')->label('Aufgenommen')->dateTime('d.m.Y H:i')->sortable()->placeholder('–'),
                TextColumn::make('created_at')->label('Hochgeladen')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(SubmissionStatus::class)
                    ->default(SubmissionStatus::PendingReview->value),
                SelectFilter::make('station')->relationship('station', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                SubmissionActions::approve(),
                SubmissionActions::reject(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    SubmissionActions::bulkApprove(),
                ]),
            ]);
    }

    private static function device(?array $exif): ?string
    {
        $make = $exif['make'] ?? null;
        $model = $exif['model'] ?? null;

        if ($model === null) {
            return $make;
        }

        // Manche Geräte wiederholen den Hersteller im Modell, dann nicht doppelt anzeigen.
        return $make !== null && ! str_contains(mb_strtolower($model), mb_strtolower($make))
            ? "{$make} {$model}"
            : $model;
    }

    private static function lensLabel(?string $type): string
    {
        return match ($type) {
            'ultrawide' => 'Ultraweitwinkel',
            'tele' => 'Tele/Zoom',
            'main' => 'Hauptkamera',
            default => 'unbekannt',
        };
    }

    private static function exposure(?array $exif): ?string
    {
        $parts = [];

        if (isset($exif['exposure_time'])) {
            $time = (float) $exif['exposure_time'];
            $parts[] = $time >= 1 ? number_format($time, 1, ',', '.').' s' : '1/'.round(1 / $time).' s';
        }
        if (isset($exif['f_number'])) {
            $parts[] = 'f/'.number_format((float) $exif['f_number'], 1, ',', '.');
        }
        if (isset($exif['iso'])) {
            $parts[] = 'ISO '.$exif['iso'];
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private static function distance(?int $meters): string
    {
        if ($meters === null) {
            return '–';
        }

        return $meters >= (int) config('fotostation.upload.gps_max_distance')
            ? 'über '.number_format($meters / 1000, 0, ',', '.').' km'
            : 'ca. '.$meters.' m';
    }

    private static function percent(?float $value): string
    {
        return $value === null ? '–' : number_format($value * 100, 0, ',', '.').' %';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubmissions::route('/'),
            'view' => ViewSubmission::route('/{record}'),
        ];
    }
}
