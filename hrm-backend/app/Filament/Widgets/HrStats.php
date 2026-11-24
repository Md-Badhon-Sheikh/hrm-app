<?php

namespace App\Filament\Widgets;

use App\Models\Attendance;
use App\Models\DailyLeave;
use App\Models\ExpenseClaim;
use App\Models\LeaveRequest;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Status;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class HrStats extends BaseWidget
{
    protected static ?int $sort = 1;
    protected static ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $active = User::where('is_active', true)->count();
        $present = Attendance::whereDate('date', today())->distinct('user_id')->count('user_id');
        $late = Attendance::whereDate('date', today())->where('in_status', 'L')->distinct('user_id')->count('user_id');
        $onLeave = LeaveRequest::where('status_id', Status::APPROVED)->whereDate('leave_from', '<=', today())->whereDate('leave_to', '>=', today())->distinct('user_id')->count('user_id');
        $pending = LeaveRequest::where('status_id', Status::PENDING)->count() + DailyLeave::where('status', 'pending')->count();
        $claims = ExpenseClaim::where('status_id', Status::PENDING)->count();

        $trend = collect(range(6, 0))->map(fn ($d) => Attendance::whereDate('date', today()->subDays($d))->distinct('user_id')->count('user_id'))->all();

        return [
            Stat::make('Present today', "$present / $active")->description($active ? round($present / $active * 100).'% attendance' : '')
                ->descriptionIcon('heroicon-m-finger-print')->chart($trend)->color('success')
                ->url(route('filament.admin.resources.attendances.index', ['tableFilters[today][isActive]' => true])),
            Stat::make('Late today', $late)->descriptionIcon('heroicon-m-clock')->description('Checked in after grace time')->color($late ? 'danger' : 'gray'),
            Stat::make('On leave today', $onLeave)->descriptionIcon('heroicon-m-calendar-days')->color('info'),
            Stat::make('Absent today', max(0, $active - $present - $onLeave))->descriptionIcon('heroicon-m-user-minus')->description('Not checked in, not on leave')->color('warning'),
            Stat::make('Pending leave requests', $pending)->descriptionIcon('heroicon-m-inbox-arrow-down')->color($pending ? 'warning' : 'gray')
                ->url(route('filament.admin.resources.leave-requests.index')),
            Stat::make('Expense claims to review', $claims)->description(SupportTicket::where('type', 'open')->count().' open support tickets')
                ->descriptionIcon('heroicon-m-banknotes')->color($claims ? 'warning' : 'gray')
                ->url(route('filament.admin.resources.expense-claims.index')),
        ];
    }
}
