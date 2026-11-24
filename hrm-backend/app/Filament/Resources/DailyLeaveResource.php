<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DailyLeaveResource\Pages;
use App\Models\DailyLeave;
use App\Support\Notifier;
use App\Support\Status;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DailyLeaveResource extends Resource
{
    protected static ?string $model = DailyLeave::class;
    protected static ?string $navigationIcon = 'heroicon-o-clock';
    protected static ?string $navigationGroup = 'Leave';
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = 'daily leave';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('user_id')->label('Employee')->relationship('user', 'name')->searchable()->preload()->required(),
            Forms\Components\DatePicker::make('date')->required()->native(false)->default(today()),
            Forms\Components\Select::make('leave_type')->options(['early_leave' => 'Early leave', 'late_arrive' => 'Late arrival'])->required(),
            Forms\Components\TextInput::make('approx_time'),
            Forms\Components\Select::make('status')->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'])->default('approved'),
            Forms\Components\Textarea::make('reason')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Employee')->searchable(),
                Tables\Columns\TextColumn::make('date')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('leave_type')->label('Type')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'late_arrive' ? 'Late arrival' : 'Early leave'),
                Tables\Columns\TextColumn::make('approx_time')->label('Time'),
                Tables\Columns\TextColumn::make('reason')->limit(40),
                Tables\Columns\TextColumn::make('manager_approval')->label('Manager')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => ucfirst($state))
                    ->color(fn ($state) => match ($state) { 'approved' => 'success', 'rejected' => 'danger', default => 'warning' }),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']),
                Tables\Filters\SelectFilter::make('leave_type')->options(['early_leave' => 'Early leave', 'late_arrive' => 'Late arrival']),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')->icon('heroicon-o-check')->color('success')->requiresConfirmation()
                    ->visible(fn ($record) => $record->status === 'pending')
                    ->action(fn ($record) => static::decide($record, 'approved')),
                Tables\Actions\Action::make('reject')->icon('heroicon-o-x-mark')->color('danger')->requiresConfirmation()
                    ->visible(fn ($record) => $record->status === 'pending')
                    ->action(fn ($record) => static::decide($record, 'rejected')),
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    public static function decide($record, string $status): void
    {
        $record->update(['status' => $status, 'hr_approval' => ucfirst($status)]);
        Notifier::send($record->user_id, "Daily leave $status", 'Your request was '.$status.' by '.auth()->user()->name, 'daily-leave', auth()->id(), $record->id);
        Notification::make()->title('Request '.$status)->success()->send();
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) (DailyLeave::where('status', 'pending')->count() ?: '') ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageDailyLeaves::route('/')];
    }
}
