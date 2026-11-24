<?php

namespace App\Filament\Resources\LiveLocationResource\Pages;

use App\Filament\Resources\LiveLocationResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageLiveLocations extends ManageRecords
{
    protected static string $resource = LiveLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
