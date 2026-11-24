<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Appreciation;
use App\Models\Attendance;
use App\Models\Conference;
use App\Models\Content;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Event;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\Notice;
use App\Models\Payslip;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Models\Visit;
use App\Support\Status;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ---------- Settings (all editable in the `settings` table) ----------
        foreach ([
            'company_name' => 'Webase Solutions',
            'office_start' => '09:00',
            'office_end' => '18:00',
            'late_grace_minutes' => '15',
            'early_grace_minutes' => '0',
            'require_late_reason' => '1',
            'require_early_reason' => '1',
            'weekends' => 'Friday',
            'multi_checkin' => '0',
            'location_service' => '1',
            'ip_restriction' => '0',
            'allowed_ips' => '',
            'single_device_login' => '0',
            'app_theme' => 'app_theme_1',
            'currency_code' => 'BDT',
            'app_sync_time' => '5',
            'live_data_store_time' => '5',
            'barikoi_key' => '',
            'hidden_menus' => '',
        ] as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        // ---------- Master data ----------
        $depts = collect(['Management', 'Human Resources', 'Engineering', 'Design', 'Marketing', 'Accounts'])
            ->mapWithKeys(fn ($t) => [$t => Department::create(['title' => $t])->id]);
        $desigs = collect(['CEO', 'HR Manager', 'Project Manager', 'Software Engineer', 'UI/UX Designer', 'Marketing Executive', 'Accountant'])
            ->mapWithKeys(fn ($t) => [$t => Designation::create(['title' => $t])->id]);

        foreach ([['Casual Leave', 10, '#0D6EFD'], ['Sick Leave', 14, '#DC2626'], ['Annual Leave', 20, '#16A34A'], ['Maternity Leave', 112, '#9333EA']] as [$n, $d, $c]) {
            LeaveType::create(['name' => $n, 'days' => $d, 'color_code' => $c]);
        }
        foreach (['Travel', 'Food', 'Office Supplies', 'Internet', 'Client Meeting', 'Others'] as $c) {
            ExpenseCategory::create(['name' => $c]);
        }

        // ---------- Users (password for all demo users: 12345678) ----------
        $mk = fn (array $a) => User::create($a + [
            'password' => '12345678',
            'joining_date' => now()->subYears(2)->toDateString(),
            'employee_type' => 'Permanent',
            'gender' => 'Male',
            'nationality' => 'Bangladeshi',
            'basic_salary' => 50000,
        ]);

        $admin = $mk(['name' => 'Admin', 'email' => 'admin@hrm.test', 'phone' => '01700000001', 'is_admin' => true,
            'department_id' => $depts['Management'], 'designation_id' => $desigs['CEO'], 'employee_id' => 'EMP-001', 'basic_salary' => 150000]);
        $hr = $mk(['name' => 'Nusrat Jahan', 'email' => 'hr@hrm.test', 'phone' => '01700000002', 'is_hr' => true, 'gender' => 'Female',
            'department_id' => $depts['Human Resources'], 'designation_id' => $desigs['HR Manager'], 'employee_id' => 'EMP-002', 'manager_id' => $admin->id, 'basic_salary' => 80000]);
        $pm = $mk(['name' => 'Rafiq Ahmed', 'email' => 'manager@hrm.test', 'phone' => '01700000003',
            'department_id' => $depts['Engineering'], 'designation_id' => $desigs['Project Manager'], 'employee_id' => 'EMP-003', 'manager_id' => $admin->id, 'basic_salary' => 90000]);
        $staff = collect([
            ['Tanvir Hasan', 'employee@hrm.test', 'Engineering', 'Software Engineer', 'Male'],
            ['Sadia Islam', 'sadia@hrm.test', 'Design', 'UI/UX Designer', 'Female'],
            ['Arif Chowdhury', 'arif@hrm.test', 'Marketing', 'Marketing Executive', 'Male'],
            ['Mitu Akter', 'mitu@hrm.test', 'Accounts', 'Accountant', 'Female'],
        ])->map(fn ($s, $i) => $mk([
            'name' => $s[0], 'email' => $s[1], 'phone' => '0170000001'.$i, 'gender' => $s[4],
            'department_id' => $depts[$s[2]], 'designation_id' => $desigs[$s[3]], 'employee_id' => 'EMP-01'.$i, 'manager_id' => $pm->id,
            'birth_date' => $i === 0 ? now()->subYears(27)->toDateString() : now()->subYears(25 + $i)->subDays(40 * $i)->toDateString(),
            'blood_group' => ['A+', 'B+', 'O+', 'AB+'][$i],
        ]));
        $emp = $staff->first();

        // ---------- Attendance for the last 20 days ----------
        foreach ($staff->push($pm)->push($hr) as $u) {
            foreach (range(20, 1) as $back) {
                $d = today()->subDays($back);
                if ($d->format('l') === 'Friday' || random_int(1, 10) === 1) {
                    continue;
                }
                $late = random_int(1, 5) === 1;
                $in = $d->copy()->setTime(9, $late ? random_int(20, 50) : random_int(0, 14));
                $out = $d->copy()->setTime(18, random_int(0, 40));
                Attendance::create([
                    'user_id' => $u->id, 'date' => $d->toDateString(), 'check_in' => $in, 'check_out' => $out,
                    'in_status' => $late ? 'L' : 'OT', 'late_time' => $late ? $in->minute : 0, 'late_reason' => $late ? 'Traffic jam' : null,
                    'out_status' => 'LT', 'check_in_location' => 'Mohammadpur, Dhaka', 'check_out_location' => 'Mohammadpur, Dhaka',
                ]);
            }
        }

        // ---------- Leave ----------
        LeaveRequest::create(['user_id' => $emp->id, 'leave_type_id' => 1, 'apply_date' => today()->subDays(10), 'leave_from' => today()->subDays(8), 'leave_to' => today()->subDays(7), 'days' => 2, 'reason' => 'Family program', 'status_id' => Status::APPROVED, 'approved_by' => $pm->id]);
        LeaveRequest::create(['user_id' => $emp->id, 'leave_type_id' => 2, 'apply_date' => today(), 'leave_from' => today()->addDays(3), 'leave_to' => today()->addDays(3), 'days' => 1, 'reason' => 'Doctor appointment', 'status_id' => Status::PENDING, 'substitute_id' => $staff[1]->id]);
        LeaveRequest::create(['user_id' => $staff[1]->id, 'leave_type_id' => 3, 'apply_date' => today(), 'leave_from' => today()->addDays(10), 'leave_to' => today()->addDays(14), 'days' => 5, 'reason' => 'Trip to Cox\'s Bazar', 'status_id' => Status::PENDING]);

        // ---------- Visit / meeting / appointment / conference ----------
        Visit::create(['user_id' => $emp->id, 'title' => 'Client site visit - TMSS', 'description' => 'Collect website requirements', 'date' => today()]);
        Visit::create(['user_id' => $emp->id, 'title' => 'Server room check', 'date' => today()->subDays(3), 'status' => 'completed', 'started_at' => now()->subDays(3)->setTime(11, 0), 'reached_at' => now()->subDays(3)->setTime(11, 40)]);

        $meeting = Meeting::create(['user_id' => $pm->id, 'title' => 'Sprint planning', 'description' => 'Plan next two weeks', 'location' => 'Conference room', 'date' => today()->addDay(), 'start_at' => '11:00:00', 'end_at' => '12:00:00']);
        foreach ($staff as $s) {
            MeetingParticipant::create(['meeting_id' => $meeting->id, 'user_id' => $s->id]);
        }
        Appointment::create(['created_by' => $emp->id, 'appoinment_with' => $hr->id, 'title' => 'Salary discussion', 'location' => 'HR room', 'date' => today()->addDays(2), 'start_at' => '15:00:00', 'end_at' => '15:30:00']);
        $conf = Conference::create(['created_by' => $pm->id, 'title' => 'Weekly all-hands', 'start' => now()->addDay()->setTime(16, 0), 'end' => now()->addDay()->setTime(17, 0), 'room_id' => 'webase-all-hands']);
        $conf->members()->sync($staff->pluck('id')->push($pm->id, $hr->id));

        // ---------- Notice / events / content ----------
        Notice::create(['user_id' => $hr->id, 'subject' => 'Office will remain closed on Victory Day', 'description' => 'Office is closed on 16 December. Enjoy the holiday!', 'date' => today()]);
        Notice::create(['user_id' => $hr->id, 'subject' => 'New attendance policy', 'description' => 'Please check in before 9:15 AM. Late check-ins need a reason.', 'date' => today()->subDays(5)]);
        Event::create(['title' => 'Victory Day', 'type' => 'holiday', 'start_date' => now()->setDate(now()->year, 12, 16), 'end_date' => now()->setDate(now()->year, 12, 16)]);
        Event::create(['title' => 'Annual picnic', 'description' => 'Company picnic at Gazipur', 'type' => 'event', 'start_date' => today()->addDays(20), 'end_date' => today()->addDays(20)]);

        foreach ([
            ['privacy-policy', 'Privacy Policy', 'We only collect the data needed to run attendance, leave and payroll. Location is used for check-in and live tracking during office hours.'],
            ['terms-of-use', 'Terms of Use', 'This app is for employees only. Do not share your login with anyone.'],
            ['support-24-7', 'Support', 'Need help? Call HR or open a support ticket from the app.'],
            ['about-us', 'About Us', 'Webase Solutions — full-stack web development agency, Dhaka.'],
            ['contact-us', 'Contact Us', 'hello@webase.com.bd'],
        ] as [$slug, $title, $body]) {
            Content::create(['slug' => $slug, 'type' => $slug, 'title' => $title, 'content' => "<p>$body</p>", 'meta_title' => $title]);
        }

        // ---------- Expense / payroll / tasks / appreciation ----------
        Expense::create(['user_id' => $emp->id, 'expense_category_id' => 1, 'date' => today()->subDays(2), 'amount' => 450, 'remarks' => 'Uber to client office']);
        Expense::create(['user_id' => $emp->id, 'expense_category_id' => 2, 'date' => today()->subDay(), 'amount' => 320, 'remarks' => 'Lunch with client']);

        foreach (User::all() as $u) {
            foreach ([1, 2, 3] as $back) {
                Payslip::create(['user_id' => $u->id, 'month' => now()->subMonths($back)->format('Y-m'), 'salary' => $u->basic_salary]);
            }
        }

        $t1 = Task::create(['created_by' => $pm->id, 'supervisor_id' => $pm->id, 'title' => 'HRM API integration', 'description' => 'Connect the mobile app to the new Laravel backend', 'start_date' => today()->subDays(3), 'end_date' => today()->addDays(7), 'priority_id' => 1, 'status_id' => Status::TASK_IN_PROGRESS, 'progress' => 40]);
        $t1->members()->sync([$emp->id, $staff[1]->id]);
        $t2 = Task::create(['created_by' => $pm->id, 'supervisor_id' => $pm->id, 'title' => 'Landing page design', 'start_date' => today()->subDays(10), 'end_date' => today()->subDays(2), 'priority_id' => 2, 'status_id' => Status::TASK_COMPLETED, 'progress' => 100]);
        $t2->members()->sync([$staff[1]->id]);

        Appreciation::create(['user_id' => $emp->id, 'appreciate_by' => $pm->id, 'message' => 'Great job shipping the release on time!']);
    }
}
