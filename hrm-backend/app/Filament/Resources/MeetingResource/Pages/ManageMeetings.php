<?php

namespace App\Filament\Resources\MeetingResource\Pages;

use App\Filament\Resources\MeetingResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageMeetings extends ManageRecords
{
    protected static string $resource = MeetingResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->after(fn ($record) => $record->attendees->each(fn ($u) => \App\Support\Notifier::send($u, 'Meeting invitation', $record->title.' on '.$record->date->format('d M'), 'meeting', auth()->id(), $record->id)))];
    }
}
