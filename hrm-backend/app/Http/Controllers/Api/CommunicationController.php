<?php

namespace App\Http\Controllers\Api;

use App\Models\AppNotification;
use App\Models\Notice;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Files;
use App\Support\Notifier;
use App\Support\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Notices, support tickets and in-app notifications. */
class CommunicationController extends ApiController
{
    // ---------------- Notices ----------------

    /** POST /notice/list {month?} */
    public function notices(Request $request)
    {
        $u = $request->user();
        $q = Notice::where(fn ($w) => $w->whereNull('department_id')->orWhere('department_id', $u->department_id))->latest('date');
        if ($request->filled('month')) {
            $m = $this->month($request->month);
            $q->whereBetween('date', [$m, $m->copy()->endOfMonth()]);
        }

        return $this->ok(['notices' => ['data' => $q->limit(100)->get()->map(fn ($n) => [
            'id' => $n->id,
            'subject' => $n->subject,
            'description' => (string) $n->description,
            'date' => $n->date->format('d M Y'),
            'file' => Files::url($n->file),
        ])]], 'Notice list');
    }

    /** GET /notice/show/{id} */
    public function notice(int $id)
    {
        $n = Notice::findOrFail($id);

        return $this->ok([
            'id' => $n->id,
            'subject' => $n->subject,
            'description' => (string) $n->description,
            'date' => $n->date->format('d M Y'),
            'file' => Files::url($n->file),
        ], 'Notice details');
    }

    /** POST /notice/add (multipart) — HR/admin only */
    public function addNotice(Request $request)
    {
        $u = $request->user();
        if (! $u->is_admin && ! $u->is_hr) {
            return $this->fail('Only HR or admin can publish notices.', 403, null, self::NULL);
        }
        $request->validate(['subject' => 'required|string', 'file' => 'nullable|file|max:5120']);
        $n = Notice::create([
            'user_id' => $u->id,
            'department_id' => $request->input('department_id') ?: null,
            'subject' => $request->subject,
            'description' => $request->input('description'),
            'date' => $this->date($request->input('date'))->toDateString(),
            'file' => Files::store($request->file('file'), 'notice'),
        ]);

        User::where('is_active', true)->when($n->department_id, fn ($q) => $q->where('department_id', $n->department_id))
            ->where('id', '!=', $u->id)
            ->each(fn ($x) => Notifier::send($x, 'New notice', $n->subject, 'notice', $u->id, $n->id));

        return $this->ok($n->id, 'Notice published');
    }

    /** GET /notice/clear — mark notice notifications as read */
    public function clearNotices(Request $request)
    {
        AppNotification::where('user_id', $request->user()->id)->where('slug', 'notice')->whereNull('read_at')->update(['read_at' => now()]);

        return $this->ok(null, 'Notices cleared');
    }

    // ---------------- Support tickets ----------------

    private function ticket(SupportTicket $t): array
    {
        return [
            'id' => $t->id,
            'code' => $t->code,
            'subject' => $t->subject,
            'description' => (string) $t->description,
            'file' => Files::url($t->file),
            'type_name' => ucfirst($t->type),
            'type_color' => $t->type === 'open' ? '#16A34A' : '#6B7280',
            'priority_name' => Status::priorityName((int) $t->priority_id),
            'priority_color' => Status::priorityColor((int) $t->priority_id),
            'date' => $t->created_at->format('d M Y'),
        ];
    }

    /** POST /support-ticket/list {type: open|close, month} */
    public function tickets(Request $request)
    {
        $q = SupportTicket::where('user_id', $request->user()->id)->latest('id');
        if ($type = strtolower((string) $request->input('type'))) {
            $q->where('type', str_starts_with($type, 'clos') ? 'close' : 'open');
        }
        if ($request->filled('month')) {
            $m = $this->month($request->month);
            $q->whereBetween('created_at', [$m, $m->copy()->endOfMonth()->endOfDay()]);
        }

        return $this->ok(['data' => $q->get()->map(fn ($t) => array_diff_key($this->ticket($t), ['code' => 1, 'description' => 1]))], 'Support tickets');
    }

    /** POST /support-ticket/add (multipart) */
    public function addTicket(Request $request)
    {
        $request->validate(['subject' => 'required|string', 'description' => 'nullable|string', 'attachment_file' => 'nullable|file|max:5120']);
        $u = $request->user();
        $t = SupportTicket::create([
            'user_id' => $u->id,
            'code' => 'TKT-'.strtoupper(Str::random(6)),
            'subject' => $request->subject,
            'description' => $request->input('description'),
            'priority_id' => (int) $request->input('priority_id', 2),
            'file' => Files::store($request->file('attachment_file'), 'support'),
        ]);
        Notifier::hr('New support ticket', "{$u->name}: {$t->subject}", 'support', $u->id, $t->id);

        return $this->ok(null, 'Support ticket created');
    }

    /** GET /support-ticket/show/{id} */
    public function ticketShow(Request $request, int $id)
    {
        $t = SupportTicket::findOrFail($id);
        if ($t->user_id !== $request->user()->id && ! $request->user()->is_hr && ! $request->user()->is_admin) {
            return $this->fail('Not allowed', 403);
        }

        return $this->ok(array_diff_key($this->ticket($t), ['id' => 1]), 'Ticket details');
    }

    // ---------------- Notifications ----------------

    /** GET /user/notification */
    public function notifications(Request $request)
    {
        $items = AppNotification::with('sender')->where('user_id', $request->user()->id)->latest()->limit(100)->get()
            ->map(fn ($n) => [
                'id' => (string) $n->id,
                'sender' => $n->sender?->name ?? config('app.name'),
                'sender_id' => $n->sender_id,
                'title' => $n->title,
                'body' => (string) $n->body,
                'image' => $n->image ?: ($n->sender?->avatarUrl() ?? ''),
                'date' => $n->created_at->diffForHumans(),
                'slag' => (string) $n->slug,
                'is_read' => (bool) $n->read_at,
            ]);

        return $this->ok(['notifications' => $items], 'Notifications');
    }

    /** GET /user/read-notification?notification_id= */
    public function read(Request $request)
    {
        AppNotification::where('user_id', $request->user()->id)->where('id', $request->query('notification_id'))->update(['read_at' => now()]);

        return $this->ok(null, 'Notification marked as read');
    }

    /** GET /user/notification/clear */
    public function clear(Request $request)
    {
        AppNotification::where('user_id', $request->user()->id)->delete();

        return $this->ok(null, 'Notifications cleared');
    }
}
