<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AttendanceResource\Pages;
use App\Models\Attendance;
use App\Support\Notifier;
use App\Support\Status;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AttendanceResource extends Resource
{
    protected static ?string $model = Attendance::class;
    protected static ?string $navigationIcon = 'heroicon-o-finger-print';
    protected static ?string $navigationGroup = 'Attendance';
    protected static ?int $navigationSort = 1;
    protected static ?string $modelLabel = 'attendance';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('user_id')->label('Employee')->relationship('user', 'name')->searchable()->preload()->required(),
            Forms\Components\DatePicker::make('date')->required()->native(false)->default(today()),
            Forms\Components\DateTimePicker::make('check_in')->required()->seconds(false)->native(false),
            Forms\Components\DateTimePicker::make('check_out')->seconds(false)->native(false),
            Forms\Components\Select::make('in_status')->options(['OT' => 'On time', 'E' => 'Early', 'L' => 'Late'])->default('OT'),
            Forms\Components\Select::make('out_status')->options(['LT' => 'Left timely', 'LE' => 'Left early', 'LL' => 'Left later']),
            Forms\Components\TextInput::make('late_reason'),
            Forms\Components\TextInput::make('early_reason'),
            Forms\Components\TextInput::make('check_in_location')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Employee')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('date')->date('d M Y, D')->sortable(),
                Tables\Columns\TextColumn::make('check_in')->dateTime('h:i A')->label('In'),
                Tables\Columns\TextColumn::make('in_status')->label('In status')->badge()
                    ->formatStateUsing(fn ($state) => ['OT' => 'On time', 'E' => 'Early', 'L' => 'Late'][$state] ?? $state)
                    ->color(fn ($state) => match ($state) { 'L' => 'danger', 'E' => 'info', default => 'success' }),
                Tables\Columns\TextColumn::make('check_out')->dateTime('h:i A')->label('Out')->placeholder('Working'),
                Tables\Columns\TextColumn::make('out_status')->label('Out status')->badge()
                    ->formatStateUsing(fn ($state) => ['LT' => 'Timely', 'LE' => 'Early', 'LL' => 'Later'][$state] ?? $state)
                    ->color(fn ($state) => $state === 'LE' ? 'warning' : 'success'),
                Tables\Columns\TextColumn::make('worked')->label('Worked')
                    ->getStateUsing(fn ($record) => $record->check_out ? gmdate('H:i', (int) $record->check_out->diffInSeconds($record->check_in, true)) : null),
                Tables\Columns\TextColumn::make('late_reason')->limit(30)->toggleable(),
                Tables\Columns\TextColumn::make('check_in_location')->label('Location')->limit(30)->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('remote_mode_in')->label('Remote')->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('user')->relationship('user', 'name')->searchable()->preload()->label('Employee'),
                Tables\Filters\SelectFilter::make('in_status')->options(['OT' => 'On time', 'E' => 'Early', 'L' => 'Late']),
                Tables\Filters\Filter::make('date')->form([
                    Forms\Components\DatePicker::make('from')->native(false),
                    Forms\Components\DatePicker::make('until')->native(false),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('date', '>=', $v))
                    ->when($data['until'] ?? null, fn ($q, $v) => $q->whereDate('date', '<=', $v))),
                Tables\Filters\Filter::make('today')->query(fn (Builder $q) => $q->whereDate('date', today()))->toggle(),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageAttendances::route('/')];
    }
}
