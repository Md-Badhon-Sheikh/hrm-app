<?php

namespace App\Http\Controllers\Api;

use App\Models\DailyLeave;
use App\Support\Notifier;
use Illuminate\Http\Request;

/** Short leaves within a day: early leave / late arrival. */
class DailyLeaveController extends ApiController
{
    private function normStatus(?string $s): ?string
    {
        $s = strtolower(trim((string) $s));

        return match (true) {
            str_starts_with($s, 'approv') => 'approved',
            str_starts_with($s, 'reject') => 'rejected',
            str_starts_with($s, 'pend') => 'pending',
            default => null,
        };
    }

    private function normType(?string $t): string
    {
        return str_contains(strtolower((string) $t), 'late') ? 'late_arrive' : 'early_leave';
    }

    private function row(DailyLeave $d): array
    {
        return [
            'id' => $d->id,
            'date' => $d->date->format('d M Y'),
            'staff' => $d->user?->name,
            'avater' => $d->user?->avatarUrl(),
            'designation' => $d->user?->designation?->title ?? '',
            'leave_type' => $d->leave_type === 'late_arrive' ? 'Late Arrive' : 'Early Leave',
            'time' => (string) $d->approx_time,
            'reason' => (string) $d->reason,
            'approval_details' => [
                'manager_approval' => $d->manager_approval ?? 'Pending',
                'hr_approval' => $d->hr_approval ?? 'Pending',
            ],
            'tl_approval_msg' => (string) $d->tl_approval_msg,
            'status' => ucfirst($d->status),
        ];
    }

    /** POST /daily-leave/store {leave_type, approx_time, reason} */
    public function store(Request $request)
    {
        $request->validate(['leave_type' => 'required', 'reason' => 'nullable|string']);
        $u = $request->user();
        $d = DailyLeave::create([
            'user_id' => $u->id,
            'date' => $request->input('date') ? $this->date($request->date)->toDateString() : today()->toDateString(),
            'leave_type' => $this->normType($request->leave_type),
            'approx_time' => $request->input('approx_time'),
            'reason' => $request->input('reason'),
        ]);
        $msg = "{$u->name} requested ".str_replace('_', ' ', $d->leave_type);
        $u->manager_id ? Notifier::send($u->manager_id, 'Daily leave request', $msg, 'daily-leave', $u->id, $d->id) : Notifier::hr('Daily leave request', $msg, 'daily-leave', $u->id, $d->id);

        return $this->ok(null, 'Request submitted successfully');
    }

    /** POST /daily-leave/leave-list {month, user_id} — counts per status/type. */
    public function summary(Request $request)
    {
        $user = $this->targetUser($request);
        $m = $this->month($request->input('month'));
        $rows = DailyLeave::where('user_id', $user->id)->whereBetween('date', [$m, $m->copy()->endOfMonth()])->get();
        $count = fn ($status) => [
            'early_leave' => $rows->where('status', $status)->where('leave_type', 'early_leave')->count(),
            'late_arrive' => $rows->where('status', $status)->where('leave_type', 'late_arrive')->count(),
        ];

        return $this->ok(['approved' => $count('approved'), 'rejected' => $count('rejected'), 'pending' => $count('pending')], 'Daily leave summary');
    }

    /** POST /daily-leave/staff-list-view {month, user_id, leave_type, leave_status} */
    public function list(Request $request)
    {
        $me = $request->user();
        $m = $this->month($request->input('month'));
        $q = DailyLeave::with('user.designation')->whereBetween('date', [$m, $m->copy()->endOfMonth()])->latest('id');

        if ($request->filled('user_id')) {
            $q->where('user_id', $this->targetUser($request)->id);
        } elseif (! $me->is_admin && ! $me->is_hr) {
            $q->whereIn('user_id', $me->team()->pluck('id')->push($me->id));
        }
        if ($request->filled('leave_type')) {
            $q->where('leave_type', $this->normType($request->leave_type));
        }
        if ($s = $this->normStatus($request->input('leave_status'))) {
            $q->where('status', $s);
        }

        return $this->ok(['data' => $q->get()->map(fn ($d) => $this->row($d))], 'Daily leave list');
    }

    /** POST /daily-leave/single-view {leave_id} */
    public function show(Request $request)
    {
        $d = DailyLeave::with('user.designation')->findOrFail($request->input('leave_id'));
        $row = $this->row($d);
        unset($row['avater'], $row['designation']);

        return $this->ok($row, 'Daily leave details');
    }

    /** POST /daily-leave/approve-reject {leave_id, leave_status: approved|rejected} */
    public function approve(Request $request)
    {
        $me = $request->user();
        $d = DailyLeave::with('user')->findOrFail($request->input('leave_id'));
        $status = $this->normStatus($request->input('leave_status'));
        if (! in_array($status, ['approved', 'rejected'], true)) {
            return $this->fail('Invalid status', 422);
        }
        $isManager = $d->user->manager_id === $me->id;
        if (! $me->is_admin && ! $me->is_hr && ! $isManager) {
            return $this->fail('You are not allowed to approve this request.', 403);
        }

        $label = ucfirst($status);
        $update = ['status' => $status];
        if ($isManager) {
            $update['manager_approval'] = $label;
            $update['tl_approval_msg'] = "$label by {$me->name}";
        }
        if ($me->is_hr || $me->is_admin) {
            $update['hr_approval'] = $label;
        }
        $d->update($update);
        Notifier::send($d->user_id, "Daily leave $status", "Your request was $status by {$me->name}", 'daily-leave', $me->id, $d->id);

        return $this->ok(null, "Request $status");
    }
}
