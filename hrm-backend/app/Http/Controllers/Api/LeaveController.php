<?php

namespace App\Http\Controllers\Api;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Files;
use App\Support\Notifier;
use App\Support\Status;
use Carbon\Carbon;
use Illuminate\Http\Request;

class LeaveController extends ApiController
{
    /** Allowance and usage per leave type for the current year. */
    private function balances(User $user): array
    {
        $used = LeaveRequest::where('user_id', $user->id)
            ->whereIn('status_id', [Status::APPROVED, Status::PENDING])
            ->whereYear('leave_from', now()->year)
            ->selectRaw('leave_type_id, SUM(days) as used')->groupBy('leave_type_id')->pluck('used', 'leave_type_id');

        return LeaveType::orderBy('id')->get()->map(fn ($t) => [
            'id' => $t->id,
            'type' => $t->name,
            'total_leave' => (int) $t->days,
            'left_days' => max(0, (int) $t->days - (int) ($used[$t->id] ?? 0)),
        ])->all();
    }

    private function statusName(LeaveRequest $l): string
    {
        return Status::name((int) $l->status_id);
    }

    /** POST /user/leave/summary */
    public function summary(Request $request)
    {
        $user = $this->targetUser($request);
        $b = $this->balances($user);
        $total = array_sum(array_column($b, 'total_leave'));
        $left = array_sum(array_column($b, 'left_days'));

        return $this->ok([
            'total_leave' => $total,
            'total_used' => $total - $left,
            'leave_balance' => $left,
            'available_leave' => $b,
        ], 'Leave summary');
    }

    /** POST /user/leave/available */
    public function available(Request $request)
    {
        return $this->ok(['available_leave' => $this->balances($this->targetUser($request))], 'Available leave');
    }

    /** POST /user/leave/list/view {user_id, month} */
    public function list(Request $request)
    {
        $user = $this->targetUser($request);
        $q = LeaveRequest::with('leaveType')->where('user_id', $user->id)->latest('id');
        if ($request->filled('month')) {
            $m = $this->month($request->month);
            $q->where(fn ($w) => $w->whereBetween('leave_from', [$m, $m->copy()->endOfMonth()])
                ->orWhereBetween('apply_date', [$m, $m->copy()->endOfMonth()]));
        }

        return $this->ok(['leaveRequests' => $q->get()->map(fn ($l) => [
            'id' => $l->id,
            'type' => $l->leaveType?->name,
            'days' => (int) $l->days,
            'status' => $this->statusName($l),
            'apply_date' => $l->apply_date->format('d M Y'),
            'color_code' => Status::color((int) $l->status_id),
        ])], 'Leave requests');
    }

    /** POST /user/leave/details/{id} — used by both "my leave" and "approval" details. */
    public function details(Request $request, int $id)
    {
        $me = $request->user();
        $l = LeaveRequest::with('leaveType', 'user.designation', 'user.department', 'substitute.designation', 'approver', 'referrer')->findOrFail($id);
        if ($l->user_id !== $me->id && ! $me->canApprove()) {
            return $this->fail('Not allowed', 403);
        }

        $bal = collect($this->balances($l->user))->firstWhere('id', $l->leave_type_id);
        $sub = $l->substitute ? [
            'id' => $l->substitute->id,
            'name' => $l->substitute->name,
            'employee_id' => (string) $l->substitute->employee_id,
            'designation' => $l->substitute->designation?->title ?? '',
            'avatar' => $l->substitute->avatarUrl(),
        ] : null;

        return $this->ok([
            'id' => $l->id,
            'user_id' => $l->user_id,
            'name' => $l->user->name,
            'designation' => $l->user->designation?->title ?? '',
            'department' => $l->user->department?->title ?? '',
            'status' => $this->statusName($l),
            'requested_on' => $l->apply_date->format('d M Y'),
            'type' => $l->leaveType?->name,
            'period' => $l->leave_from->format('d M Y').' - '.$l->leave_to->format('d M Y'),
            'note' => (string) $l->reason,
            'total_days' => (int) $l->days,
            'available_leave' => (int) ($bal['left_days'] ?? 0),
            'total_used' => (int) (($bal['total_leave'] ?? 0) - ($bal['left_days'] ?? 0)),
            'approved_by' => $l->approver?->name,
            'referred_by' => $l->referrer?->name,
            'substitute' => $sub,
            'apporover' => $l->approver?->name,
            'attachment' => Files::url($l->attachment),
            'color_code' => Status::color((int) $l->status_id),
        ], 'Leave details');
    }

    private function fill(Request $request, ?LeaveRequest $existing = null): array|string
    {
        $from = Carbon::parse($request->input('leave_from', $existing?->leave_from));
        $to = Carbon::parse($request->input('leave_to', $existing?->leave_to));
        if ($to->lt($from)) {
            return 'Leave end date must be after start date.';
        }
        $days = (int) $from->diffInDays($to) + 1;

        $typeId = (int) $request->input('assign_leave_id', $existing?->leave_type_id);
        $type = LeaveType::find($typeId);
        if (! $type) {
            return 'Please select a valid leave type.';
        }

        $user = $existing?->user ?? $request->user();
        $left = collect($this->balances($user))->firstWhere('id', $typeId)['left_days'] ?? 0;
        if ($existing && $existing->leave_type_id === $typeId) {
            $left += $existing->days;
        }
        if ($days > $left) {
            return "You only have $left day(s) of {$type->name} left.";
        }

        return [
            'leave_type_id' => $typeId,
            'substitute_id' => $request->input('substitute_id') ?: $existing?->substitute_id,
            'leave_from' => $from->toDateString(),
            'leave_to' => $to->toDateString(),
            'days' => $days,
            'reason' => $request->input('reason', $existing?->reason),
        ];
    }

