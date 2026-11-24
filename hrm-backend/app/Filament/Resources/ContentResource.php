<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ContentResource\Pages;
use App\Models\Content;
use App\Support\Notifier;
use App\Support\Status;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContentResource extends Resource
{
    protected static ?string $model = Content::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Settings';
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = 'app page';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->required(),
            Forms\Components\Select::make('slug')->label('Page')->required()->unique(ignoreRecord: true)
                ->options(['privacy-policy' => 'Privacy policy', 'terms-of-use' => 'Terms of use', 'support-24-7' => 'Support', 'about-us' => 'About us', 'contact-us' => 'Contact us'])
                ->live()->afterStateUpdated(fn (Forms\Set $set, $state) => $set('type', $state)),
            Forms\Components\Hidden::make('type'),
            Forms\Components\RichEditor::make('content')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title'),
                Tables\Columns\TextColumn::make('slug')->badge(),
                Tables\Columns\TextColumn::make('updated_at')->since(),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageContents::route('/')];
    }
}
