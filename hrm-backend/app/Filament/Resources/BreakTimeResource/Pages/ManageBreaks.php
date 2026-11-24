<?php

namespace App\Filament\Resources\BreakTimeResource\Pages;

use App\Filament\Resources\BreakTimeResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageBreaks extends ManageRecords
{
    protected static string $resource = BreakTimeResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
