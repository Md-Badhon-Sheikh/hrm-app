<?php

namespace App\Filament\Resources\AppNotificationResource\Pages;

use App\Filament\Resources\AppNotificationResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageAppNotifications extends ManageRecords
{
    protected static string $resource = AppNotificationResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\Action::make('send')->label('Send notification')->icon('heroicon-o-paper-airplane')
                ->form([
                    \Filament\Forms\Components\TextInput::make('title')->required(),
                    \Filament\Forms\Components\Textarea::make('body')->required(),
                    \Filament\Forms\Components\Select::make('department_id')->label('Department')->options(fn () => \App\Models\Department::pluck('title', 'id'))->placeholder('Everyone'),
                    \Filament\Forms\Components\Select::make('users')->label('Or only these employees')->multiple()->searchable()->options(fn () => \App\Models\User::where('is_active', true)->pluck('name', 'id')),
                ])
                ->action(function (array $data) {
                    $q = \App\Models\User::where('is_active', true);
                    if (! empty($data['users'])) {
                        $q->whereIn('id', $data['users']);
                    } elseif (! empty($data['department_id'])) {
                        $q->where('department_id', $data['department_id']);
                    }
                    $n = 0;
                    $q->each(function ($u) use ($data, &$n) {
                        \App\Support\Notifier::send($u, $data['title'], $data['body'], 'notice', auth()->id());
                        $n++;
                    });
                    \Filament\Notifications\Notification::make()->title("Sent to $n employees")->success()->send();
                })];
    }
}
