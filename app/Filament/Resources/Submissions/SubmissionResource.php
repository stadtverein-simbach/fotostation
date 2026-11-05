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
                    TextEntry::make('face_boxes')->label('Erkannte Gesichter')
                        ->state(fn (Submission $record): int => count($record->face_boxes ?? [])),
                    TextEntry::make('consentVersion.version')->label('Zustimmung (Version)'),
                    TextEntry::make('consent_at')->label('Zugestimmt am')->dateTime('d.m.Y H:i'),
                    TextEntry::make('rejection_reason')->label('Ablehnungsgrund')->placeholder('–'),
                    TextEntry::make('reviewer.name')->label('Geprüft von')->placeholder('–'),
                    TextEntry::make('error')->label('Fehler')->placeholder('–')->columnSpanFull(),
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

    public static function getPages(): array
    {
        return [
            'index' => ListSubmissions::route('/'),
            'view' => ViewSubmission::route('/{record}'),
        ];
    }
}
