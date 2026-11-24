<?php

namespace App\Filament\Resources\NoticeResource\Pages;

use App\Filament\Resources\NoticeResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageNotices extends ManageRecords
{
    protected static string $resource = NoticeResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Publish notice')->after(function ($record) {
                \App\Models\User::where('is_active', true)->when($record->department_id, fn ($q) => $q->where('department_id', $record->department_id))
                    ->where('id', '!=', auth()->id())
                    ->each(fn ($u) => \App\Support\Notifier::send($u, 'New notice', $record->subject, 'notice', auth()->id(), $record->id));
            })];
    }
}
