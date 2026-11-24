<?php

namespace App\Http\Controllers\Api;

use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\BreakTime;
use App\Models\Content;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Event;
use App\Models\LeaveRequest;
use App\Models\Meeting;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use App\Models\Visit;
use App\Support\Status;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AppController extends ApiController
{
    /** GET /app/base-settings — app-wide config read on splash/home. */
    public function baseSettings(Request $request)
    {
        $user = $request->user();
        $hour = (int) now()->format('H');
        [$wish, $sub, $img] = match (true) {
            $hour < 12 => ['Good Morning', 'Have a productive day!', 'sunrise.svg'],
            $hour < 17 => ['Good Afternoon', 'Keep up the great work!', 'sunrise.svg'],
            default => ['Good Evening', 'Time to wrap up your day.', 'sunrise.svg'],
        };

        [$sh, $sm] = array_map('intval', explode(':', Setting::get('office_start', '09:00')));
        [$eh, $em] = array_map('intval', explode(':', Setting::get('office_end', '18:00')));

        // Running break (if any) — the app resumes the break timer from diff_time.
        $break = BreakTime::where('user_id', $user->id)->whereDate('date', today())->latest('id')->first();
        $onBreak = $break && ! $break->back_time;
        $diff = '';
        if ($onBreak) {
            $secs = now()->diffInSeconds(Carbon::parse($break->date->toDateString().' '.$break->break_time), true);
            $diff = sprintf('%02d:%02d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60), $secs % 60);
        }

        return $this->ok([
            'is_hr' => (bool) $user->is_hr,
            'is_admin' => (bool) $user->is_admin,
            'location_service' => Setting::get('location_service', '1') === '1',
            'app_theme' => Setting::get('app_theme', 'app_theme_1'),
            'is_ip_enabled' => Setting::get('ip_restriction', '0') === '1',
            'time_wish' => ['wish' => $wish, 'sub_title' => $sub, 'image' => asset('assets/app/home_icon/'.$img)],
            'barikoi_api' => [
                'key' => (string) Setting::get('barikoi_key', ''),
                'secret' => (string) Setting::get('barikoi_secret', ''),
                'endpoint' => (string) Setting::get('barikoi_endpoint', 'https://barikoi.xyz/v1/api/search/reverse/'),
                'status_id' => Setting::get('barikoi_key') ? 1 : 0,
            ],
            'time_zone' => config('app.timezone'),
            'multi_checkin' => Setting::get('multi_checkin', '0') === '1',
            'currency_code' => Setting::get('currency_code', 'BDT'),
            'duty_schedule' => [
                'start_time' => ['hour' => $sh, 'min' => $sm, 'sec' => 0],
                'end_time' => ['hour' => $eh, 'min' => $em, 'sec' => 0],
            ],
            'break_status' => [
                'date' => ($break?->date ?? today())->toDateString(),
                'break_time' => $break?->break_time ?? '',
                'back_time' => $break?->back_time ?? '',
                'status' => $onBreak ? 'break_in' : 'break_out',
                'diff_time' => $diff,
            ],
            'live_tracking' => [
                'app_sync_time' => (string) Setting::get('app_sync_time', '5'),          // minutes
                'live_data_store_time' => (string) Setting::get('live_data_store_time', '5'), // minutes
            ],
            'notification_channels' => ['company_1', 'user_'.$user->id],
        ], 'Base settings');
    }

    /** GET /app/home-screen — the menu grid. Slugs must match the app's router. */
    public function menu()
    {
        $items = [
            ['Attendance', 'attendance', 'home_icon/attendance.svg'],
            ['Break', 'break', 'home_icon/break.svg'],
            ['Leave', 'leave', 'home_icon/leave.svg'],
            ['Daily Leave', 'daily-leave', 'menu_drawer_icons/leave.svg'],
            ['Approval', 'approval', 'menu_drawer_icons/team.svg'],
            ['Visit', 'visit', 'menu_report/report_visit.png'],
            ['Meeting', 'meeting', 'home_icon/appointment_banner.svg'],
            ['Appointment', 'appointments', 'menu_report/report_appointment.png'],
            ['Video Conference', 'video_conference', 'menu_drawer_icons/working-mode.svg'],
            ['Notice', 'notice', 'home_icon/notifications.svg'],
            ['Phonebook', 'phonebook', 'menu_drawer_icons/emergency-call.svg'],
            ['Support', 'support', 'menu_drawer_icons/support-policy.svg'],
            ['Expense', 'expense', 'expense/manage-expense.svg'],
            ['Payroll', 'payroll', 'menu_report/report_expense.png'],
            ['Task', 'task', 'menu_drawer_icons/tasks.svg'],
            ['Report', 'report', 'menu_report/report_attendance.png'],
        ];
        $hidden = array_filter(explode(',', (string) Setting::get('hidden_menus', '')));

        $data = [];
        foreach ($items as $i => [$name, $slug, $icon]) {
            if (in_array($slug, $hidden, true)) {
                continue;
            }
            $data[] = ['name' => $name, 'slug' => $slug, 'position' => $i + 1, 'icon' => asset('assets/app/'.$icon)];
        }

        return $this->ok(['data' => $data], 'Menu list');
    }

    /** GET /app/all-contents/{slug} — privacy policy, terms, support policy... */
    public function contents(string $slug)
    {
        $items = Content::where('slug', $slug)->orWhere('type', $slug)->get()->map(fn ($c) => [
            'id' => $c->id,
            'company_id' => (int) $c->company_id,
            'user_id' => (int) $c->user_id,
            'type' => $c->type,
            'title' => $c->title,
            'slug' => $c->slug,
            'content' => (string) $c->content,
            'meta_title' => (string) $c->meta_title,
            'meta_description' => $c->meta_description,
            'keywords' => (string) $c->keywords,
            'meta_image' => (string) $c->meta_image,
        ]);

        return $this->ok(['contents' => $items], 'Contents');
    }

    /** GET /app/get-all-users/{designation?}?keywords= — phonebook. */
    public function allUsers(Request $request, ?int $designation = null)
    {
        $q = User::with('designation', 'department')->where('is_active', true);
        if ($designation) {
            $q->where('designation_id', $designation);
        }
        if ($k = trim((string) $request->query('keywords'))) {
            if ($k !== 'null') {
                $q->where(fn ($w) => $w->where('name', 'like', "%$k%")->orWhere('phone', 'like', "%$k%")->orWhere('email', 'like', "%$k%"));
            }
        }

        return $this->ok(['users' => $q->orderBy('name')->get()->map->card()], 'Users');
    }

    /** GET /user/search/{q} */
    public function search(string $search)
    {
        $users = User::with('designation', 'department')->where('is_active', true)
            ->where(fn ($w) => $w->where('name', 'like', "%$search%")->orWhere('phone', 'like', "%$search%"))
            ->limit(30)->get()->map->card();

        return $this->ok(['users' => $users], 'Users');
    }

    /** GET /user/details/{id} — phonebook details. */
    public function userDetails(int $id)
    {
        $u = User::with('designation', 'department', 'appreciations.by')->findOrFail($id);

        return $this->ok([
            'id' => $u->id,
            'avatar' => $u->avatarUrl(),
            'name' => $u->name,
            'designation' => $u->designation?->title ?? '',
            'email' => $u->email,
            'phone' => (string) $u->phone,
            'department' => $u->department?->title ?? '',
            'birth_date' => $u->birth_date?->format('d M'),
            'blood_group' => $u->blood_group,
            'facebook_link' => $u->facebook_link,
            'linkedin_link' => $u->linkedin_link,
            'instagram_link' => $u->instagram_link,
            'appreciates' => $u->appreciations->map(fn ($a) => [
                'appreciate_by' => $a->by?->name,
                'message' => $a->message,
                'date' => $a->created_at->format('d M Y'),
                'day' => $a->created_at->format('l'),
            ]),
        ], 'User details');
    }

    public function departments()
    {
        return $this->ok(['departments' => Department::orderBy('title')->get(['id', 'title'])], 'Departments');
    }

    public function designations()
    {
        return $this->ok(['designations' => Designation::orderBy('title')->get(['id', 'title'])], 'Designations');
    }

    public function employmentTypes()
    {
        return $this->ok(['types' => ['Permanent', 'Probation', 'Contractual', 'Intern', 'Part Time']], 'Employment types');
    }

    public function bloodGroups()
    {
        return $this->ok(['blood_group' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']], 'Blood groups');
    }

    public function managers()
    {
        $managers = User::where('is_active', true)
            ->where(fn ($q) => $q->where('is_admin', true)->orWhere('is_hr', true)->orWhereIn('id', User::whereNotNull('manager_id')->select('manager_id')))
            ->orderBy('name')->get(['id', 'name']);

        return $this->ok(['managers' => $managers], 'Managers');
    }

    /** POST /user/firebase-token {firebase_token, device_name} */
    public function firebaseToken(Request $request)
    {
        $request->user()->update(['firebase_token' => $request->input('firebase_token')]);

        return $this->ok(null, 'Firebase token updated');
    }

    /** GET /dashboard/statistics — the two stat strips on the home screen. */
    public function statistics(Request $request)
    {
        $u = $request->user();
        $icon = fn ($f) => asset('assets/app/'.$f);
        $monthStart = now()->startOfMonth();

        $today = [
            ['image' => $icon('menu_report/report_appointment.png'), 'title' => 'Appointments', 'slug' => 'appointment',
                'number' => Appointment::whereDate('date', today())->where(fn ($q) => $q->where('created_by', $u->id)->orWhere('appoinment_with', $u->id))->count()],
            ['image' => $icon('home_icon/appointment_banner.svg'), 'title' => 'Meetings', 'slug' => 'meeting',
                'number' => Meeting::whereDate('date', today())->where(fn ($q) => $q->where('user_id', $u->id)->orWhereHas('participants', fn ($p) => $p->where('user_id', $u->id)))->count()],
            ['image' => $icon('menu_report/report_visit.png'), 'title' => 'Visits', 'slug' => 'visit',
                'number' => Visit::where('user_id', $u->id)->whereDate('date', today())->count()],
            ['image' => $icon('home_icon/events.png'), 'title' => 'Birthdays', 'slug' => 'birthday',
                'number' => User::where('is_active', true)->whereNotNull('birth_date')->get()->filter(fn ($x) => $x->birth_date->format('m-d') === now()->format('m-d'))->count()],
        ];

        $present = Attendance::where('user_id', $u->id)->where('date', '>=', $monthStart)->count();
        $late = Attendance::where('user_id', $u->id)->where('date', '>=', $monthStart)->where('in_status', 'L')->count();
        $leaves = LeaveRequest::where('user_id', $u->id)->where('status_id', Status::APPROVED)->where('leave_from', '>=', $monthStart)->sum('days');

        $currentMonth = [
            ['image' => $icon('home_icon/in.svg'), 'title' => 'Present', 'slug' => 'attendance', 'number' => $present],
            ['image' => $icon('home_icon/out.svg'), 'title' => 'Late In', 'slug' => 'attendance', 'number' => $late],
            ['image' => $icon('home_icon/leave.svg'), 'title' => 'Leave', 'slug' => 'leave', 'number' => (int) $leaves],
            ['image' => $icon('menu_drawer_icons/tasks.svg'), 'title' => 'Tasks', 'slug' => 'task',
                'number' => Task::whereHas('members', fn ($q) => $q->where('users.id', $u->id))->where('status_id', '!=', Status::TASK_COMPLETED)->count()],
            ['image' => $icon('menu_drawer_icons/support-policy.svg'), 'title' => 'Support Tickets', 'slug' => 'support_ticket',
                'number' => SupportTicket::where('user_id', $u->id)->where('type', 'open')->count()],
        ];

        return $this->ok(['today' => $today, 'current_month' => $currentMonth], 'Statistics');
    }

    /** POST /upcoming-events/get-list — holidays & events. */
    public function upcomingEvents()
    {
        $items = Event::whereDate('end_date', '>=', today())->orderBy('start_date')->limit(20)->get()->map(fn ($e) => [
            'id' => $e->id,
            'title' => $e->title,
            'description' => (string) $e->description,
            'date' => $e->start_date->format('d M Y'),
            'day' => $e->start_date->format('l'),
            'start_date' => $e->start_date->toDateString(),
            'end_date' => $e->end_date->toDateString(),
            'attachment_file_id' => $e->attachment ? asset('storage/'.$e->attachment) : null,
        ]);

        return $this->ok(['items' => $items], 'Upcoming events', ['env' => app()->environment(), 'status' => 200]);
    }
}
