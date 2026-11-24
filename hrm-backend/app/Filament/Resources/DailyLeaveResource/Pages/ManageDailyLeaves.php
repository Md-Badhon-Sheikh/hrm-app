<?php

namespace App\Filament\Resources\DailyLeaveResource\Pages;

use App\Filament\Resources\DailyLeaveResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageDailyLeaves extends ManageRecords
{
    protected static string $resource = DailyLeaveResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
