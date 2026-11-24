<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * All HRM tables used by the mobile app API.
 *
 * Status ids follow the mobile app's conventions (see App\Support\Status):
 * 1 = approved/active, 2 = pending, 6 = rejected, 7 = cancelled,
 * 24 = not started, 26 = in progress, 27 = completed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->timestamps();
        });

        Schema::create('designations', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->timestamps();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });

        // ---------------- Attendance ----------------
        Schema::create('attendances', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->default(1);
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->dateTime('check_in')->nullable();
            $t->dateTime('check_out')->nullable();
            $t->string('in_status')->nullable();   // OT = on time, L = late, E = early
            $t->string('out_status')->nullable();  // LT = left timely, LE = left early, LL = left later
            $t->integer('late_time')->default(0);  // minutes
            $t->string('late_reason')->nullable();
            $t->string('early_reason')->nullable();
            $t->tinyInteger('remote_mode_in')->default(0);
            $t->tinyInteger('remote_mode_out')->default(0);
            $t->string('checkin_ip')->nullable();
            $t->string('checkout_ip')->nullable();
            $t->string('check_in_location')->nullable();
            $t->string('check_in_latitude')->nullable();
            $t->string('check_in_longitude')->nullable();
            $t->string('check_in_city')->nullable();
            $t->string('check_in_country_code')->nullable();
            $t->string('check_in_country')->nullable();
            $t->string('check_out_location')->nullable();
            $t->string('check_out_latitude')->nullable();
            $t->string('check_out_longitude')->nullable();
            $t->string('check_out_city')->nullable();
            $t->string('check_out_country_code')->nullable();
            $t->string('check_out_country')->nullable();
            $t->unsignedInteger('status_id')->default(1);
            $t->timestamps();
            $t->index(['user_id', 'date']);
        });

        Schema::create('breaks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->default(1);
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->time('break_time');
            $t->time('back_time')->nullable();
            $t->string('reason')->nullable();
            $t->timestamps();
        });

        Schema::create('live_locations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('latitude')->nullable();
            $t->string('longitude')->nullable();
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->string('country')->nullable();
            $t->decimal('distance', 10, 2)->nullable();
            $t->dateTime('recorded_at')->nullable();
            $t->timestamps();
        });

        // ---------------- Leave ----------------
        Schema::create('leave_types', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedInteger('days')->default(0); // yearly allowance
            $t->string('color_code')->default('#0D6EFD');
            $t->timestamps();
        });

        Schema::create('leave_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->default(1);
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('substitute_id')->nullable();
            $t->date('apply_date');
            $t->date('leave_from');
            $t->date('leave_to');
            $t->unsignedInteger('days')->default(1);
            $t->text('reason')->nullable();
            $t->string('attachment')->nullable();
            $t->unsignedInteger('status_id')->default(2);
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->unsignedBigInteger('referred_by')->nullable();
            $t->timestamps();
        });

        Schema::create('daily_leaves', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->string('leave_type');           // early_leave | late_arrive
            $t->string('approx_time')->nullable();
            $t->text('reason')->nullable();
            $t->string('status')->default('pending'); // pending | approved | rejected
            $t->string('manager_approval')->nullable();
            $t->string('hr_approval')->nullable();
            $t->string('tl_approval_msg')->nullable();
            $t->timestamps();
        });

        // ---------------- Visits ----------------
        Schema::create('visits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->text('description')->nullable();
            $t->date('date');
            $t->string('status')->default('created'); // created, started, reached, completed, cancelled
            $t->dateTime('started_at')->nullable();
            $t->dateTime('reached_at')->nullable();
            $t->text('cancel_note')->nullable();
            $t->timestamps();
        });

        Schema::create('visit_images', function (Blueprint $t) {
            $t->id();
            $t->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $t->string('file_path');
            $t->timestamps();
        });

        Schema::create('visit_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $t->text('note');
            $t->string('status')->nullable();
            $t->timestamps();
        });

        Schema::create('visit_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $t->string('title')->nullable();
            $t->string('latitude')->nullable();
            $t->string('longitude')->nullable();
            $t->text('note')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });

        // ---------------- Meetings / Appointments / Conference ----------------
        Schema::create('meetings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete(); // creator
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('location')->nullable();
            $t->date('date');
            $t->time('start_at')->nullable();
            $t->time('end_at')->nullable();
            $t->string('attachment')->nullable();
            $t->string('status')->default('Pending');
            $t->timestamps();
        });

        Schema::create('meeting_participants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->boolean('is_agree')->default(false);
            $t->boolean('is_present')->default(false);
            $t->dateTime('present_at')->nullable();
            $t->timestamps();
        });

        Schema::create('appointments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $t->foreignId('appoinment_with')->constrained('users')->cascadeOnDelete();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('location')->nullable();
            $t->date('date');
            $t->time('start_at')->nullable();
            $t->time('end_at')->nullable();
            $t->string('attachment')->nullable();
            $t->boolean('is_agree')->default(false);
            $t->boolean('is_present')->default(false);
            $t->dateTime('present_at')->nullable();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('ended_at')->nullable();
            $t->timestamps();
        });

        Schema::create('conferences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $t->string('title');
            $t->dateTime('start');
            $t->dateTime('end');
            $t->string('room_id');
            $t->string('external_link')->nullable();
            $t->timestamps();
        });

        Schema::create('conference_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conference_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
        });

        // ---------------- Notice / Support / Content / Events ----------------
        Schema::create('notices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->string('subject');
            $t->text('description')->nullable();
            $t->date('date');
            $t->string('file')->nullable();
            $t->timestamps();
        });

        Schema::create('support_tickets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('code')->unique();
            $t->string('subject');
            $t->text('description')->nullable();
            $t->string('type')->default('open'); // open | close
            $t->unsignedInteger('priority_id')->default(2); // 1 high, 2 medium, 3 low
            $t->string('file')->nullable();
            $t->timestamps();
        });

        Schema::create('contents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->default(1);
            $t->unsignedBigInteger('user_id')->default(1);
            $t->string('type')->default('page');
            $t->string('title');
            $t->string('slug')->unique();
            $t->longText('content')->nullable();
            $t->string('meta_title')->nullable();
            $t->text('meta_description')->nullable();
            $t->string('keywords')->nullable();
            $t->string('meta_image')->nullable();
            $t->timestamps();
        });

        Schema::create('events', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('type')->default('event'); // event | holiday
            $t->date('start_date');
            $t->date('end_date');
            $t->string('attachment')->nullable();
            $t->timestamps();
        });

        Schema::create('appreciations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();       // receiver
            $t->foreignId('appreciate_by')->constrained('users')->cascadeOnDelete();
            $t->text('message');
            $t->timestamps();
        });

        Schema::create('app_notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('sender_id')->nullable();
            $t->string('title');
            $t->text('body')->nullable();
            $t->string('image')->nullable();
            $t->string('slug')->nullable(); // which screen to open: leave, notice, visit, ...
            $t->dateTime('read_at')->nullable();
            $t->timestamps();
        });

        // ---------------- Expense ----------------
        Schema::create('expense_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('expense_claims', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('invoice_number')->unique();
            $t->date('claim_date');
            $t->text('remarks')->nullable();
            $t->decimal('payable_amount', 12, 2)->default(0);
            $t->decimal('paid_amount', 12, 2)->default(0);
            $t->decimal('due_amount', 12, 2)->default(0);
            $t->string('attachment')->nullable();
            $t->unsignedInteger('status_id')->default(2);
            $t->timestamps();
        });

        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('expense_claim_id')->nullable()->constrained()->nullOnDelete();
            $t->date('date');
            $t->decimal('amount', 12, 2);
            $t->decimal('approved_amount', 12, 2)->nullable();
            $t->text('remarks')->nullable();
            $t->string('reference')->nullable();
            $t->string('attachment')->nullable();
            $t->string('status')->default('pending'); // pending, approved, rejected
            $t->string('payment')->default('unpaid');  // unpaid, paid
            $t->timestamps();
        });

        Schema::create('expense_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('expense_claim_id')->constrained()->cascadeOnDelete();
            $t->date('payment_date');
            $t->decimal('amount', 12, 2);
            $t->timestamps();
        });

        // ---------------- Payroll ----------------
        Schema::create('payslips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('month', 7); // YYYY-MM
            $t->decimal('salary', 12, 2)->default(0);
            $t->boolean('is_calculated')->default(true);
            $t->string('file')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'month']);
        });

        // ---------------- Tasks ----------------
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $t->unsignedBigInteger('supervisor_id')->nullable();
            $t->string('title');
            $t->text('description')->nullable();
            $t->date('start_date');
            $t->date('end_date');
            $t->unsignedInteger('priority_id')->default(2); // 1 high, 2 medium, 3 low, 4 urgent
            $t->unsignedInteger('status_id')->default(24);  // 24 not started, 26 in progress, 27 completed
            $t->unsignedTinyInteger('progress')->default(0);
            $t->timestamps();
        });

        Schema::create('task_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('task_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach ([
            'task_members', 'tasks', 'payslips', 'expense_payments', 'expenses', 'expense_claims',
            'expense_categories', 'app_notifications', 'appreciations', 'events', 'contents',
            'support_tickets', 'notices', 'conference_members', 'conferences', 'appointments',
            'meeting_participants', 'meetings', 'visit_schedules', 'visit_notes', 'visit_images',
            'visits', 'daily_leaves', 'leave_requests', 'leave_types', 'live_locations', 'breaks',
            'attendances', 'settings', 'designations', 'departments',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
