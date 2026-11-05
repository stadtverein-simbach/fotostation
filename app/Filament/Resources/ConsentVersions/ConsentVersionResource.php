<?php

namespace App\Filament\Resources\ConsentVersions;

use App\Filament\Resources\ConsentVersions\Pages\CreateConsentVersion;
use App\Filament\Resources\ConsentVersions\Pages\ListConsentVersions;
use App\Models\ConsentVersion;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Zustimmungstexte sind unveränderlich: Änderungen = neue Version.
 */
class ConsentVersionResource extends Resource
{
    protected static ?string $model = ConsentVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Zustimmungstexte';

    protected static ?string $modelLabel = 'Zustimmungstext';

    protected static ?string $pluralModelLabel = 'Zustimmungstexte';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('version')->required()->unique()->maxLength(20)->placeholder('z. B. 2026-11'),
            Select::make('license')->label('Lizenz')->options(['cc0-1.0' => 'CC0 1.0'])->default('cc0-1.0')->required(),
            DateTimePicker::make('valid_from')->label('Gültig ab')->default(now())->required(),
            Textarea::make('text')->label('Text an der Checkbox')->rows(4)->required()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('valid_from', 'desc')
            ->columns([
                TextColumn::make('version'),
                TextColumn::make('valid_from')->label('Gültig ab')->dateTime('d.m.Y H:i'),
                TextColumn::make('text')->limit(100)->wrap(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConsentVersions::route('/'),
            'create' => CreateConsentVersion::route('/create'),
        ];
    }
}
