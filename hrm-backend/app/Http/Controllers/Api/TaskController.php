<?php

namespace App\Http\Controllers\Api;

use App\Models\Task;
use App\Support\Notifier;
use App\Support\Status;
use Illuminate\Http\Request;

class TaskController extends ApiController
{
    private function visible(Request $request)
    {
        $u = $request->user();

        return Task::with('members.designation', 'members.department', 'supervisor')
            ->where(fn ($q) => $q->where('created_by', $u->id)->orWhere('supervisor_id', $u->id)
                ->orWhereHas('members', fn ($m) => $m->where('users.id', $u->id)));
    }

    private function collection(Task $t): array
    {
        return [
            'id' => $t->id,
            'title' => $t->title,
            'date_range' => $t->start_date->format('d M').' - '.$t->end_date->format('d M Y'),
            'start_date' => $t->start_date->toIso8601String(),
            'end_date' => $t->end_date->toIso8601String(),
            'users_count' => $t->members->count(),
            'members' => $t->members->map(fn ($m) => $m->card())->values(),
            'color' => Status::priorityColor((int) $t->priority_id),
        ];
    }

    /** GET /tasks — dashboard */
    public function dashboard(Request $request)
    {
        $all = $this->visible($request)->latest('id')->get();
        $img = fn ($f) => asset('assets/app/task/'.$f);
        $stats = [
            ['count' => $all->count(), 'text' => 'Total Tasks', 'status' => 'all', 'image' => $img('task.png')],
            ['count' => $all->where('status_id', Status::TASK_IN_PROGRESS)->count(), 'text' => 'In Progress', 'status' => (string) Status::TASK_IN_PROGRESS, 'image' => $img('calender.png')],
            ['count' => $all->where('status_id', Status::TASK_COMPLETED)->count(), 'text' => 'Completed', 'status' => (string) Status::TASK_COMPLETED, 'image' => $img('complete_task.png')],
            ['count' => $all->where('status_id', Status::TASK_NOT_STARTED)->count(), 'text' => 'Not Started', 'status' => (string) Status::TASK_NOT_STARTED, 'image' => $img('cancel.png')],
        ];

        return $this->ok([
            'staticstics' => $stats,
            'tasks_in_collection' => $all->where('status_id', '!=', Status::TASK_COMPLETED)->take(10)->map(fn ($t) => $this->collection($t))->values(),
            'complete_tasks_collection' => $all->where('status_id', Status::TASK_COMPLETED)->take(10)->map(fn ($t) => $this->collection($t))->values(),
        ], 'Task dashboard');
    }

    /** GET /tasks/list?keyword=&status=26&priority= */
    public function list(Request $request)
    {
        $base = $this->visible($request);
        if ($k = trim((string) $request->query('keyword'))) {
            $base->where('title', 'like', "%$k%");
        }
        if ($s = (int) $request->query('status')) {
            $s === Status::TASK_IN_PROGRESS
                ? $base->where('status_id', '!=', Status::TASK_COMPLETED)
                : $base->where('status_id', $s);
        }
        $counts = (clone $base)->selectRaw('priority_id, count(*) c')->groupBy('priority_id')->pluck('c', 'priority_id');
        if ($p = (int) $request->query('priority')) {
            $base->where('priority_id', $p);
        }
        $page = $base->latest('id')->paginate(20);

        $priorities = collect(Status::PRIORITIES)->map(fn ($v, $id) => [
            'title' => $v[0], 'id' => $id, 'count' => (int) ($counts[$id] ?? 0), 'color' => $v[1],
        ])->values();

        return $this->ok([
            'priorities' => $priorities,
            'task_list_collection' => [
                'tasks' => collect($page->items())->map(fn ($t) => $this->collection($t)),
                'pagination' => $this->pagination($page),
            ],
        ], 'Task list');
    }

    private function details(Task $t): array
    {
        $t->loadMissing('members.designation', 'members.department', 'supervisor');

        return [
            'members' => $t->members->map(fn ($m) => $m->card())->values(),
            'priorities' => collect(Status::PRIORITIES)->map(fn ($v, $id) => ['title' => $v[0], 'id' => $id])->values(),
            'task_details' => [
                'id' => $t->id,
                'title' => $t->title,
                'db_start_date' => $t->start_date->toDateString(),
                'start_date' => $t->start_date->format('d M Y'),
                'db_end_date' => $t->end_date->toIso8601String(),
                'end_date' => $t->end_date->format('d M Y'),
                'date' => $t->start_date->format('d M').' - '.$t->end_date->format('d M Y'),
                'supervisor' => $t->supervisor?->name,
                'users_count' => $t->members->count(),
                'actual_count' => $t->members->count(),
                'discussions_count' => 0,
                'files_count' => 0,
                'progress' => (int) $t->progress,
                'is_completed' => (int) $t->status_id === Status::TASK_COMPLETED,
                'priority_id' => (int) $t->priority_id,
                'priority' => Status::priorityName((int) $t->priority_id),
                'status_id' => (int) $t->status_id,
                'status' => Status::name((int) $t->status_id),
                'color' => Status::priorityColor((int) $t->priority_id),
                'description' => (string) $t->description,
                'files' => [],
                'discussion' => [],
            ],
        ];
    }

    /** GET /tasks/{id} */
    public function show(Request $request, int $id)
    {
        return $this->ok($this->details($this->visible($request)->findOrFail($id)), 'Task details');
    }

    /** GET /tasks/change-status?task_id=&type=1&change_to=27   (type 1 = status, 2 = priority) */
    public function changeStatus(Request $request)
    {
        $t = $this->visible($request)->findOrFail($request->query('task_id'));
        $to = (int) $request->query('change_to');
        if ((int) $request->query('type', 1) === 2) {
            $t->update(['priority_id' => $to]);
        } else {
            $t->update(['status_id' => $to, 'progress' => $to === Status::TASK_COMPLETED ? 100 : $t->progress]);
            if ($t->created_by !== $request->user()->id) {
                Notifier::send($t->created_by, 'Task updated', "{$t->title} is now ".Status::name($to), 'task', $request->user()->id, $t->id);
            }
        }

        return $this->ok($this->details($t->fresh()), 'Task updated');
    }

    /** POST /tasks/create {title, description, start_date, end_date, priority_id, members[]} */
    public function store(Request $request)
    {
        $request->validate(['title' => 'required', 'start_date' => 'required|date', 'end_date' => 'required|date|after_or_equal:start_date']);
        $u = $request->user();
        $t = Task::create([
            'created_by' => $u->id,
            'supervisor_id' => $request->input('supervisor_id', $u->id),
            'title' => $request->title,
            'description' => $request->input('description'),
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'priority_id' => (int) $request->input('priority_id', 2),
            'status_id' => Status::TASK_IN_PROGRESS,
        ]);
        $members = is_string($request->members) ? explode(',', $request->members) : (array) $request->input('members', []);
        $t->members()->sync(array_filter(array_map('intval', $members)));
        $t->members->each(fn ($m) => Notifier::send($m, 'New task assigned', $t->title, 'task', $u->id, $t->id));

        return $this->ok($this->details($t), 'Task created');
    }
}
