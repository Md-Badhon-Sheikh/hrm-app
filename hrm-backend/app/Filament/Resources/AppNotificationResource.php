<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AppNotificationResource\Pages;
use App\Models\AppNotification;
use App\Support\Notifier;
use App\Support\Status;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AppNotificationResource extends Resource
{
    protected static ?string $model = AppNotification::class;
    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';
    protected static ?string $navigationGroup = 'Communication';
    protected static ?int $navigationSort = 3;
    protected static ?string $modelLabel = 'notification';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([

        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable()->description(fn ($record) => \Illuminate\Support\Str::limit($record->body, 70)),
                Tables\Columns\TextColumn::make('user_id')->label('To')->formatStateUsing(fn ($state) => \App\Models\User::find($state)?->name),
                Tables\Columns\TextColumn::make('slug')->label('Type')->badge(),
                Tables\Columns\IconColumn::make('read_at')->label('Read')->boolean()->getStateUsing(fn ($record) => (bool) $record->read_at),
                Tables\Columns\TextColumn::make('created_at')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([Tables\Actions\DeleteAction::make()->iconButton()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageAppNotifications::route('/')];
    }
}
