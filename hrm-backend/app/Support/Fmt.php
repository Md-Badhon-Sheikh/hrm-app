<?php

namespace App\Support;

/**
 * Number / money formatting that does not need the PHP "intl" extension.
 *
 * Laravel's Illuminate\Support\Number (used by Filament for pagination,
 * selection counts and money columns) throws when intl is missing, which
 * is the case on some shared hosts. These helpers give the same output
 * for the English locale using plain number_format().
 */
class Fmt
{
    public static function number(int|float|null $value, int $decimals = 0): string
    {
        return number_format((float) ($value ?? 0), $decimals, '.', ',');
    }

    /** "BDT 1,500.00" — same shape as Number::currency() for the en locale. */
    public static function money(int|float|string|null $amount, ?string $currency = null): string
    {
        $currency = $currency ?: \App\Models\Setting::get('currency_code', 'BDT');

        return trim($currency.' '.static::number((float) $amount, 2));
    }
}
