<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TaskResource\Pages;
use App\Models\Task;
use App\Support\Notifier;
use App\Support\Status;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationGroup = 'Work';
    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->required()->columnSpanFull(),
            Forms\Components\Hidden::make('created_by')->default(fn () => auth()->id()),
            Forms\Components\Select::make('supervisor_id')->label('Supervisor')->relationship('supervisor', 'name')->searchable()->preload()->default(fn () => auth()->id()),
            Forms\Components\Select::make('members')->relationship('members', 'name')->multiple()->searchable()->preload()->required(),
            Forms\Components\DatePicker::make('start_date')->required()->native(false)->default(today()),
            Forms\Components\DatePicker::make('end_date')->required()->native(false)->afterOrEqual('start_date'),
            Forms\Components\Select::make('priority_id')->label('Priority')->options([1 => 'High', 2 => 'Medium', 3 => 'Low', 4 => 'Urgent'])->default(2)->required(),
            Forms\Components\Select::make('status_id')->label('Status')->options([24 => 'Not started', 25 => 'On hold', 26 => 'In progress', 27 => 'Completed'])->default(26)->required(),
            Forms\Components\TextInput::make('progress')->numeric()->minValue(0)->maxValue(100)->suffix('%')->default(0),
            Forms\Components\Textarea::make('description')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('members.name')->badge()->limitList(2)->label('Members'),
                Tables\Columns\TextColumn::make('end_date')->date('d M Y')->label('Deadline')->sortable()
                    ->color(fn ($record) => $record->end_date->isPast() && $record->status_id != 27 ? 'danger' : null),
                Tables\Columns\TextColumn::make('priority_id')->label('Priority')->badge()->formatStateUsing(fn ($state) => Status::priorityName((int) $state))
                    ->color(fn ($state) => match ((int) $state) { 1, 4 => 'danger', 2 => 'warning', default => 'success' }),
                Tables\Columns\TextColumn::make('status_id')->label('Status')->badge()->formatStateUsing(fn ($state) => Status::name((int) $state))
                    ->color(fn ($state) => (int) $state === 27 ? 'success' : 'info'),
                Tables\Columns\TextColumn::make('progress')->suffix('%')->alignCenter(),
            ])
            ->defaultSort('end_date')
            ->filters([
                Tables\Filters\SelectFilter::make('status_id')->label('Status')->options([24 => 'Not started', 25 => 'On hold', 26 => 'In progress', 27 => 'Completed']),
                Tables\Filters\SelectFilter::make('priority_id')->label('Priority')->options([1 => 'High', 2 => 'Medium', 3 => 'Low', 4 => 'Urgent']),
            ])
            ->actions([
                Tables\Actions\Action::make('complete')->icon('heroicon-o-check-circle')->color('success')->iconButton()
                    ->visible(fn ($record) => (int) $record->status_id !== 27)
                    ->action(fn ($record) => $record->update(['status_id' => 27, 'progress' => 100])),
                Tables\Actions\EditAction::make()->iconButton(), Tables\Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageTasks::route('/')];
    }
}
