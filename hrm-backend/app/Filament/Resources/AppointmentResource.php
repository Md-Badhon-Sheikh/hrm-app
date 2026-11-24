<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AppointmentResource\Pages;
use App\Models\Appointment;
use App\Support\Notifier;
use App\Support\Status;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AppointmentResource extends Resource
{
    protected static ?string $model = Appointment::class;
    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationGroup = 'Work';
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->required()->columnSpanFull(),
            Forms\Components\Select::make('created_by')->label('Requested by')->relationship('creator', 'name')->searchable()->preload()->required(),
            Forms\Components\Select::make('appoinment_with')->label('With')->relationship('withUser', 'name')->searchable()->preload()->required(),
            Forms\Components\DatePicker::make('date')->required()->native(false),
            Forms\Components\TextInput::make('location'),
            Forms\Components\TimePicker::make('start_at')->seconds(false),
            Forms\Components\TimePicker::make('end_at')->seconds(false),
            Forms\Components\Textarea::make('description')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable(),
                Tables\Columns\TextColumn::make('creator.name')->label('Requested by'),
                Tables\Columns\TextColumn::make('withUser.name')->label('With'),
                Tables\Columns\TextColumn::make('date')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('start_at')->time('h:i A')->label('Time'),
                Tables\Columns\TextColumn::make('location'),
            ])
            ->defaultSort('date', 'desc')
            ->actions([Tables\Actions\EditAction::make()->iconButton(), Tables\Actions\DeleteAction::make()->iconButton()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageAppointments::route('/')];
    }
}
