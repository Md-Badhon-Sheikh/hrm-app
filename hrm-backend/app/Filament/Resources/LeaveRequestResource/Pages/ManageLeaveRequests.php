<?php

namespace App\Filament\Resources\LeaveRequestResource\Pages;

use App\Filament\Resources\LeaveRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageLeaveRequests extends ManageRecords
{
    protected static string $resource = LeaveRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->mutateFormDataUsing(fn (array $data) => LeaveRequestResource::mutateDays($data + ['approved_by' => auth()->id()]))];
    }
}
