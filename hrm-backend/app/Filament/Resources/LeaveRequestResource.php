<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LeaveRequestResource\Pages;
use App\Models\LeaveRequest;
use App\Support\Notifier;
use App\Support\Status;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeaveRequestResource extends Resource
{
    protected static ?string $model = LeaveRequest::class;
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationGroup = 'Leave';
    protected static ?int $navigationSort = 1;
    protected static ?string $modelLabel = 'leave request';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('user_id')->label('Employee')->relationship('user', 'name')->searchable()->preload()->required(),
            Forms\Components\Select::make('leave_type_id')->label('Leave type')->relationship('leaveType', 'name')->preload()->required(),
            Forms\Components\DatePicker::make('leave_from')->required()->native(false)->live(),
            Forms\Components\DatePicker::make('leave_to')->required()->native(false)->afterOrEqual('leave_from'),
            Forms\Components\Select::make('substitute_id')->label('Substitute')->relationship('substitute', 'name')->searchable()->preload(),
            Forms\Components\Select::make('status_id')->label('Status')->options([Status::PENDING => 'Pending', Status::APPROVED => 'Approved', Status::REJECTED => 'Rejected', Status::CANCELLED => 'Cancelled'])->default(Status::APPROVED)->required(),
            Forms\Components\Textarea::make('reason')->columnSpanFull(),
            Forms\Components\FileUpload::make('attachment')->disk('public')->directory('leave')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Employee')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('leaveType.name')->label('Type')->badge()->color('info'),
                Tables\Columns\TextColumn::make('leave_from')->label('Period')->date('d M')
                    ->description(fn ($record) => 'to '.$record->leave_to->format('d M Y'))->sortable(),
                Tables\Columns\TextColumn::make('days')->alignCenter(),
                Tables\Columns\TextColumn::make('reason')->limit(35)->tooltip(fn ($record) => $record->reason),
                Tables\Columns\TextColumn::make('status_id')->label('Status')->badge()
                    ->formatStateUsing(fn ($state) => Status::name((int) $state))
                    ->color(fn ($state) => match ((int) $state) { Status::APPROVED => 'success', Status::PENDING => 'warning', Status::REJECTED => 'danger', default => 'gray' }),
                Tables\Columns\TextColumn::make('approver.name')->label('Approved by')->toggleable(),
                Tables\Columns\TextColumn::make('apply_date')->date('d M Y')->label('Applied')->sortable()->toggleable(),
                Tables\Columns\IconColumn::make('attachment')->boolean()->label('File')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status_id')->label('Status')->options([Status::PENDING => 'Pending', Status::APPROVED => 'Approved', Status::REJECTED => 'Rejected', Status::CANCELLED => 'Cancelled']),
                Tables\Filters\SelectFilter::make('leave_type_id')->label('Type')->relationship('leaveType', 'name'),
                Tables\Filters\SelectFilter::make('user')->relationship('user', 'name')->searchable()->preload()->label('Employee'),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')->icon('heroicon-o-check')->color('success')->requiresConfirmation()
                    ->visible(fn ($record) => (int) $record->status_id === Status::PENDING)
                    ->action(fn ($record) => static::decide($record, Status::APPROVED)),
                Tables\Actions\Action::make('reject')->icon('heroicon-o-x-mark')->color('danger')->requiresConfirmation()
                    ->visible(fn ($record) => (int) $record->status_id === Status::PENDING)
                    ->action(fn ($record) => static::decide($record, Status::REJECTED)),
                Tables\Actions\Action::make('file')->icon('heroicon-o-paper-clip')->visible(fn ($record) => filled($record->attachment))
                    ->url(fn ($record) => \App\Support\Files::url($record->attachment), true),
                Tables\Actions\EditAction::make()->iconButton()->mutateFormDataUsing(fn (array $data) => static::mutateDays($data)),
                Tables\Actions\DeleteAction::make()->iconButton(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('approve')->icon('heroicon-o-check')->color('success')->requiresConfirmation()
                    ->action(fn ($records) => $records->where('status_id', Status::PENDING)->each(fn ($r) => static::decide($r, Status::APPROVED, false))),
            ]);
    }

    public static function decide($record, int $status, bool $toast = true): void
    {
        $record->update(['status_id' => $status, 'approved_by' => auth()->id()]);
        $word = $status === Status::APPROVED ? 'approved' : 'rejected';
        Notifier::send($record->user_id, "Leave $word", 'Your leave request has been '.$word.' by '.auth()->user()->name, 'leave', auth()->id(), $record->id);
        if ($toast) {
            Notification::make()->title("Leave $word")->success()->send();
        }
    }

    public static function mutateDays(array $data): array
    {
        $data['days'] = \Carbon\Carbon::parse($data['leave_from'])->diffInDays(\Carbon\Carbon::parse($data['leave_to'])) + 1;
        $data['apply_date'] ??= today()->toDateString();
        return $data;
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) (LeaveRequest::where('status_id', Status::PENDING)->count() ?: '') ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageLeaveRequests::route('/')];
    }
}
