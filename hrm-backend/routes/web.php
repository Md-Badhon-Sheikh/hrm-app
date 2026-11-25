<?php

use App\Http\Controllers\Api\PayrollController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

// Signed link used by the app's PDF viewer (it downloads without the auth token).
Route::get('payslip/{payslip}/pdf', [PayrollController::class, 'pdf'])->name('payslip.pdf')->middleware('signed');
