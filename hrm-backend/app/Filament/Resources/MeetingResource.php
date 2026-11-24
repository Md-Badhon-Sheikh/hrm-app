<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MeetingResource\Pages;
use App\Models\Meeting;
use App\Support\Notifier;
use App\Support\Status;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MeetingResource extends Resource
{
    protected static ?string $model = Meeting::class;
    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'Work';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->required()->columnSpanFull(),
            Forms\Components\Select::make('user_id')->label('Organizer')->relationship('user', 'name')->searchable()->preload()->required()->default(fn () => auth()->id()),
            Forms\Components\TextInput::make('location'),
            Forms\Components\DatePicker::make('date')->required()->native(false),
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TimePicker::make('start_at')->seconds(false),
                Forms\Components\TimePicker::make('end_at')->seconds(false)->after('start_at'),
            ]),
            Forms\Components\Select::make('attendees')->relationship('attendees', 'name')->multiple()->searchable()->preload()->columnSpanFull(),
            Forms\Components\Textarea::make('description')->columnSpanFull(),
            Forms\Components\FileUpload::make('attachment')->disk('public')->directory('meeting')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable(),
                Tables\Columns\TextColumn::make('date')->date('d M Y, D')->sortable(),
                Tables\Columns\TextColumn::make('start_at')->time('h:i A')->label('Time'),
                Tables\Columns\TextColumn::make('location'),
                Tables\Columns\TextColumn::make('user.name')->label('Organizer'),
                Tables\Columns\TextColumn::make('attendees_count')->counts('attendees')->label('Participants')->badge(),
            ])
            ->defaultSort('date', 'desc')
            ->actions([Tables\Actions\EditAction::make()->iconButton(), Tables\Actions\DeleteAction::make()->iconButton()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageMeetings::route('/')];
    }
}
