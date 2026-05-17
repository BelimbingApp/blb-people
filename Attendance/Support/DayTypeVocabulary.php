<?php

namespace App\Modules\People\Attendance\Support;

use App\Modules\People\Attendance\Models\AttendanceDay;

/**
 * Single source of truth for how a day type renders.
 *
 * Centralises the label + surface-class + ink-class triplet for the four
 * day-type values defined on `AttendanceDay::DAY_TYPE_*` so every calendar
 * surface (Attendance roster grid, future Leave / Claim / Timesheet calendars)
 * resolves them the same way.
 *
 * Surfaces consume the helper through the static methods below; tokens are
 * declared in `resources/core/css/tokens.css` under `--color-day-{rest,off,
 * holiday}` (plus `*-ink` variants). When adding a new day-type, extend the
 * constants on `AttendanceDay` first, register tokens in `tokens.css`, then
 * add the mapping here.
 */
final class DayTypeVocabulary
{
    /**
     * Translated label, e.g. "Rest", "Off", "Holiday", "Normal".
     */
    public static function label(string $dayType): string
    {
        return match ($dayType) {
            AttendanceDay::DAY_TYPE_REST => __('Rest'),
            AttendanceDay::DAY_TYPE_OFF => __('Off'),
            AttendanceDay::DAY_TYPE_HOLIDAY => __('Holiday'),
            default => __('Normal'),
        };
    }

    /**
     * Background utility class for a cell whose day type is non-normal.
     * Returns an empty string for Normal so callers can compose without a
     * conditional wrapper.
     */
    public static function surfaceClass(string $dayType): string
    {
        return match ($dayType) {
            AttendanceDay::DAY_TYPE_REST => 'bg-day-rest',
            AttendanceDay::DAY_TYPE_OFF => 'bg-day-off',
            AttendanceDay::DAY_TYPE_HOLIDAY => 'bg-day-holiday',
            default => '',
        };
    }

    /**
     * Ink colour utility class for the day-type label or marker.
     */
    public static function inkClass(string $dayType): string
    {
        return match ($dayType) {
            AttendanceDay::DAY_TYPE_REST => 'text-day-rest-ink',
            AttendanceDay::DAY_TYPE_OFF => 'text-day-off-ink',
            AttendanceDay::DAY_TYPE_HOLIDAY => 'text-day-holiday-ink',
            default => 'text-muted',
        };
    }

    public static function isNonWorking(string $dayType): bool
    {
        return $dayType !== AttendanceDay::DAY_TYPE_NORMAL;
    }
}
