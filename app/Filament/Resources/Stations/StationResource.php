<?php

namespace App\Filament\Resources\Stations;

use App\Filament\Resources\Stations\Pages\CreateStation;
use App\Filament\Resources\Stations\Pages\EditStation;
use App\Filament\Resources\Stations\Pages\ListStations;
use App\Models\Station;
use App\Support\QrCode;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class StationResource extends Resource
{
    protected static ?string $model = Station::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $navigationLabel = 'Stationen';

    protected static ?string $modelLabel = 'Station';

    protected static ?string $pluralModelLabel = 'Stationen';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Station')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (string $operation, ?string $state, Set $set): void {
                            if ($operation === 'create') {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),
                    TextInput::make('slug')
                        ->label('Kurzname in der URL')
                        ->helperText('Der QR-Code zeigt auf /s/{kurzname}. Nach dem Druck des Schilds nicht mehr ändern.')
                        ->required()
                        ->alphaDash()
                        ->maxLength(64)
                        ->unique(ignoreRecord: true),
                    Textarea::make('description')->label('Beschreibung')->rows(3)->columnSpanFull(),
                    TextInput::make('lat')->label('Breitengrad')->numeric(),
                    TextInput::make('lng')->label('Längengrad')->numeric(),
                    Toggle::make('is_active')->label('Aktiv')->default(true),
                ]),
            Section::make('Bildabgleich')
                ->columns(3)
                ->schema([
                    FileUpload::make('reference_path')
                        ->label('Referenzbild')
                        ->helperText('Ein gutes, scharfes Foto aus dem Rahmen bei Tageslicht. Alle Uploads werden darauf ausgerichtet.')
                        ->image()
                        ->disk(config('fotostation.disks.private'))
                        ->visibility('private')
                        ->directory('references')
                        ->maxSize(15360)
                        ->columnSpanFull(),
                    TextInput::make('output_width')->label('Breite der Ausgabe (px)')->numeric()->default(1920)->required(),
                    TextInput::make('output_height')->label('Höhe der Ausgabe (px)')->numeric()->default(1440)->required()
                        ->helperText('Seitenverhältnis wie das Referenzbild wählen.'),
                    TextInput::make('min_inliers')->label('Mindest-Inlier')->numeric()->default(40)->required()
                        ->helperText('Weniger übereinstimmende Merkmale = automatisch abgelehnt. Mit der Stichprobe justieren.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('slug')->label('Kurzname'),
                IconColumn::make('is_active')->label('Aktiv')->boolean(),
                TextColumn::make('published_submissions_count')->label('Fotos')->counts('publishedSubmissions'),
                IconColumn::make('needs_render')->label('Zeitraffer veraltet')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('qr')
                    ->label('QR-Code')
                    ->icon(Heroicon::OutlinedQrCode)
                    ->action(fn (Station $record) => response()->streamDownload(
                        fn () => print (QrCode::svg($record->uploadUrl())),
                        "fotostation-{$record->slug}-qr.svg",
                        ['Content-Type' => 'image/svg+xml'],
                    )),
                Action::make('render')
                    ->label('Zeitraffer neu erstellen')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->modalDescription('Der Zeitraffer wird in der kommenden Nacht neu erstellt. Sofort geht es in Laravel Cloud unter „Commands“ mit: php artisan fotostation:render-changed')
                    ->action(function (Station $record): void {
                        $record->forceFill(['needs_render' => true])->save();
                        Notification::make()->title('Vorgemerkt für die nächste Nacht')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStations::route('/'),
            'create' => CreateStation::route('/create'),
            'edit' => EditStation::route('/{record}/edit'),
        ];
    }
}
