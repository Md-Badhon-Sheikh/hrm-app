<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExpenseClaimResource\Pages;
use App\Models\ExpenseClaim;
use App\Support\Notifier;
use App\Support\Status;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExpenseClaimResource extends Resource
{
    protected static ?string $model = ExpenseClaim::class;
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup = 'Finance';
    protected static ?int $navigationSort = 1;
    protected static ?string $modelLabel = 'expense claim';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('invoice_number')->disabled(),
            Forms\Components\Textarea::make('remarks')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('invoice_number')->searchable(),
                Tables\Columns\TextColumn::make('user.name')->label('Employee')->searchable(),
                Tables\Columns\TextColumn::make('claim_date')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('payable_amount')->label('Claimed')->formatStateUsing(fn ($state) => \App\Support\Fmt::money($state)),
                Tables\Columns\TextColumn::make('paid_amount')->label('Paid')->formatStateUsing(fn ($state) => \App\Support\Fmt::money($state)),
                Tables\Columns\TextColumn::make('due_amount')->label('Due')->formatStateUsing(fn ($state) => \App\Support\Fmt::money($state))->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
                Tables\Columns\TextColumn::make('status_id')->label('Status')->badge()->formatStateUsing(fn ($state) => Status::name((int) $state))
                    ->color(fn ($state) => match ((int) $state) { Status::APPROVED => 'success', Status::REJECTED => 'danger', default => 'warning' }),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status_id')->label('Status')->options([Status::PENDING => 'Pending', Status::APPROVED => 'Approved', Status::REJECTED => 'Rejected']),
                Tables\Filters\Filter::make('due')->label('Has due')->query(fn (Builder $q) => $q->where('due_amount', '>', 0))->toggle(),
            ])
            ->actions([
                Tables\Actions\Action::make('items')->icon('heroicon-o-list-bullet')->iconButton()->modalSubmitAction(false)
                    ->modalHeading(fn ($record) => 'Claim '.$record->invoice_number)
                    ->modalContent(fn ($record) => new \Illuminate\Support\HtmlString(static::itemsTable($record))),
                Tables\Actions\Action::make('approve')->icon('heroicon-o-check')->color('success')->requiresConfirmation()
                    ->visible(fn ($record) => (int) $record->status_id === Status::PENDING)
                    ->action(function ($record) {
                        $record->update(['status_id' => Status::APPROVED]);
                        $record->expenses()->update(['status' => 'approved']);
                        Notifier::send($record->user_id, 'Expense claim approved', $record->invoice_number, 'expense', auth()->id(), $record->id);
                    }),
                Tables\Actions\Action::make('reject')->icon('heroicon-o-x-mark')->color('danger')->requiresConfirmation()
                    ->visible(fn ($record) => (int) $record->status_id === Status::PENDING)
                    ->action(function ($record) {
                        $record->update(['status_id' => Status::REJECTED]);
                        $record->expenses()->update(['status' => 'rejected']);
                        Notifier::send($record->user_id, 'Expense claim rejected', $record->invoice_number, 'expense', auth()->id(), $record->id);
                    }),
                Tables\Actions\Action::make('pay')->label('Record payment')->icon('heroicon-o-currency-bangladeshi')->color('primary')
                    ->visible(fn ($record) => $record->due_amount > 0 && (int) $record->status_id !== Status::REJECTED)
                    ->form(fn ($record) => [
                        Forms\Components\TextInput::make('amount')->numeric()->required()->default($record->due_amount)->maxValue($record->due_amount)->minValue(0.01),
                        Forms\Components\DatePicker::make('payment_date')->required()->default(today())->native(false),
                    ])
                    ->action(function ($record, array $data) {
                        $record->payments()->create($data);
                        $paid = $record->paid_amount + $data['amount'];
                        $record->update(['paid_amount' => $paid, 'due_amount' => max(0, $record->payable_amount - $paid), 'status_id' => Status::APPROVED]);
                        if ($record->due_amount <= 0) {
                            $record->expenses()->update(['payment' => 'paid', 'status' => 'approved']);
                        }
                        Notifier::send($record->user_id, 'Expense payment received', $data['amount'].' paid for '.$record->invoice_number, 'expense', auth()->id(), $record->id);
                        Notification::make()->title('Payment recorded')->success()->send();
                    }),
            ]);
    }

    public static function itemsTable($record): string
    {
        $rows = $record->expenses()->with('category')->get()->map(fn ($e) => '<tr><td class="py-1 pr-4">'.e($e->date->format('d M Y')).'</td><td class="pr-4">'.e($e->category?->name).'</td><td class="pr-4">'.e($e->remarks).'</td><td class="text-right">'.number_format($e->amount, 2).'</td></tr>')->implode('');
        return '<table class="w-full text-sm"><thead><tr class="text-left text-gray-500"><th>Date</th><th>Category</th><th>Remarks</th><th class="text-right">Amount</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) (ExpenseClaim::where('status_id', Status::PENDING)->count() ?: '') ?: null;
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageExpenseClaims::route('/')];
    }
}
