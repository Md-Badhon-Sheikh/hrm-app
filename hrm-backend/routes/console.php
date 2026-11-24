<?php

use App\Models\Department;
use App\Models\Designation;
use App\Models\Payslip;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;

/*
| php artisan hrm:create-user "Full Name" email@company.com secret123 --hr --admin --manager=3
*/
Artisan::command('hrm:create-user {name} {email} {password} {--hr} {--admin} {--manager=} {--department=} {--designation=} {--phone=}', function () {
    $u = User::create([
        'name' => $this->argument('name'),
        'email' => $this->argument('email'),
        'password' => $this->argument('password'),
        'phone' => $this->option('phone'),
        'is_hr' => (bool) $this->option('hr'),
        'is_admin' => (bool) $this->option('admin'),
        'manager_id' => $this->option('manager') ?: null,
        'department_id' => $this->option('department') ? Department::firstOrCreate(['title' => $this->option('department')])->id : null,
        'designation_id' => $this->option('designation') ? Designation::firstOrCreate(['title' => $this->option('designation')])->id : null,
        'joining_date' => today(),
    ]);
    $this->info("Created user #{$u->id} {$u->email}");
})->purpose('Create an employee who can log in to the mobile app');

/*
| php artisan hrm:payslips 2026-11      (defaults to last month)
| Creates/updates one payslip per active employee from users.basic_salary.
*/
Artisan::command('hrm:payslips {month?}', function () {
    $month = $this->argument('month') ? Carbon::createFromFormat('Y-m', $this->argument('month')) : now()->subMonth();
    $n = 0;
    User::where('is_active', true)->each(function (User $u) use ($month, &$n) {
        Payslip::updateOrCreate(['user_id' => $u->id, 'month' => $month->format('Y-m')], ['salary' => $u->basic_salary, 'is_calculated' => true]);
        $n++;
    });
    $this->info("Generated $n payslips for ".$month->format('F Y'));
})->purpose('Generate monthly payslips for all active employees');
