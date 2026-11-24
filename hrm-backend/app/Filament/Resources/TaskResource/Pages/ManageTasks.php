<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageTasks extends ManageRecords
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->after(fn ($record) => $record->members->each(fn ($u) => \App\Support\Notifier::send($u, 'New task assigned', $record->title, 'task', auth()->id(), $record->id)))];
    }
}
