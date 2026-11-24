<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EventResource\Pages;
use App\Models\Event;
use App\Support\Notifier;
use App\Support\Status;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EventResource extends Resource
{
    protected static ?string $model = Event::class;
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';
    protected static ?string $navigationGroup = 'Leave';
    protected static ?int $navigationSort = 4;
    protected static ?string $modelLabel = 'holiday / event';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->required()->columnSpanFull(),
            Forms\Components\Select::make('type')->options(['holiday' => 'Holiday (office closed)', 'event' => 'Event'])->default('holiday')->required(),
            Forms\Components\DatePicker::make('start_date')->required()->native(false)->live(),
            Forms\Components\DatePicker::make('end_date')->required()->native(false)->afterOrEqual('start_date')->default(fn (Forms\Get $get) => $get('start_date')),
            Forms\Components\Textarea::make('description')->columnSpanFull(),
            Forms\Components\FileUpload::make('attachment')->image()->disk('public')->directory('events')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable(),
                Tables\Columns\TextColumn::make('type')->badge()->color(fn ($state) => $state === 'holiday' ? 'danger' : 'info'),
                Tables\Columns\TextColumn::make('start_date')->date('d M Y, D')->sortable(),
                Tables\Columns\TextColumn::make('end_date')->date('d M Y'),
            ])
            ->defaultSort('start_date', 'desc')
            ->filters([Tables\Filters\SelectFilter::make('type')->options(['holiday' => 'Holiday', 'event' => 'Event'])])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageEvents::route('/')];
    }
}
