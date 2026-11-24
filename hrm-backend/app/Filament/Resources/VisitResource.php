<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VisitResource\Pages;
use App\Models\Visit;
use App\Support\Notifier;
use App\Support\Status;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VisitResource extends Resource
{
    protected static ?string $model = Visit::class;
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationGroup = 'Work';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('user_id')->label('Assign to')->relationship('user', 'name')->searchable()->preload()->required(),
            Forms\Components\DatePicker::make('date')->required()->native(false)->default(today()),
            Forms\Components\TextInput::make('title')->required()->columnSpanFull(),
            Forms\Components\Textarea::make('description')->columnSpanFull(),
            Forms\Components\Select::make('status')->options(['created' => 'Created', 'started' => 'Started', 'reached' => 'Reached', 'completed' => 'Completed', 'cancelled' => 'Cancelled'])->default('created')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('user.name')->label('Employee')->searchable(),
                Tables\Columns\TextColumn::make('date')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => ucfirst($state))
                    ->color(fn ($state) => match ($state) { 'completed' => 'success', 'cancelled' => 'danger', 'created' => 'warning', default => 'info' }),
                Tables\Columns\TextColumn::make('started_at')->dateTime('h:i A')->label('Started')->placeholder('—'),
                Tables\Columns\TextColumn::make('reached_at')->dateTime('h:i A')->label('Reached')->placeholder('—'),
                Tables\Columns\TextColumn::make('images_count')->counts('images')->label('Photos')->toggleable(),
                Tables\Columns\TextColumn::make('notes_count')->counts('notes')->label('Notes')->toggleable(),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['created' => 'Created', 'started' => 'Started', 'reached' => 'Reached', 'completed' => 'Completed', 'cancelled' => 'Cancelled']),
                Tables\Filters\SelectFilter::make('user')->relationship('user', 'name')->searchable()->preload()->label('Employee'),
            ])
            ->actions([Tables\Actions\EditAction::make()->iconButton(), Tables\Actions\DeleteAction::make()->iconButton()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageVisits::route('/')];
    }
}
