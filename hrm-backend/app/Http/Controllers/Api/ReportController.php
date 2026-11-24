<?php

namespace App\Http\Controllers\Api;

use App\Models\Attendance;
use App\Models\BreakTime;
use App\Models\Event;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Setting;
use App\Models\User;
use App\Support\Status;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ReportController extends ApiController
{
    private function weekends(): array
    {
        return array_map('trim', explode(',', (string) Setting::get('weekends', 'Friday')));
    }

    private function holidays(Carbon $from, Carbon $to): Collection
    {
        return Event::where('type', 'holiday')->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->get();
    }

    private function isHoliday(Collection $holidays, Carbon $d): bool
    {
        return $holidays->contains(fn ($h) => $d->between($h->start_date, $h->end_date));
    }

    private function onLeave(int $userId, Carbon $d): bool
    {
        return LeaveRequest::where('user_id', $userId)->where('status_id', Status::APPROVED)
            ->whereDate('leave_from', '<=', $d)->whereDate('leave_to', '>=', $d)->exists();
    }

    private const IN = ['OT' => 'On Time', 'L' => 'Late', 'E' => 'Early'];
    private const OUT = ['LT' => 'Left Timely', 'LE' => 'Left Early', 'LL' => 'Left Later'];

    private function dayRow(?Attendance $a, Carbon $d, string $status): array
    {
        return [
            'full_date' => $d->format('d M Y'),
            'week_day' => $d->format('D'),
            'date' => $d->format('d'),
            'status' => $status,
            'remote_mode_in' => $a ? ((int) $a->remote_mode_in ? 'Remote' : 'Office') : '',
            'remote_mode_out' => $a ? ((int) $a->remote_mode_out ? 'Remote' : 'Office') : '',
            'check_in' => $a?->check_in?->format('h:i A') ?? '',
            'check_in_status' => self::IN[$a?->in_status] ?? '',
            'check_out_status' => self::OUT[$a?->out_status] ?? '',
            'check_in_location' => (string) $a?->check_in_location,
            'check_in_reason' => (string) $a?->late_reason,
            'check_out' => $a?->check_out?->format('h:i A') ?? '',
            'check_out_location' => (string) $a?->check_out_location,
            'check_out_reason' => (string) $a?->early_reason,
        ];
    }

    private function statusFor(User $u, ?Attendance $a, Carbon $d, Collection $holidays): string
    {
        return match (true) {
            (bool) $a => 'Present',
            $this->isHoliday($holidays, $d) => 'Holiday',
            in_array($d->format('l'), $this->weekends(), true) => 'Weekend',
            $this->onLeave($u->id, $d) => 'Leave',
            $d->isFuture() => '...',
            default => 'Absent',
        };
    }

    /** POST /report/attendance/particular-month/{userId} {month} */
    public function monthly(Request $request, int $userId)
    {
        $me = $request->user();
        $user = ($userId && $userId !== $me->id && $me->canApprove()) ? User::findOrFail($userId) : $me;
        $m = $this->month($request->input('month'));
        $end = $m->copy()->endOfMonth();
        $att = Attendance::where('user_id', $user->id)->whereBetween('date', [$m, $end])->orderBy('id')->get()->groupBy(fn ($a) => $a->date->toDateString());
        $holidays = $this->holidays($m, $end);

        $rows = [];
        $c = ['working' => 0, 'present' => 0, 'absent' => 0, 'leave' => 0, 'work' => 0];
        foreach ($m->daysUntil($end) as $d) {
            $a = $att[$d->toDateString()][0] ?? null;
            $status = $this->statusFor($user, $a, $d, $holidays);
            if (! in_array($status, ['Holiday', 'Weekend'], true)) {
                $c['working']++;
            }
            if ($status === 'Present') {
                $c['present']++;
                foreach ($att[$d->toDateString()] as $x) {
                    $c['work'] += $x->check_out ? (int) $x->check_out->diffInSeconds($x->check_in, true) : 0;
                }
            }
            $c['absent'] += $status === 'Absent';
            $c['leave'] += $status === 'Leave';
            $rows[] = $this->dayRow($a, $d, $status);
        }
        $all = $att->flatten();

        return $this->ok([
            'attendance_summary' => [
                'working_days' => (string) $c['working'],
                'present' => (string) $c['present'],
                'work_time' => self::duration($c['work']),
                'absent' => (string) $c['absent'],
                'total_on_time_in' => (string) $all->where('in_status', 'OT')->count(),
                'total_leave' => (string) $c['leave'],
                'total_early_in' => (string) $all->where('in_status', 'E')->count(),
                'total_late_in' => (string) $all->where('in_status', 'L')->count(),
                'total_left_timely' => (string) $all->where('out_status', 'LT')->count(),
                'total_left_early' => (string) $all->where('out_status', 'LE')->count(),
                'total_left_later' => (string) $all->where('out_status', 'LL')->count(),
            ],
            'daily_report' => $rows,
        ], 'Attendance report');
    }

    /** POST /report/attendance/particular-date {date, user_id} */
    public function daily(Request $request)
    {
        $user = $this->targetUser($request);
        $d = $this->date($request->input('date'));
        $rows = Attendance::where('user_id', $user->id)->whereDate('date', $d)->get();
        $holidays = $this->holidays($d, $d);
        $list = $rows->isEmpty()
            ? [$this->dayRow(null, $d, $this->statusFor($user, null, $d, $holidays))]
            : $rows->map(fn ($a) => $this->dayRow($a, $d, 'Present'))->all();

        return $this->ok(['date' => $d->format('d M Y'), 'date_wise_report' => $list], 'Daily report');
    }

    /** People for a given summary bucket on a day (company-wide; HR/admin, else my team). */
    private function scope(Request $request)
    {
        $me = $request->user();

        return User::with('designation')->where('is_active', true)
            ->when(! $me->is_admin && ! $me->is_hr, fn ($q) => $q->whereIn('id', $me->team()->pluck('id')->push($me->id)));
    }

    private function buckets(Request $request, Carbon $d): array
    {
        $users = $this->scope($request)->get();
        $att = Attendance::whereIn('user_id', $users->pluck('id'))->whereDate('date', $d)->orderBy('id')->get()->groupBy('user_id');
        $onLeave = LeaveRequest::where('status_id', Status::APPROVED)->whereDate('leave_from', '<=', $d)->whereDate('leave_to', '>=', $d)->pluck('user_id')->all();

        $b = array_fill_keys(['present', 'absent', 'on_time_in', 'leave', 'early_in', 'late_in', 'left_timely', 'left_early', 'left_later'], []);
        foreach ($users as $u) {
            $a = $att[$u->id][0] ?? null;
            $last = isset($att[$u->id]) ? $att[$u->id]->last() : null;
            if ($a) {
                $b['present'][] = [$u, $a];
                $key = ['OT' => 'on_time_in', 'E' => 'early_in', 'L' => 'late_in'][$a->in_status] ?? null;
                if ($key) {
                    $b[$key][] = [$u, $a];
                }
                $key = ['LT' => 'left_timely', 'LE' => 'left_early', 'LL' => 'left_later'][$last?->out_status] ?? null;
                if ($key) {
                    $b[$key][] = [$u, $last];
                }
            } elseif (in_array($u->id, $onLeave, true)) {
                $b['leave'][] = [$u, null];
            } else {
                $b['absent'][] = [$u, null];
            }
        }

        return $b;
    }

    /** POST /report/attendance/date-summary {date} */
    public function dateSummary(Request $request)
    {
        $d = $this->date($request->input('date'));

        return $this->ok([
            'date' => $d->format('d M Y'),
            'attendance_summary' => array_map(fn ($x) => (string) count($x), $this->buckets($request, $d)),
        ], 'Attendance summary');
    }

    /** POST /report/attendance/summary-to-list {type, date} */
    public function summaryList(Request $request)
    {
        $d = $this->date($request->input('date'));
        $type = str_replace('-', '_', strtolower((string) $request->input('type', 'present')));
        $b = $this->buckets($request, $d);
        $list = $b[$type] ?? [];

        return $this->ok([
            'title' => ucwords(str_replace('_', ' ', $type)),
            'users' => array_map(fn ($x) => [
                'user_id' => $x[0]->id,
                'name' => $x[0]->name,
                'designation' => $x[0]->designation?->title ?? '',
                'check_in' => $x[1]?->check_in?->format('h:i A') ?? '',
                'check_out' => $x[1]?->check_out?->format('h:i A') ?? '',
                'avatar' => $x[0]->avatarUrl(),
            ], $list),
        ], 'Attendance list');
    }

    /** POST /report/break/date-summary {date} */
    public function breakSummary(Request $request)
    {
        $d = $this->date($request->input('date'));
        $users = $this->scope($request)->get()->keyBy('id');
        $list = BreakTime::whereIn('user_id', $users->keys())->whereDate('date', $d)->get()->groupBy('user_id')
            ->map(function ($rows, $uid) use ($users, $d) {
                $secs = $rows->sum(fn ($b) => (int) Carbon::parse($d->toDateString().' '.$b->break_time)
                    ->diffInSeconds($b->back_time ? Carbon::parse($d->toDateString().' '.$b->back_time) : now(), true));
                $u = $users[$uid];

                return [
                    'user_id' => $u->id,
                    'name' => $u->name,
                    'designation' => $u->designation?->title ?? '',
                    'avatar_id' => $u->avatarUrl(),
                    'total_break_time' => self::duration($secs),
                ];
            })->values();

        return $this->ok(['date' => $d->format('d M Y'), 'employee_list' => $list], 'Break summary');
    }

    /** POST /report/leave/date-summary {date} */
    public function leaveSummary(Request $request)
    {
        $d = $this->date($request->input('date'));
        $ids = $this->scope($request)->pluck('id');
        $counts = LeaveRequest::whereIn('user_id', $ids)->where('status_id', Status::APPROVED)
            ->whereDate('leave_from', '<=', $d)->whereDate('leave_to', '>=', $d)
            ->selectRaw('leave_type_id, count(*) c')->groupBy('leave_type_id')->pluck('c', 'leave_type_id');

        return $this->ok([
            'date' => $d->format('d M Y'),
            'leave_types' => LeaveType::orderBy('id')->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'count' => (int) ($counts[$t->id] ?? 0)]),
        ], 'Leave summary');
    }

    /** POST /report/leave/date-wise-leave {date, leave_type} */
    public function leaveList(Request $request)
    {
        $d = $this->date($request->input('date'));
        $ids = $this->scope($request)->pluck('id');
        $q = LeaveRequest::with('user.designation')->whereIn('user_id', $ids)->where('status_id', Status::APPROVED)
            ->whereDate('leave_from', '<=', $d)->whereDate('leave_to', '>=', $d);
        if ($request->filled('leave_type')) {
            $q->where('leave_type_id', $request->leave_type);
        }

        return $this->ok(['leaves' => $q->get()->map(fn ($l) => [
            'id' => $l->id,
            'user_id' => $l->user_id,
            'user_name' => $l->user->name,
            'avatar' => $l->user->avatarUrl(),
            'user_designation' => $l->user->designation?->title ?? '',
            'days' => (int) $l->days,
            'reason' => (string) $l->reason,
        ])], 'Leave list');
    }
}
