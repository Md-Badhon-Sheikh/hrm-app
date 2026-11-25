<?php

use App\Http\Controllers\Api\PayrollController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

// Signed link used by the app's PDF viewer (it downloads without the auth token).
Route::get('payslip/{payslip}/pdf', [PayrollController::class, 'pdf'])->name('payslip.pdf')->middleware('signed');

// TEMPORARY diagnostics for the live server (remove after the 500 error is fixed).
Route::get('__diag/c6c077c80ab1e341340c581eb158a8bc', function () {
    $out = [
        'php' => PHP_VERSION,
        'extensions' => collect(['intl', 'gd', 'mbstring', 'fileinfo', 'pdo_mysql', 'openssl', 'zip', 'bcmath', 'ctype', 'tokenizer', 'xml'])
            ->mapWithKeys(fn ($e) => [$e => extension_loaded($e)])->all(),
        'memory_limit' => ini_get('memory_limit'),
        'writable' => [
            'storage/logs' => is_writable(storage_path('logs')),
            'storage/framework/views' => is_writable(storage_path('framework/views')),
            'storage/framework/cache' => is_writable(storage_path('framework/cache')),
            'storage/framework/sessions' => is_writable(storage_path('framework/sessions')),
            'bootstrap/cache' => is_writable(base_path('bootstrap/cache')),
        ],
        'db' => [
            'driver' => config('database.default'),
            'version' => rescue(fn () => \Illuminate\Support\Facades\DB::selectOne('select version() as v')->v, 'n/a', false),
            'sql_mode' => rescue(fn () => \Illuminate\Support\Facades\DB::selectOne('select @@sql_mode as m')->m, 'n/a', false),
        ],
        'cached' => [
            'config' => file_exists(base_path('bootstrap/cache/config.php')),
            'routes' => count(glob(base_path('bootstrap/cache/routes-*.php')) ?: []) > 0,
        ],
    ];

    foreach (['/admin/employees', '/admin/departments'] as $path) {
        try {
            $user = \App\Models\User::where('is_admin', true)->orWhere('is_hr', true)->firstOrFail();
            auth()->login($user);
            $res = app(\Illuminate\Contracts\Http\Kernel::class)->handle(\Illuminate\Http\Request::create($path, 'GET'));
            $info = ['status' => $res->getStatusCode()];
            if ($e = $res->exception) {
                $info['exception'] = get_class($e).': '.$e->getMessage();
                $info['at'] = str_replace(base_path(), '', $e->getFile()).':'.$e->getLine();
                $info['trace'] = array_slice(array_map(fn ($f) => str_replace(base_path(), '', ($f['file'] ?? '?').':'.($f['line'] ?? '?')), $e->getTrace()), 0, 8);
            }
        } catch (\Throwable $e) {
            $info = ['exception' => get_class($e).': '.$e->getMessage(), 'at' => str_replace(base_path(), '', $e->getFile()).':'.$e->getLine()];
        }
        $out['pages'][$path] = $info;
    }

    return response()->json($out, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
});
