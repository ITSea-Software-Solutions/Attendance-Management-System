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

    // ── Wage register defaults (per-worker values override these) ───────────
    // Day rate  = monthly rate / wage_divisor        (26 is the Indian norm)
    // OT rate   = day rate / ot_divisor * multiplier (single rate by default)
    'wage_divisor'  => (int) env('WAGE_DIVISOR', 26),
    'ot_divisor'    => (int) env('OT_DIVISOR', 8),
    'ot_multiplier' => (float) env('OT_MULTIPLIER', 1.0),

    // Pay period start day. 26 = the 26th of one month to the 25th of the
    // next, which is how most contract-labour musters run. 1 = calendar month.
    'cycle_start_day' => (int) env('PAY_CYCLE_START_DAY', 26),

    // Weekly off days, 0 = Sunday ... 6 = Saturday.
    'weekly_offs' => array_values(array_filter(array_map(
        'intval', explode(',', env('WEEKLY_OFFS', '0'))
    ), fn ($d) => $d >= 0 && $d <= 6)),
];
