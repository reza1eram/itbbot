<?php

namespace App\Modules\AnonymousExam\Support;

use Carbon\CarbonInterface;
use Hekmatinasser\Verta\Verta;

/**
 * Presentation helpers: Jalali (Shamsi) dates in Asia/Tehran and Persian digits.
 * Storage is always UTC; conversion happens only here.
 */
class Display
{
    private const FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    public static function tz(): string
    {
        return (string) config('anonymous_exam.display_timezone', 'Asia/Tehran');
    }

    public static function digits(string|int|float|null $value): string
    {
        return str_replace(range(0, 9), self::FA_DIGITS, (string) $value);
    }

    public static function latinDigits(?string $value): string
    {
        return str_replace(self::FA_DIGITS, range(0, 9), (string) $value);
    }

    public static function dateTime(?CarbonInterface $at, string $format = 'Y/m/d H:i'): string
    {
        if (!$at) {
            return '—';
        }

        return self::digits((new Verta($at->copy()->setTimezone(self::tz())))->format($format));
    }

    public static function date(?CarbonInterface $at): string
    {
        return self::dateTime($at, 'Y/m/d');
    }

    /** Value for <input type="datetime-local"> in display timezone (Gregorian, as browsers require) */
    public static function inputValue(?CarbonInterface $at): string
    {
        return $at ? $at->copy()->setTimezone(self::tz())->format('Y-m-d\TH:i') : '';
    }

    public static function number(float|int|null $value, int $decimals = 0): string
    {
        if ($value === null) {
            return '—';
        }

        return self::digits(number_format((float) $value, $decimals));
    }
}
