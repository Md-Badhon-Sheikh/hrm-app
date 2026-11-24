<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LiveLocationResource\Pages;
use App\Models\LiveLocation;
use App\Support\Notifier;
use App\Support\Status;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LiveLocationResource extends Resource
{
    protected static ?string $model = LiveLocation::class;
    protected static ?string $navigationIcon = 'heroicon-o-map-pin';
    protected static ?string $navigationGroup = 'Attendance';
    protected static ?int $navigationSort = 3;
    protected static ?string $modelLabel = 'live location';

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
                Tables\Columns\TextColumn::make('user.name')->label('Employee')->searchable(),
                Tables\Columns\TextColumn::make('recorded_at')->dateTime('d M Y, h:i A')->sortable(),
                Tables\Columns\TextColumn::make('address')->limit(40)->placeholder('—'),
                Tables\Columns\TextColumn::make('map')->label('Map')->getStateUsing(fn () => 'Open map')
                    ->url(fn ($record) => "https://www.google.com/maps?q={$record->latitude},{$record->longitude}", true)->color('primary'),
            ])
            ->defaultSort('recorded_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('user')->relationship('user', 'name')->searchable()->preload()->label('Employee'),
                Tables\Filters\Filter::make('today')->query(fn (Builder $q) => $q->whereDate('recorded_at', today()))->toggle()->default(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageLiveLocations::route('/')];
    }
}
