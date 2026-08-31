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

    // Government / festival holidays are PAID at the day rate, and a worker
    // who turns up on one is paid overtime for the whole day.
    'paid_holidays'         => (bool) env('PAID_HOLIDAYS', true),
    'holiday_ot_multiplier' => (float) env('HOLIDAY_OT_MULTIPLIER', 2.0),
    'weekly_off_ot_multiplier' => (float) env('WEEKLY_OFF_OT_MULTIPLIER', 1.0),

    // Overtime multiplier per skill grade. A company can override these in its
    // settings (ot_multipliers), and a single worker can override both.
    'ot_multipliers' => [
        'unskilled'      => (float) env('OT_MULT_UNSKILLED', 1.0),
        'semi_skilled'   => (float) env('OT_MULT_SEMI_SKILLED', 1.0),
        'skilled'        => (float) env('OT_MULT_SKILLED', 1.0),
        'highly_skilled' => (float) env('OT_MULT_HIGHLY_SKILLED', 1.0),
    ],

    // Weekly off days, 0 = Sunday ... 6 = Saturday.
    'weekly_offs' => array_values(array_filter(array_map(
        'intval', explode(',', env('WEEKLY_OFFS', '0'))
    ), fn ($d) => $d >= 0 && $d <= 6)),

    // ── Wage heads used in Indian manufacturing ─────────────────────────────
    // Earnings are paid PRO-RATA on present days unless 'fixed' is true.
    // 'pct_of' seeds a suggested amount when a structure is first built; the
    // saved per-worker amount always wins.
    'components' => [
        // Earnings
        ['code' => 'basic',        'label' => 'Basic',                  'type' => 'earning',  'pct_of' => ['gross' => 50], 'pf' => true,  'esi' => true],
        ['code' => 'da',           'label' => 'Dearness Allowance (DA/VDA)', 'type' => 'earning', 'pct_of' => ['gross' => 20], 'pf' => true, 'esi' => true],
        ['code' => 'hra',          'label' => 'House Rent Allowance',   'type' => 'earning',  'pct_of' => ['basic' => 5],  'pf' => false, 'esi' => true],
        ['code' => 'conveyance',   'label' => 'Conveyance',             'type' => 'earning',  'pf' => false, 'esi' => true],
        ['code' => 'washing',      'label' => 'Washing Allowance',      'type' => 'earning',  'pf' => false, 'esi' => false],
        ['code' => 'medical',      'label' => 'Medical Allowance',      'type' => 'earning',  'pf' => false, 'esi' => true],
        ['code' => 'night_shift',  'label' => 'Night Shift Allowance',  'type' => 'earning',  'pf' => false, 'esi' => true],
        ['code' => 'incentive',    'label' => 'Attendance / Production Incentive', 'type' => 'earning', 'pf' => false, 'esi' => true],
        ['code' => 'special',      'label' => 'Special Allowance',      'type' => 'earning',  'pf' => false, 'esi' => true],
        // Deductions entered per worker (statutory ones are computed, not typed)
        ['code' => 'canteen',      'label' => 'Canteen',                'type' => 'deduction'],
        ['code' => 'uniform',      'label' => 'Uniform / Safety shoes', 'type' => 'deduction'],
        ['code' => 'transport_ded','label' => 'Transport recovery',     'type' => 'deduction'],
        ['code' => 'union',        'label' => 'Union / Society',        'type' => 'deduction'],
    ],

    /*
     * Statutory rates. Every figure is configurable because these change and
     * several are state-specific — CONFIRM against the current notification
     * and the state where the site operates before going live.
     */
    'statutory' => [
        'pf' => [
            'enabled'        => (bool) env('PF_ENABLED', true),
            'employee_pct'   => (float) env('PF_EMPLOYEE_PCT', 12),
            'employer_pct'   => (float) env('PF_EMPLOYER_PCT', 12),   // 8.33 EPS + 3.67 EPF
            'eps_pct'        => (float) env('PF_EPS_PCT', 8.33),
            'admin_pct'      => (float) env('PF_ADMIN_PCT', 0.5),     // EPF administration
            'edli_pct'       => (float) env('PF_EDLI_PCT', 0.5),
            'wage_ceiling'   => (float) env('PF_WAGE_CEILING', 15000),
            'cap_at_ceiling' => (bool) env('PF_CAP_AT_CEILING', true),
        ],
        'esi' => [
            'enabled'       => (bool) env('ESI_ENABLED', true),
            'employee_pct'  => (float) env('ESI_EMPLOYEE_PCT', 0.75),
            'employer_pct'  => (float) env('ESI_EMPLOYER_PCT', 3.25),
            'gross_ceiling' => (float) env('ESI_GROSS_CEILING', 21000),
        ],
        // Professional tax is per state. Maharashtra defaults; slabs are
        // [monthly gross up to, amount]; the last entry is the top slab.
        'pt' => [
            'enabled' => (bool) env('PT_ENABLED', true),
            'state'   => env('PT_STATE', 'MH'),
            'slabs'   => [[7500, 0], [10000, 175], [PHP_INT_MAX, 200]],
            'february_extra' => (float) env('PT_FEBRUARY_EXTRA', 100), // MH pays 300 in Feb
        ],
        'lwf' => [
            'enabled'      => (bool) env('LWF_ENABLED', false),
            'employee'     => (float) env('LWF_EMPLOYEE', 25),
            'employer'     => (float) env('LWF_EMPLOYER', 75),
            'months'       => [6, 12],   // deducted in these months only
        ],
        // Employer-side provisions — shown for contractor bill verification.
        'bonus'    => ['pct' => (float) env('BONUS_PCT', 8.33), 'ceiling' => (float) env('BONUS_CEILING', 7000)],
        'gratuity' => ['pct' => (float) env('GRATUITY_PCT', 4.81)],
    ],
];
