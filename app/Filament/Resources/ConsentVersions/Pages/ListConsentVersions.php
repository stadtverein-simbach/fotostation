<?php

namespace App\Filament\Resources\ConsentVersions\Pages;

use App\Filament\Resources\ConsentVersions\ConsentVersionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListConsentVersions extends ListRecords
{
    protected static string $resource = ConsentVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Neue Version'),
        ];
    }
}
