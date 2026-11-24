<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Base controller: every response uses the envelope the Flutter app parses:
 *   { "result": bool, "message": string, "data": mixed }
 */
abstract class ApiController extends Controller
{
    /** Sentinel: send a real JSON null for "data" (default is an empty object). */
    public const NULL = '__null__';

    /**
     * Many app models do `Data.fromJson(json["data"])` without a null check, so an
     * empty result is sent as `{}` instead of `null` unless self::NULL is passed.
     */
    private static function data($data)
    {
        return $data === self::NULL ? null : ($data ?? new \stdClass);
    }

    protected function ok($data = null, string $message = 'Success', array $extra = []): JsonResponse
    {
        return response()->json(array_merge(['result' => true, 'message' => $message, 'data' => self::data($data)], $extra));
    }

    protected function fail(string $message, int $code = 400, $error = null, $data = null): JsonResponse
    {
        return response()->json(['result' => false, 'message' => $message, 'data' => self::data($data), 'error' => $error], $code);
    }

    /** The user the request is about: user_id if allowed, otherwise the logged-in user. */
    protected function targetUser(Request $request): User
    {
        $me = $request->user();
        $id = (int) $request->input('user_id');
        if ($id && $id !== $me->id && $me->canApprove()) {
            return User::findOrFail($id);
        }

        return $me;
    }

    /** Parse "2026-11", "11-2026", "November 2026" etc. into a month start. */
    protected function month(?string $value): Carbon
    {
        if (! $value) {
            return now()->startOfMonth();
        }
        foreach (['Y-m', 'm-Y', 'Y-m-d', 'F Y', 'M Y', 'Y/m'] as $format) {
            try {
                $d = Carbon::createFromFormat($format, trim($value));
                if ($d !== false) {
                    return $d->startOfMonth();
                }
            } catch (\Throwable) {
            }
        }
        try {
            return Carbon::parse($value)->startOfMonth();
        } catch (\Throwable) {
            return now()->startOfMonth();
        }
    }

    protected function date(?string $value): Carbon
    {
        try {
            return $value ? Carbon::parse($value)->startOfDay() : today();
        } catch (\Throwable) {
            return today();
        }
    }

    protected function pagination(LengthAwarePaginator $p): array
    {
        return [
            'total' => $p->total(),
            'count' => $p->count(),
            'per_page' => $p->perPage(),
            'current_page' => $p->currentPage(),
            'total_pages' => $p->lastPage(),
            'total_page' => $p->lastPage(),
        ];
    }

    protected function links(LengthAwarePaginator $p): array
    {
        return [
            'first' => $p->url(1),
            'last' => $p->url($p->lastPage()),
            'prev' => $p->previousPageUrl(),
            'next' => $p->nextPageUrl(),
        ];
    }

    protected static function duration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        return sprintf('%02d:%02d', $h, $m);
    }
}
