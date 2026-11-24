<?php

namespace App\Http\Controllers\Api;

use App\Models\Attendance;
use App\Models\BreakTime;
use App\Models\LiveLocation;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AttendanceController extends ApiController
{
    private function officeTime(string $key, string $default, Carbon $date): Carbon
    {
        return Carbon::parse($date->toDateString().' '.Setting::get($key, $default));
    }

    private function checkIp(Request $request): ?string
    {
        if (Setting::get('ip_restriction', '0') !== '1') {
            return null;
        }
        $allowed = array_filter(array_map('trim', explode(',', (string) Setting::get('allowed_ips', ''))));
        if ($allowed && ! in_array($request->ip(), $allowed, true)) {
            return 'You are not connected to the office network (IP '.$request->ip().').';
        }

        return null;
    }

    private function row(Attendance $a): array
    {
        return [
            'id' => $a->id,
            'company_id' => (int) $a->company_id,
            'user_id' => $a->user_id,
            'date' => $a->date->toDateString(),
            'check_in' => $a->check_in?->toIso8601String(),
            'check_out' => $a->check_out?->toIso8601String(),
            'late_reason' => $a->late_reason,
            'late_time' => (int) $a->late_time,
            'in_status' => $a->in_status,
            'out_status' => $a->out_status,
            'checkin_ip' => $a->checkin_ip,
            'checkout_ip' => $a->checkout_ip,
            'remote_mode_in' => (int) $a->remote_mode_in,
            'remote_mode_out' => (int) $a->remote_mode_out,
            'check_in_location' => $a->check_in_location,
            'check_out_location' => $a->check_out_location,
            'check_in_latitude' => $a->check_in_latitude,
            'check_in_longitude' => $a->check_in_longitude,
            'check_out_latitude' => $a->check_out_latitude,
            'check_out_longitude' => $a->check_out_longitude,
            'check_in_city' => $a->check_in_city,
            'check_in_country_code' => $a->check_in_country_code,
            'check_in_country' => $a->check_in_country,
            'check_out_city' => $a->check_out_city,
            'check_out_country_code' => $a->check_out_country_code,
            'check_out_country' => $a->check_out_country,
            'status_id' => (int) $a->status_id,
            'created_at' => $a->created_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ];
    }

    /** POST /user/attendance/check-in */
    public function checkIn(Request $request)
    {
        $user = $request->user();
        if ($err = $this->checkIp($request)) {
            return $this->fail($err, 400, null, self::NULL);
        }

        $date = $this->date($request->input('date'));
        $time = Carbon::parse($date->toDateString().' '.($request->input('check_in') ?: now()->format('H:i')));

        $open = Attendance::where('user_id', $user->id)->whereDate('date', $date)->whereNull('check_out')->first();
        if ($open) {
            return $this->fail('You have already checked in today.', 400, null, self::NULL);
        }
        if (Setting::get('multi_checkin', '0') !== '1' && Attendance::where('user_id', $user->id)->whereDate('date', $date)->exists()) {
            return $this->fail('You have already completed attendance for today.', 400, null, self::NULL);
        }

        $start = $this->officeTime('office_start', '09:00', $date);
        $grace = (int) Setting::get('late_grace_minutes', 15);
        $isFirst = ! Attendance::where('user_id', $user->id)->whereDate('date', $date)->exists();
        $lateMinutes = $isFirst ? max(0, (int) $start->diffInMinutes($time, false)) : 0;
        $isLate = $lateMinutes > $grace;

        // Late without a reason → the app opens the "give reason" screen.
        if ($isLate && ! $request->filled('reason') && Setting::get('require_late_reason', '1') === '1') {
            return $this->fail("Oops! You're late today!", 422, ['reason_status' => 'L'], self::NULL);
        }

        $a = Attendance::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'date' => $date->toDateString(),
            'check_in' => $time,
            'in_status' => $isLate ? 'L' : ($time->lt($start) ? 'E' : 'OT'),
            'late_time' => $isLate ? $lateMinutes : 0,
            'late_reason' => $request->input('reason'),
            'remote_mode_in' => (int) $request->input('remote_mode_in', 0),
            'checkin_ip' => $request->ip(),
            'check_in_location' => $request->input('check_in_location'),
            'check_in_latitude' => $request->input('check_in_latitude'),
            'check_in_longitude' => $request->input('check_in_longitude'),
            'check_in_city' => $request->input('city'),
            'check_in_country_code' => $request->input('country_code'),
            'check_in_country' => $request->input('country'),
        ]);

        return $this->ok($this->row($a), 'Check in successfully');
    }

    /** PATCH|POST /user/attendance/check-out/{id} */
    public function checkOut(Request $request, ?int $id = null)
    {
        $user = $request->user();
        if ($err = $this->checkIp($request)) {
            return $this->fail($err, 400, null, self::NULL);
        }

        $a = Attendance::where('user_id', $user->id)
            ->when($id, fn ($q) => $q->where('id', $id))
            ->whereNull('check_out')->latest('id')->first()
            ?? Attendance::where('user_id', $user->id)->whereNull('check_out')->latest('id')->first();
        if (! $a) {
            return $this->fail('You have not checked in yet.', 400, null, self::NULL);
        }

        $time = Carbon::parse(($request->input('date') ? $this->date($request->input('date'))->toDateString() : $a->date->toDateString())
            .' '.($request->input('check_out') ?: now()->format('H:i')));
        $end = $this->officeTime('office_end', '18:00', $a->date);
        $early = $time->lt($end->copy()->subMinutes((int) Setting::get('early_grace_minutes', 0)));

        if ($early && ! $request->filled('reason') && Setting::get('require_early_reason', '1') === '1') {
            return $this->fail('You are leaving early!', 422, ['reason_status' => 'LE'], self::NULL);
        }

        $a->update([
            'check_out' => $time,
            'out_status' => $early ? 'LE' : ($time->gt($end->copy()->addMinutes(30)) ? 'LL' : 'LT'),
            'early_reason' => $request->input('reason'),
            'remote_mode_out' => (int) $request->input('remote_mode_out', 0),
            'checkout_ip' => $request->ip(),
            'check_out_location' => $request->input('check_out_location'),
            'check_out_latitude' => $request->input('check_out_latitude'),
            'check_out_longitude' => $request->input('check_out_longitude'),
            'check_out_city' => $request->input('city'),
            'check_out_country_code' => $request->input('country_code'),
            'check_out_country' => $request->input('country'),
        ]);

        return $this->ok($this->row($a->fresh()), 'Check out successfully');
    }

    /** POST /user/attendance/late-in-reason/{id} {type: in|out, reason} */
    public function lateReason(Request $request, int $id)
    {
        $a = Attendance::where('user_id', $request->user()->id)->findOrFail($id);
        $request->input('type') === 'out'
            ? $a->update(['early_reason' => $request->input('reason')])
            : $a->update(['late_reason' => $request->input('reason')]);

        return $this->ok(null, 'Reason saved');
    }

    /** POST /user/attendance/get-checkin-checkout-status */
    public function status(Request $request)
    {
        $user = $this->targetUser($request);
        $today = Attendance::where('user_id', $user->id)->whereDate('date', today())->latest('id')->first();
        $open = $today && ! $today->check_out;
        $multi = Setting::get('multi_checkin', '0') === '1';

        $stay = '';
        if ($today?->check_in) {
            $secs = ($today->check_out ?? now())->diffInSeconds($today->check_in, true);
            $stay = self::duration((int) $secs);
        }

        return $this->ok([
            'id' => $today?->id,
            'checkin' => (bool) $today && (! $multi || $open),
            'checkout' => (bool) $today?->check_out && ! $multi,
            'in_time' => $today?->check_in?->format('h:i A') ?? '',
            'out_time' => $today?->check_out?->format('h:i A') ?? '',
            'stay_time' => $stay,
        ], 'Attendance status');
    }

    /** POST /user/attendance/break-back/{start|back} {time, reason?} */
    public function breakToggle(Request $request, string $slug)
    {
        $user = $request->user();
        $open = BreakTime::where('user_id', $user->id)->whereDate('date', today())->whereNull('back_time')->latest('id')->first();
        $time = $request->input('time') ?: now()->format('H:i:s');
        $time = strlen($time) === 5 ? $time.':00' : $time;

        if ($slug === 'start') {
            if ($open) {
                return $this->fail('You are already on a break.', 400);
            }
            if (! \App\Models\Attendance::where('user_id', $user->id)->whereDate('date', today())->whereNull('check_out')->exists()) {
                return $this->fail('Please check in before taking a break.', 400);
            }
            $b = BreakTime::create([
                'company_id' => $user->company_id,
                'user_id' => $user->id,
                'date' => today()->toDateString(),
                'break_time' => $time,
                'reason' => $request->input('reason'),
            ]);
            $status = 'break_in';
            $msg = 'Break started';
        } else {
            if (! $open) {
                return $this->fail('You are not on a break.', 400);
            }
            $open->update(['back_time' => $time]);
            $b = $open->fresh();
            $status = 'break_out';
            $msg = 'Welcome back!';
        }

        return $this->ok([
            'company_id' => (int) $b->company_id,
            'user_id' => $b->user_id,
            'date' => $b->date->toDateString(),
            'break_time' => $b->break_time,
            'back_time' => $b->back_time,
            'reason' => $b->reason,
            'updated_at' => $b->updated_at->toIso8601String(),
            'created_at' => $b->created_at->toIso8601String(),
            'id' => $b->id,
            'status' => $status,
        ], $msg);
    }

    private function breakHistoryData(int $userId, Carbon $date, bool $withName = false, bool $paginate = false): array
    {
        $q = BreakTime::with('user')->where('user_id', $userId)->whereDate('date', $date)->latest('id');
        $p = $paginate ? $q->paginate(20) : null;
        $rows = $p ? collect($p->items()) : $q->get();

        $total = 0;
        $history = $rows->map(function ($b) use (&$total, $withName) {
            $start = Carbon::parse($b->date->toDateString().' '.$b->break_time);
            $end = $b->back_time ? Carbon::parse($b->date->toDateString().' '.$b->back_time) : now();
            $secs = (int) $end->diffInSeconds($start, true);
            $total += $secs;
            $row = [
                'reason' => (string) $b->reason,
                'break_time_duration' => $start->format('h:i A').' - '.($b->back_time ? $end->format('h:i A') : 'Running'),
                'break_back_time' => self::duration($secs),
            ];
            if ($withName) {
                $row = ['name' => $b->user?->name] + $row;
            }

            return $row;
        })->values();

        $data = [
            'total_break_time' => self::duration($total),
            'has_break' => $rows->contains(fn ($b) => ! $b->back_time),
            'break_history' => ['today_history' => $history],
        ];
        if ($p) {
            $data['break_history']['links'] = $this->links($p);
            $data['break_history']['pagination'] = $this->pagination($p);
        }

        return $data;
    }

    /** POST /user/attendance/break-status — today's break history for me. */
    public function breakStatus(Request $request)
    {
        return $this->ok($this->breakHistoryData($this->targetUser($request)->id, today()), 'Break history');
    }

    /** POST /user/attendance/break-history {date, user_id} */
    public function breakHistory(Request $request)
    {
        return $this->ok($this->breakHistoryData($this->targetUser($request)->id, $this->date($request->input('date')), true, true), 'Break history');
    }

    /** POST /user/attendance/live-location-store {locations: [...]} or single point */
    public function liveLocation(Request $request)
    {
        $user = $request->user();
        $points = $request->input('locations');
        if (is_string($points)) {
            $points = json_decode($points, true);
        }
        if (! is_array($points) || ! $points) {
            $points = [$request->all()];
        }

        foreach ($points as $p) {
            if (! is_array($p) || (empty($p['latitude']) && empty($p['lat']))) {
                continue;
            }
            LiveLocation::create([
                'user_id' => $user->id,
                'latitude' => $p['latitude'] ?? $p['lat'] ?? null,
                'longitude' => $p['longitude'] ?? $p['lng'] ?? null,
                'address' => $p['address'] ?? null,
                'city' => $p['city'] ?? null,
                'country' => $p['country'] ?? null,
                'distance' => isset($p['distance']) ? (float) $p['distance'] : null,
                'recorded_at' => isset($p['datetime']) ? Carbon::parse($p['datetime']) : now(),
            ]);
        }

        return $this->ok(self::NULL, 'Location stored');
    }
}
