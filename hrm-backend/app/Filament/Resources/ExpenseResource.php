<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExpenseResource\Pages;
use App\Models\Expense;
use App\Support\Notifier;
use App\Support\Status;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;
    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';
    protected static ?string $navigationGroup = 'Finance';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('user_id')->label('Employee')->relationship('user', 'name')->searchable()->preload()->required(),
            Forms\Components\Select::make('expense_category_id')->label('Category')->relationship('category', 'name')->preload(),
            Forms\Components\DatePicker::make('date')->required()->native(false)->default(today()),
            Forms\Components\TextInput::make('amount')->numeric()->required()->prefix(fn () => \App\Models\Setting::get('currency_code', 'BDT')),
            Forms\Components\TextInput::make('approved_amount')->numeric()->prefix(fn () => \App\Models\Setting::get('currency_code', 'BDT')),
            Forms\Components\Select::make('status')->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'])->default('pending'),
            Forms\Components\Select::make('payment')->options(['unpaid' => 'Unpaid', 'paid' => 'Paid'])->default('unpaid'),
            Forms\Components\TextInput::make('reference'),
            Forms\Components\Textarea::make('remarks')->columnSpanFull(),
            Forms\Components\FileUpload::make('attachment')->disk('public')->directory('expense')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Employee')->searchable(),
                Tables\Columns\TextColumn::make('category.name')->label('Category'),
                Tables\Columns\TextColumn::make('date')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('amount')->formatStateUsing(fn ($state) => \App\Support\Fmt::money($state)),
                Tables\Columns\TextColumn::make('remarks')->limit(30),
                Tables\Columns\TextColumn::make('claim.invoice_number')->label('Claim')->placeholder('Not claimed')->toggleable(),
                Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => ucfirst($state))
                    ->color(fn ($state) => match ($state) { 'approved' => 'success', 'rejected' => 'danger', default => 'warning' }),
                Tables\Columns\TextColumn::make('payment')->badge()->formatStateUsing(fn ($state) => ucfirst($state))->color(fn ($state) => $state === 'paid' ? 'success' : 'gray'),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']),
                Tables\Filters\SelectFilter::make('payment')->options(['unpaid' => 'Unpaid', 'paid' => 'Paid']),
                Tables\Filters\SelectFilter::make('user')->relationship('user', 'name')->searchable()->preload()->label('Employee'),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')->icon('heroicon-o-check')->color('success')->iconButton()
                    ->visible(fn ($record) => $record->status === 'pending')
                    ->form(fn ($record) => [Forms\Components\TextInput::make('approved_amount')->numeric()->required()->default($record->amount)])
                    ->action(fn ($record, array $data) => $record->update(['status' => 'approved', 'approved_amount' => $data['approved_amount']])),
                Tables\Actions\Action::make('paid')->label('Mark paid')->icon('heroicon-o-banknotes')->iconButton()
                    ->visible(fn ($record) => $record->status === 'approved' && $record->payment !== 'paid')
                    ->requiresConfirmation()->action(fn ($record) => $record->update(['payment' => 'paid'])),
                Tables\Actions\Action::make('file')->icon('heroicon-o-paper-clip')->iconButton()->visible(fn ($record) => filled($record->attachment))
                    ->url(fn ($record) => \App\Support\Files::url($record->attachment), true),
                Tables\Actions\EditAction::make()->iconButton(), Tables\Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageExpenses::route('/')];
    }
}
