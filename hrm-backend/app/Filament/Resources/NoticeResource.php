<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NoticeResource\Pages;
use App\Models\Notice;
use App\Support\Notifier;
use App\Support\Status;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NoticeResource extends Resource
{
    protected static ?string $model = Notice::class;
    protected static ?string $navigationIcon = 'heroicon-o-megaphone';
    protected static ?string $navigationGroup = 'Communication';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('subject')->required()->columnSpanFull(),
            Forms\Components\Hidden::make('user_id')->default(fn () => auth()->id()),
            Forms\Components\DatePicker::make('date')->required()->native(false)->default(today()),
            Forms\Components\Select::make('department_id')->label('Department')->options(fn () => \App\Models\Department::pluck('title', 'id'))->placeholder('All employees'),
            Forms\Components\Textarea::make('description')->rows(5)->columnSpanFull(),
            Forms\Components\FileUpload::make('file')->disk('public')->directory('notice')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('subject')->searchable()->limit(50)->description(fn ($record) => \Illuminate\Support\Str::limit($record->description, 70)),
                Tables\Columns\TextColumn::make('date')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('department_id')->label('For')
                    ->formatStateUsing(fn ($state) => \App\Models\Department::find($state)?->title)->placeholder('All employees'),
                Tables\Columns\TextColumn::make('user.name')->label('By'),
            ])
            ->defaultSort('date', 'desc')
            ->actions([Tables\Actions\EditAction::make()->iconButton(), Tables\Actions\DeleteAction::make()->iconButton()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageNotices::route('/')];
    }
}
