<?php

namespace App\Http\Controllers\Api;

use App\Models\Appointment;
use App\Models\Conference;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Support\Files;
use App\Support\Notifier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Meetings, appointments (1:1) and video conferences. */
class MeetingController extends ApiController
{
    private function ids($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        return array_values(array_unique(array_filter(array_map('intval', (array) $value))));
    }

    private function times($date, $start, $end): array
    {
        $s = $start ? Carbon::parse($start) : null;
        $e = $end ? Carbon::parse($end) : null;
        $duration = $s && $e ? self::duration((int) $e->diffInSeconds($s, true)) : '';

        return [
            'time' => trim(($s?->format('h:i A') ?? '').($e ? ' - '.$e->format('h:i A') : '')),
            'start_at' => $s?->format('h:i A') ?? '',
            'end_at' => $e?->format('h:i A') ?? '',
            'duration' => $duration,
        ];
    }

    private function meetingItem(Meeting $m): array
    {
        return [
            'id' => $m->id,
            'title' => $m->title,
            'date' => $m->date->format('d M Y'),
            'day' => $m->date->format('l'),
            'location' => (string) $m->location,
            'participants' => $m->participants->map(fn ($p) => [
                'name' => $p->user?->name,
                'is_agree' => $p->is_agree ? 'Yes' : 'No',
                'is_present' => $p->is_present ? 'Yes' : 'No',
                'present_at' => $p->present_at?->format('h:i A') ?? '',
            ]),
            'appoinmentWith' => $m->user?->name,
        ] + $this->times($m->date, $m->start_at, $m->end_at);
    }

    /** POST /meeting {month} */
    public function meetings(Request $request)
    {
        $u = $request->user();
        $m = $this->month($request->input('month'));
        $items = Meeting::with('participants.user', 'user')
            ->whereBetween('date', [$m, $m->copy()->endOfMonth()])
            ->where(fn ($q) => $q->where('user_id', $u->id)->orWhereHas('participants', fn ($p) => $p->where('user_id', $u->id)))
            ->orderBy('date')->get()->map(fn ($x) => $this->meetingItem($x));

        return $this->ok(['items' => $items], 'Meeting list');
    }

    /** POST /meeting/create (multipart) */
    public function storeMeeting(Request $request)
    {
        $request->validate(['title' => 'required|string', 'date' => 'required', 'attachment_file' => 'nullable|file|max:5120']);
        $u = $request->user();
        $m = Meeting::create([
            'user_id' => $u->id,
            'title' => $request->title,
            'description' => $request->input('description'),
            'location' => $request->input('location'),
            'date' => $this->date($request->date)->toDateString(),
            'start_at' => $request->input('start_at') ? Carbon::parse($request->start_at)->format('H:i:s') : null,
            'end_at' => $request->input('end_at') ? Carbon::parse($request->end_at)->format('H:i:s') : null,
            'attachment' => Files::store($request->file('attachment_file'), 'meeting'),
        ]);
        foreach ($this->ids($request->input('participants')) as $pid) {
            MeetingParticipant::create(['meeting_id' => $m->id, 'user_id' => $pid]);
            Notifier::send($pid, 'Meeting invitation', "{$u->name} invited you to: {$m->title}", 'meeting', $u->id, $m->id);
        }

        return $this->ok(null, 'Meeting created successfully');
    }

    /** GET /meeting/show/{id} */
    public function showMeeting(int $id)
    {
        $m = Meeting::findOrFail($id);
        $t = $this->times($m->date, $m->start_at, $m->end_at);

        return $this->ok([
            'id' => $m->id,
            'title' => $m->title,
            'description' => (string) $m->description,
            'location' => (string) $m->location,
            'meeting_at' => Carbon::parse($m->date->toDateString().' '.($m->start_at ?? '00:00'))->toIso8601String(),
            'duration' => $t['duration'],
            'start_at' => $t['start_at'],
            'end_at' => $t['end_at'],
            'status' => $m->status,
            'color' => '#2563EB',
            'attachment_file' => Files::url($m->attachment),
        ], 'Meeting details');
    }

    private function myAppointments(Request $request)
    {
        $u = $request->user();

        return Appointment::with('creator', 'withUser')
            ->where(fn ($q) => $q->where('created_by', $u->id)->orWhere('appoinment_with', $u->id));
    }

