<?php

namespace App\Filament\Resources\ExpenseClaimResource\Pages;

use App\Filament\Resources\ExpenseClaimResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageExpenseClaims extends ManageRecords
{
    protected static string $resource = ExpenseClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
