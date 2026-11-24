<?php

namespace App\Http\Controllers\Api;

use App\Models\Visit;
use App\Models\VisitImage;
use App\Models\VisitNote;
use App\Models\VisitSchedule;
use App\Support\Files;
use App\Support\Notifier;
use Illuminate\Http\Request;

/** Field visits: created → started → reached → completed (or cancelled). */
class VisitController extends ApiController
{
    private const FLOW = ['created' => 'started', 'started' => 'reached', 'reached' => 'completed'];
    private const TEXT = ['started' => 'Start Visit', 'reached' => 'Reached', 'completed' => 'Complete Visit'];

    private function color(string $status): string
    {
        return match ($status) {
            'created' => '#F59E0B',
            'started' => '#2563EB',
            'reached' => '#7C3AED',
            'completed' => '#16A34A',
            'cancelled' => '#DC2626',
            default => '#6B7280',
        };
    }

    private function mine(Request $request, $id): Visit
    {
        return Visit::where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function brief(Visit $v): array
    {
        return [
            'id' => $v->id,
            'title' => $v->title,
            'date' => $v->date->format('d M Y'),
            'status' => ucfirst($v->status),
            'status_color' => $this->color($v->status),
        ];
    }

    /** GET /visit/list — today's & upcoming open visits */
    public function index(Request $request)
    {
        $visits = Visit::where('user_id', $request->user()->id)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->orderBy('date')->get()->map(fn ($v) => $this->brief($v));

        return $this->ok(['my_visits' => $visits], 'Visit list');
    }

    /** POST /visit/history {month} */
    public function history(Request $request)
    {
        $m = $this->month($request->input('month'));
        $visits = Visit::where('user_id', $this->targetUser($request)->id)
            ->whereBetween('date', [$m, $m->copy()->endOfMonth()])->latest('date')->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'title' => $v->title,
                'year' => $v->date->format('Y'),
                'month' => $v->date->format('M'),
                'day' => $v->date->format('d'),
                'started' => $v->started_at?->format('h:i A') ?? '',
                'reached' => $v->reached_at?->format('h:i A') ?? '',
                'duration' => $v->started_at && $v->reached_at ? self::duration((int) $v->reached_at->diffInSeconds($v->started_at, true)) : '',
                'status' => ucfirst($v->status),
                'status_color' => $this->color($v->status),
            ]);

        return $this->ok(['history' => $visits], 'Visit history');
    }

    /** POST /visit/create {date, title, description, user_id?} */
    public function store(Request $request)
    {
        $request->validate(['title' => 'required|string|max:255', 'date' => 'required']);
        $v = Visit::create([
            'user_id' => $this->targetUser($request)->id,
            'title' => $request->title,
            'description' => $request->input('description'),
            'date' => $this->date($request->date)->toDateString(),
            'status' => 'created',
        ]);
        if ($v->user_id !== $request->user()->id) {
            Notifier::send($v->user_id, 'New visit assigned', $v->title, 'visit', $request->user()->id, $v->id);
        }

        return $this->ok($this->brief($v), 'Visit created successfully');
    }

    /** POST /visit/update {id, title, description} */
    public function update(Request $request)
    {
        $v = $this->mine($request, $request->input('id'));
        $v->update(array_filter($request->only('title', 'description'), fn ($x) => $x !== null));

        return $this->ok($this->brief($v), 'Visit updated successfully');
    }

    /** GET /visit/show/{id} */
    public function show(Request $request, int $id)
    {
        $v = Visit::with('images', 'notes', 'schedules')->findOrFail($id);
        if ($v->user_id !== $request->user()->id && ! $request->user()->canApprove()) {
            return $this->fail('Not allowed', 403);
        }
        $next = self::FLOW[$v->status] ?? null;

        return $this->ok([
            'id' => $v->id,
            'title' => $v->title,
            'date' => $v->date->format('d M Y'),
            'description' => (string) $v->description,
            'status' => ucfirst($v->status),
            'status_color' => $this->color($v->status),
            'images' => $v->images->map(fn ($i) => ['id' => $i->id, 'file_id' => $i->id, 'file_path' => Files::url($i->file_path)]),
            'notes' => $v->notes->map(fn ($n) => [
                'note' => $n->note,
                'status' => ucfirst((string) $n->status),
                'status_color' => $this->color((string) $n->status),
                'date_time' => $n->created_at->format('d M Y, h:i A'),
            ]),
            'schedules' => $v->schedules->map(fn ($s) => [
                'title' => (string) $s->title,
                'latitude' => (string) $s->latitude,
                'longitude' => (string) $s->longitude,
                'note' => (string) $s->note,
                'status' => ucfirst((string) $s->status),
                'status_color' => $this->color((string) $s->status),
                'date_time' => $s->created_at->format('d M Y, h:i A'),
            ]),
            'next_status' => ['status' => $next ?? '', 'status_text' => $next ? self::TEXT[$next] : ''],
        ], 'Visit details', ['env' => app()->environment(), 'status' => 200]);
    }

    /** POST /visit/change-status {visit_id, status, latitude, longitude, cancel_note} */
    public function changeStatus(Request $request)
    {
        $v = $this->mine($request, $request->input('visit_id'));
        $status = strtolower((string) $request->input('status'));

        if ($status === 'cancelled') {
            if ($v->status === 'completed') {
                return $this->fail('A completed visit cannot be cancelled.', 400);
            }
            $v->update(['status' => 'cancelled', 'cancel_note' => $request->input('cancel_note')]);
        } else {
            if ((self::FLOW[$v->status] ?? null) !== $status) {
                return $this->fail('Invalid status change.', 400);
            }
            $v->update(['status' => $status] + match ($status) {
                'started' => ['started_at' => now()],
                'reached' => ['reached_at' => now()],
                default => [],
            });
        }

        VisitSchedule::create([
            'visit_id' => $v->id,
            'title' => ucfirst($status),
            'latitude' => $request->input('latitude'),
            'longitude' => $request->input('longitude'),
            'note' => $request->input('cancel_note'),
            'status' => $status,
        ]);

        return $this->ok(null, 'Visit '.$status);
    }

    /** POST /visit/create-note {visit_id, note} */
    public function note(Request $request)
    {
        $request->validate(['note' => 'required|string']);
        $v = $this->mine($request, $request->input('visit_id'));
        VisitNote::create(['visit_id' => $v->id, 'note' => $request->note, 'status' => $v->status]);

        return $this->ok(null, 'Note added');
    }

    /** POST /visit/create-schedule {visit_id, date, latitude, longitude, note} — reschedule */
    public function reschedule(Request $request)
    {
        $v = $this->mine($request, $request->input('visit_id'));
        $date = $this->date($request->input('date'));
        $v->update(['date' => $date->toDateString(), 'status' => in_array($v->status, ['cancelled', 'completed'], true) ? $v->status : 'created']);
        VisitSchedule::create([
            'visit_id' => $v->id,
            'title' => 'Rescheduled to '.$date->format('d M Y'),
            'latitude' => $request->input('latitude'),
            'longitude' => $request->input('longitude'),
            'note' => $request->input('note'),
            'status' => 'rescheduled',
        ]);

        return $this->ok(null, 'Visit rescheduled');
    }

    /** POST /visit/image-upload {id, visit_image} */
    public function image(Request $request)
    {
        $request->validate(['visit_image' => 'required|image|max:5120']);
        $v = $this->mine($request, $request->input('id'));
        $path = Files::store($request->file('visit_image'), 'visit');
        VisitImage::create(['visit_id' => $v->id, 'file_path' => $path]);

        return $this->ok(Files::url($path), 'Image uploaded');
    }
}
