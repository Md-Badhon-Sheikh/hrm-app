<?php

namespace App\Support;

/**
 * Status ids + labels + colours exactly as the mobile app expects them.
 */
class Status
{
    public const APPROVED = 1;
    public const PENDING = 2;
    public const REJECTED = 6;
    public const CANCELLED = 7;
    public const REFERRED = 8;

    public const TASK_NOT_STARTED = 24;
    public const TASK_ON_HOLD = 25;
    public const TASK_IN_PROGRESS = 26;
    public const TASK_COMPLETED = 27;

    public const PRIORITIES = [
        1 => ['High', '#FF0000'],
        2 => ['Medium', '#FFA500'],
        3 => ['Low', '#008000'],
        4 => ['Urgent', '#8B0000'],
    ];

    public static function name(int $id): string
    {
        return match ($id) {
            self::APPROVED => 'Approved',
            self::PENDING => 'Pending',
            self::REJECTED => 'Rejected',
            self::CANCELLED => 'Cancelled',
            self::REFERRED => 'Referred',
            self::TASK_NOT_STARTED => 'Not Started',
            self::TASK_ON_HOLD => 'On Hold',
            self::TASK_IN_PROGRESS => 'In Progress',
            self::TASK_COMPLETED => 'Completed',
            default => 'Unknown',
        };
    }

    public static function color(int|string $status): string
    {
        $key = is_int($status) ? self::name($status) : ucfirst(strtolower($status));

        return match ($key) {
            'Approved', 'Completed', 'Paid', 'Reached' => '#16A34A',
            'Pending', 'Created', 'Not Started', 'Unpaid' => '#F59E0B',
            'Rejected', 'Cancelled' => '#DC2626',
            'Referred', 'Started', 'In Progress' => '#2563EB',
            default => '#6B7280',
        };
    }

    public static function priorityName(int $id): string
    {
        return self::PRIORITIES[$id][0] ?? 'Medium';
    }

    public static function priorityColor(int $id): string
    {
        return self::PRIORITIES[$id][1] ?? '#FFA500';
    }
}
