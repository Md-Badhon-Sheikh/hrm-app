<?php

namespace App\Filament\Resources\VisitResource\Pages;

use App\Filament\Resources\VisitResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageVisits extends ManageRecords
{
    protected static string $resource = VisitResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->after(fn ($record) => \App\Support\Notifier::send($record->user_id, 'New visit assigned', $record->title, 'visit', auth()->id(), $record->id))];
    }
}
