<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BreakTimeResource\Pages;
use App\Models\BreakTime;
use App\Support\Notifier;
use App\Support\Status;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BreakTimeResource extends Resource
{
    protected static ?string $model = BreakTime::class;
    protected static ?string $navigationIcon = 'heroicon-o-pause-circle';
    protected static ?string $navigationGroup = 'Attendance';
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = 'break';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('user_id')->label('Employee')->relationship('user', 'name')->searchable()->preload()->required(),
            Forms\Components\DatePicker::make('date')->required()->native(false),
            Forms\Components\TimePicker::make('break_time')->required()->native(false),
            Forms\Components\TimePicker::make('back_time')->native(false),
            Forms\Components\TextInput::make('reason')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Employee')->searchable(),
                Tables\Columns\TextColumn::make('date')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('break_time')->time('h:i A')->label('Start'),
                Tables\Columns\TextColumn::make('back_time')->time('h:i A')->label('Back')->placeholder('On break'),
                Tables\Columns\TextColumn::make('reason')->limit(40),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('user')->relationship('user', 'name')->searchable()->preload()->label('Employee'),
                Tables\Filters\Filter::make('today')->query(fn (Builder $q) => $q->whereDate('date', today()))->toggle(),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageBreaks::route('/')];
    }
}