    /** POST /appoinment/get-list {month} — same shape as meeting list. */
    public function appointments(Request $request)
    {
        $u = $request->user();
        $m = $this->month($request->input('month'));
        $items = $this->myAppointments($request)->whereBetween('date', [$m, $m->copy()->endOfMonth()])->orderBy('date')->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'date' => $a->date->format('d M Y'),
                'day' => $a->date->format('l'),
                'location' => (string) $a->location,
                'participants' => [[
                    'name' => $a->withUser?->name,
                    'is_agree' => $a->is_agree ? 'Yes' : 'No',
                    'is_present' => $a->is_present ? 'Yes' : 'No',
                    'present_at' => $a->present_at?->format('h:i A') ?? '',
                ]],
                'appoinmentWith' => $a->created_by === $u->id ? $a->withUser?->name : $a->creator?->name,
            ] + $this->times($a->date, $a->start_at, $a->end_at));

        return $this->ok(['items' => $items], 'Appointment list');
    }

    /** GET /appoinment/get-list — upcoming appointments for the home screen. */
    public function upcomingAppointments(Request $request)
    {
        $u = $request->user();
        $items = $this->myAppointments($request)->whereDate('date', '>=', today())->orderBy('date')->limit(10)->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'date' => $a->date->format('d M Y'),
                'day' => $a->date->format('l'),
                'time' => $this->times($a->date, $a->start_at, $a->end_at)['time'],
                'location' => (string) $a->location,
                'participant' => [$a->created_by === $u->id ? $a->withUser?->name : $a->creator?->name],
            ]);

        return $this->ok(['items' => $items], 'Upcoming appointments', ['env' => app()->environment(), 'status' => 200]);
    }

    /** POST /appoinment/create (multipart) */
    public function storeAppointment(Request $request)
    {
        $request->validate(['title' => 'required|string', 'appoinment_with' => 'required|exists:users,id', 'date' => 'required']);
        $u = $request->user();
        $a = Appointment::create([
            'created_by' => $u->id,
            'appoinment_with' => (int) $request->appoinment_with,
            'title' => $request->title,
            'description' => $request->input('description'),
            'location' => $request->input('location'),
            'date' => $this->date($request->date)->toDateString(),
            'start_at' => $request->input('appoinment_start_at') ? Carbon::parse($request->appoinment_start_at)->format('H:i:s') : null,
            'end_at' => $request->input('appoinment_end_at') ? Carbon::parse($request->appoinment_end_at)->format('H:i:s') : null,
            'attachment' => Files::store($request->file('attachment_file'), 'appointment'),
        ]);
        Notifier::send($a->appoinment_with, 'New appointment', "{$u->name}: {$a->title}", 'appointment', $u->id, $a->id);

        return $this->ok(null, 'Appointment created successfully');
    }

    /** GET /appoinment/details/{id} */
    public function showAppointment(Request $request, int $id)
    {
        $a = $this->myAppointments($request)->findOrFail($id);
        $other = $a->created_by === $request->user()->id ? $a->withUser : $a->creator;

        return $this->ok([
            'id' => $a->id,
            'title' => $a->title,
            'date' => $a->date->format('d M Y'),
            'day' => $a->date->format('l'),
            'location' => (string) $a->location,
            'appoinmentWith' => $other?->name,
            'other_participant' => [
                'id' => $other?->id,
                'name' => $other?->name,
                'is_agree' => $a->is_agree ? 'Yes' : 'No',
                'is_present' => $a->is_present ? 'Yes' : 'No',
                'present_at' => $a->present_at?->format('h:i A'),
                'appoinment_started_at' => $a->started_at?->format('h:i A'),
                'appoinment_ended_at' => $a->ended_at?->format('h:i A'),
                'appoinment_duration' => $a->started_at && $a->ended_at ? self::duration((int) $a->ended_at->diffInSeconds($a->started_at, true)) : null,
            ],
        ] + array_diff_key($this->times($a->date, $a->start_at, $a->end_at), ['duration' => 1]), 'Appointment details');
    }

    /** GET /conference/my-meeting — video conferences (Jitsi links by default). */
    public function conferences(Request $request)
    {
        $u = $request->user();
        $base = rtrim((string) env('CONFERENCE_BASE_URL', 'https://meet.jit.si'), '/');
        $items = Conference::with('members')
            ->where(fn ($q) => $q->where('created_by', $u->id)->orWhereHas('members', fn ($m) => $m->where('users.id', $u->id)))
            ->where('end', '>=', now()->subDay())->orderBy('start')->get()
            ->map(function ($c) use ($base) {
                $live = now()->between($c->start, $c->end);

                return [
                    'title' => $c->title,
                    'start' => $c->start->toIso8601String(),
                    'end' => $c->end->toIso8601String(),
                    'room_id' => $c->room_id,
                    'external_link' => $c->external_link ?: "$base/{$c->room_id}",
                    'internal_link' => "$base/{$c->room_id}",
                    'button' => $live ? 'Join' : ($c->end->isPast() ? 'Ended' : 'Upcoming'),
                    'time_text' => $c->start->format('d M Y, h:i A').' - '.$c->end->format('h:i A'),
                    'members' => $c->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'email' => $m->email, 'avatar' => $m->avatarUrl()]),
                ];
            });

        return $this->ok($items, 'Conference list');
    }

    /** POST /conference/create {title, start, end, members} — helper for admins. */
    public function storeConference(Request $request)
    {
        $request->validate(['title' => 'required', 'start' => 'required|date', 'end' => 'required|date']);
        $c = Conference::create([
            'created_by' => $request->user()->id,
            'title' => $request->title,
            'start' => Carbon::parse($request->start),
            'end' => Carbon::parse($request->end),
            'room_id' => Str::slug($request->title).'-'.Str::lower(Str::random(6)),
            'external_link' => $request->input('external_link'),
        ]);
        $c->members()->sync($this->ids($request->input('members')));

        return $this->ok(null, 'Conference created');
    }
}
