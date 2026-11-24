<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Support\Status;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class TodayAttendance extends BaseWidget
{
    protected static ?int $sort = 3;
    protected int|string|array $columnSpan = 'full';
    protected static ?string $heading = "Today's attendance";

    public function table(Table $table): Table
    {
        $today = today()->toDateString();

        return $table
            ->query(User::query()->where('is_active', true)->with(['designation', 'attendances' => fn ($q) => $q->whereDate('date', $today)->orderBy('id')]))
            ->columns([
                Tables\Columns\ImageColumn::make('avatar_url')->label('')->circular()->getStateUsing(fn (User $r) => $r->avatarUrl()),
                Tables\Columns\TextColumn::make('name')->description(fn (User $r) => $r->designation?->title)->searchable(),
                Tables\Columns\TextColumn::make('today_status')->label('Status')->badge()
                    ->getStateUsing(function (User $r) use ($today) {
                        $a = $r->attendances->first();
                        if ($a) {
                            return $a->in_status === 'L' ? 'Late' : 'Present';
                        }
                        $leave = \App\Models\LeaveRequest::where('user_id', $r->id)->where('status_id', Status::APPROVED)
                            ->whereDate('leave_from', '<=', $today)->whereDate('leave_to', '>=', $today)->exists();

                        return $leave ? 'On leave' : 'Not checked in';
                    })
                    ->color(fn ($state) => match ($state) { 'Present' => 'success', 'Late' => 'danger', 'On leave' => 'info', default => 'gray' }),
                Tables\Columns\TextColumn::make('in')->label('Check in')->getStateUsing(fn (User $r) => $r->attendances->first()?->check_in?->format('h:i A'))->placeholder('—'),
                Tables\Columns\TextColumn::make('out')->label('Check out')->getStateUsing(fn (User $r) => $r->attendances->last()?->check_out?->format('h:i A'))->placeholder('—'),
                Tables\Columns\TextColumn::make('where')->label('Location')->getStateUsing(fn (User $r) => $r->attendances->first()?->check_in_location)->limit(30)->placeholder('—'),
            ])
            ->paginated([10, 25, 50]);
    }
}
