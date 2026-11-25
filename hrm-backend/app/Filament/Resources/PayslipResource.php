<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PayslipResource\Pages;
use App\Models\Payslip;
use App\Support\Notifier;
use App\Support\Status;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PayslipResource extends Resource
{
    protected static ?string $model = Payslip::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-currency-bangladeshi';
    protected static ?string $navigationGroup = 'Finance';
    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('user_id')->label('Employee')->relationship('user', 'name')->searchable()->preload()->required(),
            Forms\Components\TextInput::make('month')->placeholder('2026-11')->regex('/^\\d{4}-\\d{2}$/')->required(),
            Forms\Components\TextInput::make('salary')->label('Net salary')->numeric()->required()->prefix(fn () => \App\Models\Setting::get('currency_code', 'BDT')),
            Forms\Components\Toggle::make('is_calculated')->label('Visible in app')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Employee')->searchable(),
                Tables\Columns\TextColumn::make('month')->sortable()->formatStateUsing(fn ($state) => \Carbon\Carbon::createFromFormat('Y-m', $state)->format('F Y')),
                Tables\Columns\TextColumn::make('salary')->formatStateUsing(fn ($state) => \App\Support\Fmt::money($state))->sortable(),
                Tables\Columns\IconColumn::make('is_calculated')->label('In app')->boolean(),
            ])
            ->defaultSort('month', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('month')->options(fn () => Payslip::query()->distinct()->orderByDesc('month')->pluck('month', 'month')->all()),
                Tables\Filters\SelectFilter::make('user')->relationship('user', 'name')->searchable()->preload()->label('Employee'),
            ])
            ->actions([
                Tables\Actions\Action::make('pdf')->icon('heroicon-o-arrow-down-tray')->label('PDF')
                    ->url(fn ($record) => \Illuminate\Support\Facades\URL::temporarySignedRoute('payslip.pdf', now()->addHour(), ['payslip' => $record->id]), true),
                Tables\Actions\EditAction::make()->iconButton(), Tables\Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManagePayslips::route('/')];
    }
}
