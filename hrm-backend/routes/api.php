<?php

use App\Http\Controllers\Api\AppController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommunicationController;
use App\Http\Controllers\Api\DailyLeaveController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\MeetingController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\VisitController;
use Illuminate\Support\Facades\Route;

/*
| Mobile app API — mounted at /api/V11 (see bootstrap/app.php) so the
| Flutter app only needs its base URL changed.
*/

// ---------- Public ----------
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
Route::post('check-token', [AuthController::class, 'checkToken']);
Route::post('reset-password', [AuthController::class, 'sendResetCode'])->middleware('throttle:auth');
Route::post('change-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);

    // ---------- App / settings ----------
    Route::prefix('app')->controller(AppController::class)->group(function () {
        Route::get('base-settings', 'baseSettings');
        Route::get('home-screen', 'menu');
        Route::get('all-contents/{slug}', 'contents');
        Route::get('get-all-users/{designation?}', 'allUsers')->whereNumber('designation');
        Route::get('get-department', 'departments');
        Route::get('get-designation', 'designations');
        Route::get('get-employment-type', 'employmentTypes');
        Route::get('get-blood-group', 'bloodGroups');
        Route::get('get-manager', 'managers');
    });
    Route::get('dashboard/statistics', [AppController::class, 'statistics']);
    Route::post('upcoming-events/get-list', [AppController::class, 'upcomingEvents']);

    // ---------- User ----------
    Route::prefix('user')->group(function () {
        Route::get('search/{search}', [AppController::class, 'search']);
        Route::get('details/{id}', [AppController::class, 'userDetails'])->whereNumber('id');
        Route::post('firebase-token', [AppController::class, 'firebaseToken']);
        Route::post('password-update', [AuthController::class, 'updatePassword']);
        Route::post('avatar-update', [ProfileController::class, 'avatar']);

        Route::post('profile/appreciates', [ProfileController::class, 'appreciates']);
        Route::post('profile/update/official', [ProfileController::class, 'updateOfficial']);
        Route::post('profile/update/personal', [ProfileController::class, 'updatePersonal']);
        Route::post('profile/update/financial', [ProfileController::class, 'updateFinancial']);
        Route::post('profile/update/emergency', [ProfileController::class, 'updateEmergency']);
        Route::post('profile/{slug}', [ProfileController::class, 'show'])->whereIn('slug', ['official', 'personal', 'financial', 'emergency']);

        // Attendance & breaks
        Route::prefix('attendance')->controller(AttendanceController::class)->group(function () {
            Route::post('check-in', 'checkIn');
            Route::match(['patch', 'post'], 'check-out/{id?}', 'checkOut');
            Route::post('late-in-reason/{id}', 'lateReason');
            Route::post('get-checkin-checkout-status', 'status');
            Route::post('break-back/{slug}', 'breakToggle')->whereIn('slug', ['start', 'back']);
            Route::post('break-status', 'breakStatus');
            Route::post('break-history', 'breakHistory');
            Route::post('live-location-store', 'liveLocation');
        });

        // Leave
        Route::prefix('leave')->controller(LeaveController::class)->group(function () {
            Route::post('summary', 'summary');
            Route::post('available', 'available');
            Route::post('list/view', 'list');
            Route::post('details/{id}', 'details');
            Route::post('request', 'store');
            Route::post('request/update/{id}', 'update');
            Route::get('request/cancel/{id}', 'cancel');
            Route::post('approval/list/view', 'approvalList');
            Route::get('approval/status-change/{id}/{type}', 'changeStatus');
        });

        // Notifications
        Route::get('notification', [CommunicationController::class, 'notifications']);
        Route::get('notification/clear', [CommunicationController::class, 'clear']);
        Route::get('read-notification', [CommunicationController::class, 'read']);
    });

    Route::post('appreciate/create', [ProfileController::class, 'appreciate']);

    // ---------- Daily leave ----------
    Route::prefix('daily-leave')->controller(DailyLeaveController::class)->group(function () {
        Route::post('store', 'store');
        Route::post('leave-list', 'summary');
        Route::post('staff-list-view', 'list');
        Route::post('single-view', 'show');
        Route::post('approve-reject', 'approve');
    });

    // ---------- Visit ----------
    Route::prefix('visit')->controller(VisitController::class)->group(function () {
        Route::get('list', 'index');
        Route::post('history', 'history');
        Route::post('create', 'store');
        Route::post('update', 'update');
        Route::get('show/{id}', 'show');
        Route::post('change-status', 'changeStatus');
        Route::post('create-note', 'note');
        Route::post('create-schedule', 'reschedule');
        Route::post('image-upload', 'image');
    });

    // ---------- Meeting / Appointment / Conference ----------
    Route::post('meeting', [MeetingController::class, 'meetings']);
    Route::post('meeting/create', [MeetingController::class, 'storeMeeting']);
    Route::get('meeting/show/{id}', [MeetingController::class, 'showMeeting']);
    Route::get('appoinment/get-list', [MeetingController::class, 'upcomingAppointments']);
    Route::post('appoinment/get-list', [MeetingController::class, 'appointments']);
    Route::post('appoinment/create', [MeetingController::class, 'storeAppointment']);
    Route::get('appoinment/details/{id}', [MeetingController::class, 'showAppointment']);
    Route::get('conference/my-meeting', [MeetingController::class, 'conferences']);
    Route::post('conference/create', [MeetingController::class, 'storeConference']);

    // ---------- Notice / Support ----------
    Route::post('notice/list', [CommunicationController::class, 'notices']);
    Route::get('notice/show/{id}', [CommunicationController::class, 'notice']);
    Route::post('notice/add', [CommunicationController::class, 'addNotice']);
    Route::get('notice/clear', [CommunicationController::class, 'clearNotices']);
    Route::post('support-ticket/list', [CommunicationController::class, 'tickets']);
    Route::post('support-ticket/add', [CommunicationController::class, 'addTicket']);
    Route::get('support-ticket/show/{id}', [CommunicationController::class, 'ticketShow']);

    // ---------- Expense ----------
    Route::prefix('accounts/expense')->controller(ExpenseController::class)->group(function () {
        Route::get('category-list', 'categories');
        Route::post('add', 'store');
        Route::post('list', 'legacyList');
        Route::get('view/{id}', 'legacyShow');
    });
    Route::prefix('expense')->controller(ExpenseController::class)->group(function () {
        Route::post('list', 'list');
        Route::get('single-expense/{id}', 'show');
        Route::post('update/{id}', 'update');
        Route::delete('delete/{id}', 'destroy');
        Route::post('send-claim', 'sendClaim');
        Route::post('claim-history', 'claims');
        Route::post('claim-details/{id}', 'claim');
        Route::post('payment-history', 'payments');
    });

    // ---------- Tasks ----------
    Route::prefix('tasks')->controller(TaskController::class)->group(function () {
        Route::get('/', 'dashboard');
        Route::get('list', 'list');
        Route::get('change-status', 'changeStatus');
        Route::post('create', 'store');
        Route::get('{id}', 'show')->whereNumber('id');
    });

    // ---------- Reports & payroll ----------
    Route::prefix('report')->group(function () {
        Route::post('attendance/particular-month/{userId}', [ReportController::class, 'monthly']);
        Route::post('attendance/particular-date', [ReportController::class, 'daily']);
        Route::post('attendance/date-summary', [ReportController::class, 'dateSummary']);
        Route::post('attendance/summary-to-list', [ReportController::class, 'summaryList']);
        Route::post('break/date-summary', [ReportController::class, 'breakSummary']);
        Route::post('break/user-break-history', [AttendanceController::class, 'breakHistory']);
        Route::post('leave/date-summary', [ReportController::class, 'leaveSummary']);
        Route::post('leave/date-wise-leave', [ReportController::class, 'leaveList']);
        Route::post('payslip/list', [PayrollController::class, 'list']);
    });
});
