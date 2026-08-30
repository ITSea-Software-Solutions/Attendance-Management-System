<?php

/**
 * Wage-day rules. Attendance is paid by HOURS, but the muster is read in
 * DAYS — so every worker-day is classified against these thresholds:
 *
 *   hours >= full_day_hours  → 1.0 day  (full day)
 *   hours >= half_day_hours  → 0.5 day  (half day)
 *   otherwise                → 0.0 day  (short — hours still reported)
 *
 * Overtime counts the minutes worked beyond a full day.
 */
return [
    'full_day_hours' => (float) env('FULL_DAY_HOURS', 8),
    'half_day_hours' => (float) env('HALF_DAY_HOURS', 4),

    // Count overtime past a full day in the reports.
    'overtime' => (bool) env('PAYROLL_OVERTIME', true),
];
