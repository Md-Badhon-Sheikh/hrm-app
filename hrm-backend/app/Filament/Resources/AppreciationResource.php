<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AppreciationResource\Pages;
use App\Models\Appreciation;
use App\Support\Notifier;
use App\Support\Status;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AppreciationResource extends Resource
{
    protected static ?string $model = Appreciation::class;
    protected static ?string $navigationIcon = 'heroicon-o-heart';
    protected static ?string $navigationGroup = 'Employees';
    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('user_id')->label('To')->relationship('user', 'name')->searchable()->preload()->required(),
            Forms\Components\Select::make('appreciate_by')->label('From')->relationship('by', 'name')->searchable()->preload()->required()->default(fn () => auth()->id()),
            Forms\Components\Textarea::make('message')->required()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('To')->searchable(),
                Tables\Columns\TextColumn::make('by.name')->label('From'),
                Tables\Columns\TextColumn::make('message')->limit(60)->wrap(),
                Tables\Columns\TextColumn::make('created_at')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([Tables\Actions\DeleteAction::make()]);
    }

    public static function getModelLabel(): string
    {
        return 'appreciation';
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageAppreciations::route('/')];
    }
}
