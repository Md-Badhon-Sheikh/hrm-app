<?php

use App\Http\Controllers\Api\PayrollController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', fn () => redirect('/admin'));

// Signed link used by the app's PDF viewer (it downloads without the auth token).
Route::get('payslip/{payslip}/pdf', [PayrollController::class, 'pdf'])->name('payslip.pdf')->middleware('signed');

// Fallback for uploaded files (avatars, documents). Normally /storage is the
// public/storage symlink served by the web server; on shared hosts the link
// is often missing or not followed, so serve the file from the disk instead.
Route::get('storage/{path}', function (string $path) {
    abort_if(str_contains($path, '..'), 404);

    $disk = Storage::disk('public');
    abort_unless($disk->exists($path), 404);

    return $disk->response($path, null, ['Cache-Control' => 'public, max-age=86400']);
})->where('path', '.+')->name('storage.file');
