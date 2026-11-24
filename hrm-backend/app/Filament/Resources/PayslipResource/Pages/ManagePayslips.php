<?php

namespace App\Filament\Resources\PayslipResource\Pages;

use App\Filament\Resources\PayslipResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManagePayslips extends ManageRecords
{
    protected static string $resource = PayslipResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\Action::make('generate')->label('Generate month')->icon('heroicon-o-sparkles')
                ->form([\Filament\Forms\Components\TextInput::make('month')->required()->default(now()->subMonth()->format('Y-m'))->regex('/^\\d{4}-\\d{2}$/')->helperText('Uses each active employee\'s basic salary.')])
                ->action(function (array $data) {
                    \Illuminate\Support\Facades\Artisan::call('hrm:payslips', ['month' => $data['month']]);
                    \Filament\Notifications\Notification::make()->title(trim(\Illuminate\Support\Facades\Artisan::output()))->success()->send();
                }),
            Actions\CreateAction::make()];
    }
}
