<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ConferenceResource\Pages;
use App\Models\Conference;
use App\Support\Notifier;
use App\Support\Status;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ConferenceResource extends Resource
{
    protected static ?string $model = Conference::class;
    protected static ?string $navigationIcon = 'heroicon-o-video-camera';
    protected static ?string $navigationGroup = 'Work';
    protected static ?int $navigationSort = 4;
    protected static ?string $modelLabel = 'video conference';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->required()->columnSpanFull(),
            Forms\Components\DateTimePicker::make('start')->required()->seconds(false)->native(false),
            Forms\Components\DateTimePicker::make('end')->required()->seconds(false)->native(false)->after('start'),
            Forms\Components\Hidden::make('created_by')->default(fn () => auth()->id()),
            Forms\Components\TextInput::make('room_id')->label('Room name')->required()->default(fn () => 'room-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8)))
                ->helperText('Jitsi room (meet.jit.si/<room>) — or paste a Zoom/Meet link below.'),
            Forms\Components\TextInput::make('external_link')->url()->label('External link (optional)'),
            Forms\Components\Select::make('members')->relationship('members', 'name')->multiple()->searchable()->preload()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable(),
                Tables\Columns\TextColumn::make('start')->dateTime('d M Y, h:i A')->sortable(),
                Tables\Columns\TextColumn::make('end')->dateTime('h:i A'),
                Tables\Columns\TextColumn::make('members_count')->counts('members')->label('Members')->badge(),
            ])
            ->defaultSort('start', 'desc')
            ->actions([
                Tables\Actions\Action::make('join')->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record) => $record->external_link ?: rtrim(env('CONFERENCE_BASE_URL', 'https://meet.jit.si'), '/').'/'.$record->room_id, true),
                Tables\Actions\EditAction::make()->iconButton(), Tables\Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageConferences::route('/')];
    }
}
