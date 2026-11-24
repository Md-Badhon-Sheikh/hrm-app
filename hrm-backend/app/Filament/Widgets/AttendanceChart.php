<?php

namespace App\Filament\Widgets;

use App\Models\Attendance;
use Filament\Widgets\ChartWidget;

class AttendanceChart extends ChartWidget
{
    protected static ?string $heading = 'Attendance — last 14 days';
    protected static ?int $sort = 2;
    protected int|string|array $columnSpan = 'full';
    protected static ?string $maxHeight = '260px';

    protected function getData(): array
    {
        $days = collect(range(13, 0))->map(fn ($d) => today()->subDays($d));
        $rows = Attendance::whereDate('date', '>=', $days->first())->get()->groupBy(fn ($a) => $a->date->toDateString());

        return [
            'datasets' => [
                ['label' => 'Present', 'data' => $days->map(fn ($d) => $rows->get($d->toDateString())?->unique('user_id')->count() ?? 0)->all(),
                    'borderColor' => '#2563EB', 'backgroundColor' => 'rgba(37,99,235,.12)', 'fill' => true, 'tension' => 0.3],
                ['label' => 'Late', 'data' => $days->map(fn ($d) => $rows->get($d->toDateString())?->where('in_status', 'L')->unique('user_id')->count() ?? 0)->all(),
                    'borderColor' => '#DC2626', 'backgroundColor' => 'rgba(220,38,38,.08)', 'fill' => true, 'tension' => 0.3],
            ],
            'labels' => $days->map(fn ($d) => $d->format('d M'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
