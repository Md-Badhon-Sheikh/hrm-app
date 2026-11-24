<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupportTicketResource\Pages;
use App\Models\SupportTicket;
use App\Support\Notifier;
use App\Support\Status;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SupportTicketResource extends Resource
{
    protected static ?string $model = SupportTicket::class;
    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';
    protected static ?string $navigationGroup = 'Communication';
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = 'support ticket';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('subject')->disabled()->columnSpanFull(),
            Forms\Components\Select::make('priority_id')->label('Priority')->options([1 => 'High', 2 => 'Medium', 3 => 'Low', 4 => 'Urgent']),
            Forms\Components\Select::make('type')->label('Status')->options(['open' => 'Open', 'close' => 'Closed']),
            Forms\Components\Textarea::make('description')->disabled()->rows(5)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->searchable(),
                Tables\Columns\TextColumn::make('subject')->searchable()->limit(40)->description(fn ($record) => \Illuminate\Support\Str::limit($record->description, 60)),
                Tables\Columns\TextColumn::make('user.name')->label('Employee'),
                Tables\Columns\TextColumn::make('priority_id')->label('Priority')->badge()->formatStateUsing(fn ($state) => Status::priorityName((int) $state))
                    ->color(fn ($state) => match ((int) $state) { 1, 4 => 'danger', 2 => 'warning', default => 'success' }),
                Tables\Columns\TextColumn::make('type')->label('Status')->badge()->formatStateUsing(fn ($state) => $state === 'open' ? 'Open' : 'Closed')
                    ->color(fn ($state) => $state === 'open' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('created_at')->since()->label('Opened')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([Tables\Filters\SelectFilter::make('type')->label('Status')->options(['open' => 'Open', 'close' => 'Closed'])->default('open')])
            ->actions([
                Tables\Actions\Action::make('file')->icon('heroicon-o-paper-clip')->iconButton()->visible(fn ($record) => filled($record->file))
                    ->url(fn ($record) => \App\Support\Files::url($record->file), true),
                Tables\Actions\Action::make('close')->icon('heroicon-o-check-badge')->color('success')->requiresConfirmation()
                    ->visible(fn ($record) => $record->type === 'open')
                    ->action(function ($record) {
                        $record->update(['type' => 'close']);
                        Notifier::send($record->user_id, 'Support ticket closed', $record->subject, 'support', auth()->id(), $record->id);
                    }),
                Tables\Actions\EditAction::make()->iconButton(),
            ]);
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) (SupportTicket::where('type', 'open')->count() ?: '') ?: null;
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageSupportTickets::route('/')];
    }
}