    /** POST /user/leave/request (multipart) */
    public function store(Request $request)
    {
        $request->validate([
            'assign_leave_id' => 'required',
            'leave_from' => 'required|date',
            'leave_to' => 'required|date',
            'attachment_file' => 'nullable|file|max:5120',
        ]);
        $user = $request->user();
        $data = $this->fill($request);
        if (is_string($data)) {
            return $this->fail($data, 422);
        }

        $l = LeaveRequest::create($data + [
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'apply_date' => $request->input('apply_date') ? Carbon::parse($request->apply_date)->toDateString() : today()->toDateString(),
            'attachment' => Files::store($request->file('attachment_file'), 'leave'),
            'status_id' => Status::PENDING,
        ]);

        $msg = "{$user->name} applied for {$l->days} day(s) leave";
        $user->manager_id ? Notifier::send($user->manager_id, 'New leave request', $msg, 'leave', $user->id, $l->id) : Notifier::hr('New leave request', $msg, 'leave', $user->id, $l->id);

        return $this->ok([
            'user_id' => $l->user_id,
            'assign_leave_id' => $l->leave_type_id,
            'substitute_id' => $l->substitute_id ? (string) $l->substitute_id : null,
            'days' => (int) $l->days,
            'reason' => (string) $l->reason,
            'status_id' => (int) $l->status_id,
            'id' => $l->id,
        ], 'Leave request submitted successfully');
    }

    /** POST /user/leave/request/update/{id} */
    public function update(Request $request, int $id)
    {
        $l = LeaveRequest::with('user')->where('user_id', $request->user()->id)->findOrFail($id);
        if ($l->status_id !== Status::PENDING) {
            return $this->fail('Only pending requests can be updated.', 400);
        }
        $data = $this->fill($request, $l);
        if (is_string($data)) {
            return $this->fail($data, 422);
        }
        if ($file = Files::store($request->file('attachment_file'), 'leave')) {
            $data['attachment'] = $file;
        }
        $l->update($data);

        return $this->ok(null, 'Leave request updated successfully');
    }

    /** GET /user/leave/request/cancel/{id} */
    public function cancel(Request $request, int $id)
    {
        $l = LeaveRequest::where('user_id', $request->user()->id)->findOrFail($id);
        if ($l->status_id !== Status::PENDING) {
            return $this->fail('Only pending requests can be cancelled.', 400);
        }
        $l->update(['status_id' => Status::CANCELLED]);

        return $this->ok(null, 'Leave request cancelled');
    }

    /** POST /user/leave/approval/list/view — requests waiting for me. */
    public function approvalList(Request $request)
    {
        $me = $request->user();
        $q = LeaveRequest::with('leaveType', 'user')->where('user_id', '!=', $me->id)->latest('id');
        if (! $me->is_admin && ! $me->is_hr) {
            $q->whereIn('user_id', $me->team()->pluck('id'));
        }
        if ($request->filled('month')) {
            $m = $this->month($request->month);
            $q->whereBetween('leave_from', [$m, $m->copy()->endOfMonth()]);
        }

        return $this->ok(['leaveRequests' => $q->limit(100)->get()->map(fn ($l) => [
            'id' => $l->id,
            'user_id' => $l->user_id,
            'name' => $l->user->name,
            'message' => (string) $l->reason,
            'type' => $l->leaveType?->name,
            'days' => (int) $l->days,
            'apply_date' => $l->apply_date->format('d M Y'),
            'date_duration' => $l->leave_from->format('d M').' - '.$l->leave_to->format('d M Y'),
            'status' => $this->statusName($l),
            'color_code' => Status::color((int) $l->status_id),
        ])], 'Approval list');
    }

    /** GET /user/leave/approval/status-change/{id}/{type}  type: 1 approve, 6 reject */
    public function changeStatus(Request $request, int $id, int $type)
    {
        $me = $request->user();
        $l = LeaveRequest::with('user')->findOrFail($id);
        $allowed = $me->is_admin || $me->is_hr || $l->user->manager_id === $me->id;
        if (! $allowed || $l->user_id === $me->id) {
            return $this->fail('You are not allowed to approve this request.', 403);
        }
        if (! in_array($type, [Status::APPROVED, Status::REJECTED], true)) {
            return $this->fail('Invalid status', 422);
        }

        $l->update(['status_id' => $type, 'approved_by' => $me->id]);
        $word = $type === Status::APPROVED ? 'approved' : 'rejected';
        Notifier::send($l->user_id, "Leave $word", "Your leave request has been $word by {$me->name}", 'leave', $me->id, $l->id);

        return $this->ok(null, "Leave request $word");
    }
}
