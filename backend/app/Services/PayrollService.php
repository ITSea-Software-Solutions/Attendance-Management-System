<?php

namespace App\Services;

use App\Models\Worker;
use App\Models\WorkerAssignment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Wage register for contract labour.
 *
 * The arithmetic follows how plant musters are actually run in India:
 *
 *   day rate = monthly rate / wage divisor        (26 by convention)
 *   OT rate  = day rate / shift hours * multiplier (single rate by default)
 *   payable  = day rate x present days + OT hours x OT rate + adjustments
 *
 * Attendance comes from real gate punches, so "present" is a biometric fact
 * rather than a typed letter. A day worked on a weekly-off or holiday counts
 * entirely as overtime, which is the usual site practice.
 */
class PayrollService
{
    public const STATUS_PRESENT = 'P';
    public const STATUS_ABSENT  = 'A';
    public const STATUS_OFF     = 'WO';
    public const STATUS_HOLIDAY = 'H';

    /**
     * The pay period containing $anchor for a cycle starting on $startDay.
     * startDay 26 → 26 Jul .. 25 Aug. startDay 1 → the calendar month.
     */
    public static function period(Carbon $anchor, ?int $startDay = null): array
    {
        $startDay = $startDay ?: (int) config('payroll.cycle_start_day', 26);
        $startDay = max(1, min(28, $startDay));

        if ($startDay === 1) {
            return [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()];
        }

        $start = $anchor->day >= $startDay
            ? $anchor->copy()->startOfMonth()->addDays($startDay - 1)
            : $anchor->copy()->subMonthNoOverflow()->startOfMonth()->addDays($startDay - 1);

        return [$start->startOfDay(), $start->copy()->addMonthNoOverflow()->subDay()->endOfDay()];
    }

    /** Weekly-off weekdays for a company (settings override the config default). */
    private function weeklyOffs($company): array
    {
        $fromSettings = data_get($company?->settings, 'weekly_offs');

        return is_array($fromSettings) && $fromSettings !== []
            ? array_map('intval', $fromSettings)
            : (array) config('payroll.weekly_offs', [0]);
    }


    // ── Wage heads & statutory ───────────────────────────────────────────────

    /** The head catalogue, as configured. */
    public static function components(): array
    {
        return (array) config('payroll.components', []);
    }

    /**
     * The day rate a worker is actually paid at.
     *
     * Daily-wage labour is hired at a rate per day, so that figure is used as
     * entered. Monthly staff on the same muster keep the divisor treatment.
     */
    public static function dayRateOf(Worker $w): float
    {
        if (($w->wage_type ?? 'daily') === 'monthly') {
            $div = (int) ($w->wage_divisor ?: config('payroll.wage_divisor', 26));

            return $div > 0 ? round(((float) $w->monthly_rate) / $div, 2) : 0.0;
        }

        // Daily: the rate, or the sum of the per-day heads if one is not set.
        $rate = (float) ($w->daily_rate ?? 0);
        if ($rate <= 0) {
            $rate = array_sum(array_map('floatval', (array) ($w->wage_components ?: [])));
        }

        return round($rate, 2);
    }

    /**
     * Overtime multiplier: the worker's own override wins, then the company's
     * per-grade setting, then the configured default. Work on a holiday or a
     * weekly off carries its own multiplier on top.
     */
    public static function otMultiplierFor(Worker $w, $company, string $dayStatus = self::STATUS_PRESENT): float
    {
        $base = $w->ot_multiplier !== null ? (float) $w->ot_multiplier : null;

        if ($base === null) {
            $perGrade = (array) data_get($company?->settings, 'ot_multipliers', []);
            $grade    = $w->skill_category ?: 'unskilled';
            $base = isset($perGrade[$grade])
                ? (float) $perGrade[$grade]
                : (float) (config("payroll.ot_multipliers.{$grade}") ?? 1.0);
        }

        return match ($dayStatus) {
            self::STATUS_HOLIDAY => $base * (float) config('payroll.holiday_ot_multiplier', 2.0),
            self::STATUS_OFF     => $base * (float) config('payroll.weekly_off_ot_multiplier', 1.0),
            default              => $base,
        };
    }

    /** Suggest a structure from a monthly rate, for a worker with none saved. */
    public static function suggestStructure(float $monthlyRate): array
    {
        $out = [];
        foreach (self::components() as $c) {
            if (($c['type'] ?? '') !== 'earning' || empty($c['pct_of'])) {
                continue;
            }
            if (isset($c['pct_of']['gross'])) {
                $out[$c['code']] = round($monthlyRate * $c['pct_of']['gross'] / 100, 2);
            }
        }
        // Percent-of-basic heads resolve after basic is known.
        foreach (self::components() as $c) {
            if (($c['type'] ?? '') !== 'earning' || empty($c['pct_of']['basic'])) {
                continue;
            }
            $out[$c['code']] = round(($out['basic'] ?? 0) * $c['pct_of']['basic'] / 100, 2);
        }
        // Whatever the named heads do not account for lands in special allowance.
        $named = array_sum($out);
        if ($monthlyRate > $named) {
            $out['special'] = round(($out['special'] ?? 0) + $monthlyRate - $named, 2);
        }

        return $out;
    }

    /**
     * Earnings for one worker for the period, head by head.
     * Heads are paid pro-rata on present days (payable days / divisor).
     */
    private function earningHeads(Worker $w, float $monthlyRate, int $presentDays, int $divisor): array
    {
        $daily     = ($w->wage_type ?? 'daily') === 'daily';
        $structure = (array) ($w->wage_components ?: []);
        if ($structure === [] && ! $daily && $monthlyRate > 0) {
            $structure = self::suggestStructure($monthlyRate);
        }
        // Daily heads are per-day amounts paid once per present day; monthly
        // heads are a month's figure earned in proportion to days worked.
        $ratio = $daily ? $presentDays : ($divisor > 0 ? $presentDays / $divisor : 0);

        $heads = [];
        foreach (self::components() as $c) {
            if (($c['type'] ?? '') !== 'earning') {
                continue;
            }
            $monthly = (float) ($structure[$c['code']] ?? 0);
            if ($monthly <= 0) {
                continue;
            }
            $heads[$c['code']] = [
                'label'   => $c['label'],
                'rate'    => round($monthly, 2),          // per day, or per month
                'monthly' => round($monthly, 2),
                'earned'  => round($monthly * $ratio, 2),
                'pf'      => (bool) ($c['pf'] ?? false),
                'esi'     => (bool) ($c['esi'] ?? false),
            ];
        }

        return $heads;
    }

    /** Professional tax for a monthly gross, using the configured slabs. */
    private function professionalTax(float $gross, Carbon $periodEnd): float
    {
        $pt = (array) config('payroll.statutory.pt', []);
        if (empty($pt['enabled']) || $gross <= 0) {
            return 0.0;
        }
        $amount = 0.0;
        foreach ((array) ($pt['slabs'] ?? []) as [$upTo, $value]) {
            if ($gross <= $upTo) {
                $amount = (float) $value;
                break;
            }
        }
        if ($amount > 0 && (int) $periodEnd->month === 2) {
            $amount += (float) ($pt['february_extra'] ?? 0);
        }

        return round($amount, 2);
    }

    /**
     * Statutory deductions and employer contributions for one worker-period.
     * PF applies to PF-eligible heads; ESI to the whole gross while it stays
     * under the ceiling.
     */
    private function statutory(Worker $w, array $heads, float $otAmount, Carbon $periodEnd): array
    {
        $pfCfg  = (array) config('payroll.statutory.pf', []);
        $esiCfg = (array) config('payroll.statutory.esi', []);

        $pfWages  = 0.0;
        $esiWages = 0.0;
        foreach ($heads as $h) {
            if ($h['pf'])  { $pfWages  += $h['earned']; }
            if ($h['esi']) { $esiWages += $h['earned']; }
        }
        $esiWages += $otAmount;   // overtime counts for ESI, not for PF

        $out = [
            'pf_wages' => round($pfWages, 2), 'pf_employee' => 0.0, 'pf_employer' => 0.0,
            'pf_eps' => 0.0, 'pf_admin' => 0.0, 'pf_edli' => 0.0,
            'esi_wages' => round($esiWages, 2), 'esi_employee' => 0.0, 'esi_employer' => 0.0,
            'pt' => 0.0, 'lwf_employee' => 0.0, 'lwf_employer' => 0.0,
            'bonus_provision' => 0.0, 'gratuity_provision' => 0.0,
        ];

        if (! empty($pfCfg['enabled']) && $w->pf_applicable && $pfWages > 0) {
            $base = (! empty($pfCfg['cap_at_ceiling']))
                ? min($pfWages, (float) ($pfCfg['wage_ceiling'] ?? 15000))
                : $pfWages;
            $out['pf_employee'] = round($base * ($pfCfg['employee_pct'] ?? 12) / 100, 2);
            $out['pf_eps']      = round($base * ($pfCfg['eps_pct'] ?? 8.33) / 100, 2);
            $out['pf_employer'] = round($base * ($pfCfg['employer_pct'] ?? 12) / 100, 2);
            $out['pf_admin']    = round($base * ($pfCfg['admin_pct'] ?? 0.5) / 100, 2);
            $out['pf_edli']     = round($base * ($pfCfg['edli_pct'] ?? 0.5) / 100, 2);
        }

        if (! empty($esiCfg['enabled']) && $w->esi_applicable
            && $esiWages > 0 && $esiWages <= (float) ($esiCfg['gross_ceiling'] ?? 21000)) {
            $out['esi_employee'] = round($esiWages * ($esiCfg['employee_pct'] ?? 0.75) / 100, 2);
            $out['esi_employer'] = round($esiWages * ($esiCfg['employer_pct'] ?? 3.25) / 100, 2);
        }

        $gross = array_sum(array_column($heads, 'earned')) + $otAmount;
        $out['pt'] = $this->professionalTax($gross, $periodEnd);

        $lwf = (array) config('payroll.statutory.lwf', []);
        if (! empty($lwf['enabled']) && in_array((int) $periodEnd->month, (array) ($lwf['months'] ?? []), true)) {
            $out['lwf_employee'] = (float) ($lwf['employee'] ?? 0);
            $out['lwf_employer'] = (float) ($lwf['employer'] ?? 0);
        }

        $bonus = (array) config('payroll.statutory.bonus', []);
        $out['bonus_provision'] = round(min($pfWages, (float) ($bonus['ceiling'] ?? 7000))
            * ($bonus['pct'] ?? 8.33) / 100, 2);
        $out['gratuity_provision'] = round($pfWages
            * (config('payroll.statutory.gratuity.pct', 4.81)) / 100, 2);

        return $out;
    }

    /**
     * Build the register.
     *
     * @return array{period:array,days:array,rows:array,totals:array,rules:array}
     */
    public function register(int $companyId, Carbon $from, Carbon $to, array $opts = []): array
    {
        $company   = \App\Models\Company::find($companyId);
        $offs      = $this->weeklyOffs($company);
        $fullDay   = (float) config('payroll.full_day_hours', 8);
        $workerIds = $opts['worker_ids'] ?? [];
        $vendorId  = $opts['vendor_id'] ?? null;

        // ── every day in the period ──────────────────────────────────────────
        $days = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $days[] = $d->toDateString();
        }

        // ── the muster population: deployed in the period OR seen at the gate ─
        $deployed = WorkerAssignment::where('company_id', $companyId)
            ->where('status', WorkerAssignment::STATUS_ACTIVE)
            ->where('approval_status', 'approved')
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->pluck('worker_id');

        $attended = DB::table('attendance_logs')
            ->where('company_id', $companyId)->where('is_valid', true)
            ->whereBetween(DB::raw('DATE(marked_at)'), [$from->toDateString(), $to->toDateString()])
            ->distinct()->pluck('worker_id');

        $ids = $deployed->merge($attended)->unique()->values();
        if ($workerIds) {
            $ids = $ids->intersect($workerIds)->values();
        }

        $workers = Worker::with('vendor:id,name')->whereIn('id', $ids)
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->orderBy('name')->get();

        // ── one grouped pass over the punches ────────────────────────────────
        $punches = DB::table('attendance_logs')
            ->selectRaw("worker_id, DATE(marked_at) as d,
                MIN(CASE WHEN type='IN'  THEN marked_at END) as first_in,
                MAX(CASE WHEN type='OUT' THEN marked_at END) as last_out")
            ->where('company_id', $companyId)->where('is_valid', true)
            ->whereBetween(DB::raw('DATE(marked_at)'), [$from->toDateString(), $to->toDateString()])
            ->whereIn('worker_id', $workers->pluck('id'))
            ->groupBy('worker_id', DB::raw('DATE(marked_at)'))
            ->get()->groupBy('worker_id');

        $holidayRows = DB::table('company_holidays')
            ->where('company_id', $companyId)
            ->whereBetween('holiday_date', [$from->toDateString(), $to->toDateString()])
            ->get();
        $holidays    = $holidayRows->pluck('name', 'holiday_date');
        // A holiday can be declared unpaid (rare, but the flag exists).
        $holidayPaid = $holidayRows->mapWithKeys(fn ($h) => [(string) $h->holiday_date => (bool) $h->paid]);

        $overrides = DB::table('attendance_overrides')
            ->where('company_id', $companyId)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('worker_id', $workers->pluck('id'))
            ->get()->groupBy('worker_id');

        $adjustments = DB::table('payroll_adjustments')
            ->where('company_id', $companyId)
            ->where('period_start', $from->toDateString())
            ->where('period_end', $to->toDateString())
            ->whereIn('worker_id', $workers->pluck('id'))
            ->get()->groupBy('worker_id');

        $rows = [];
        foreach ($workers as $w) {
            $byDate  = ($punches[$w->id] ?? collect())->keyBy('d');
            $ovByDay = ($overrides[$w->id] ?? collect())->keyBy(fn ($o) => (string) $o->work_date);

            $isDaily = ($w->wage_type ?? 'daily') === 'daily';
            $rate    = (float) ($isDaily ? ($w->daily_rate ?? 0) : ($w->monthly_rate ?? 0));
            $wDiv    = (int) ($w->wage_divisor ?: config('payroll.wage_divisor', 26));
            $oDiv    = (int) ($w->ot_divisor ?: config('payroll.ot_divisor', 8));
            $dayRate = self::dayRateOf($w);
            // Ordinary-day overtime rate; holiday and weekly-off hours are
            // re-priced with their own multipliers as the days are walked.
            $oMul    = self::otMultiplierFor($w, $company);
            $otRate  = $oDiv > 0 ? ($dayRate / $oDiv) * $oMul : 0.0;

            $cells = [];
            $present = $absent = $woDays = $holiDays = 0;
            $paidHolidays = 0;
            $minutes = 0; $otHours = 0.0;
            $otByStatus = [self::STATUS_PRESENT => 0.0, self::STATUS_OFF => 0.0, self::STATUS_HOLIDAY => 0.0];
            $missingOut = 0; $absentWithOt = 0; $offWorked = 0; $unapproved = 0;

            foreach ($days as $date) {
                $p   = $byDate[$date] ?? null;
                $ov  = $ovByDay[$date] ?? null;
                $dow = (int) Carbon::parse($date)->dayOfWeek;

                $isHoliday = $holidays->has($date);
                $isOff     = in_array($dow, $offs, true);
                $worked    = $p && $p->first_in;

                // status: an explicit override wins, else punches, else the calendar
                $status = $ov->status ?? null;
                if (! $status) {
                    if ($worked)          $status = $isHoliday ? self::STATUS_HOLIDAY : ($isOff ? self::STATUS_OFF : self::STATUS_PRESENT);
                    elseif ($isHoliday)   $status = self::STATUS_HOLIDAY;
                    elseif ($isOff)       $status = self::STATUS_OFF;
                    else                  $status = self::STATUS_ABSENT;
                }

                $mins = ($worked && $p->last_out)
                    ? max(0, Carbon::parse($p->last_out)->diffInMinutes(Carbon::parse($p->first_in), true))
                    : 0;
                if ($worked && ! $p->last_out) {
                    $missingOut++;
                }

                // Overtime: a normal day pays OT beyond a full shift; a day
                // worked on an off/holiday is overtime end to end.
                $ot = 0.0;
                if ($ov && $ov->ot_hours !== null) {
                    $ot = (float) $ov->ot_hours;
                    if (! $ov->approved_at) {
                        $unapproved++;
                    }
                } elseif ($worked) {
                    $ot = in_array($status, [self::STATUS_OFF, self::STATUS_HOLIDAY], true)
                        ? round($mins / 60, 2)
                        : round(max(0, $mins - $fullDay * 60) / 60, 2);
                }

                if ($status === self::STATUS_ABSENT && $ot > 0) {
                    $absentWithOt++;
                }
                if (in_array($status, [self::STATUS_OFF, self::STATUS_HOLIDAY], true) && $ot > 0) {
                    $offWorked++;
                }

                match ($status) {
                    self::STATUS_PRESENT => $present++,
                    self::STATUS_OFF     => $woDays++,
                    self::STATUS_HOLIDAY => $holiDays++,
                    default              => $absent++,
                };
                // A government/festival holiday is paid whether or not the
                // worker came in — that is what makes it a holiday.
                if ($status === self::STATUS_HOLIDAY && ($holidayPaid[$date] ?? true)) {
                    $paidHolidays++;
                }
                $otByStatus[$status] = ($otByStatus[$status] ?? 0) + $ot;
                if ($status === self::STATUS_PRESENT) {
                    $minutes += $mins;
                }
                $otHours += $ot;

                $cells[$date] = [
                    'status' => $status,
                    'in'     => $worked ? substr((string) $p->first_in, 11, 5) : null,
                    'out'    => ($worked && $p->last_out) ? substr((string) $p->last_out, 11, 5) : null,
                    'hours'  => round($mins / 60, 2),
                    'ot'     => $ot,
                    'manual' => (bool) $ov,
                ];
            }

            $adj = ['arrear' => 0.0, 'advance' => 0.0, 'deduction' => 0.0, 'bonus' => 0.0];
            foreach (($adjustments[$w->id] ?? collect()) as $a) {
                $adj[$a->type] = ($adj[$a->type] ?? 0) + (float) $a->amount;
            }

            $base        = round($dayRate * $present, 2);
            $holidayPay  = config('payroll.paid_holidays', true)
                ? round($dayRate * $paidHolidays, 2) : 0.0;
            // Each bucket of overtime hours is paid at its own multiplier.
            $otAmount = 0.0;
            foreach ($otByStatus as $st => $hrs) {
                if ($hrs <= 0) {
                    continue;
                }
                $mul = self::otMultiplierFor($w, $company, $st);
                $otAmount += $hrs * ($oDiv > 0 ? ($dayRate / $oDiv) * $mul : 0);
            }
            $otAmount = round($otAmount, 2);

            // Head-wise earnings, and the statutory that follows from them.
            $heads    = $this->earningHeads($w, $rate, $present, $wDiv);
            $headsSum = round(array_sum(array_column($heads, 'earned')), 2);
            // With no structure saved the single day-rate figure IS the gross.
            $earnings = ($heads === [] ? $base : $headsSum) + $holidayPay;
            $stat     = $this->statutory($w, $heads, $otAmount, $to);

            $gross      = round($earnings + $otAmount, 2);
            $deductions = round(
                $stat['pf_employee'] + $stat['esi_employee'] + $stat['pt'] + $stat['lwf_employee']
                + $adj['advance'] + $adj['deduction'], 2);
            $net        = round($gross + $adj['arrear'] + $adj['bonus'] - $deductions, 2);
            $employerCost = round($gross + $stat['pf_employer'] + $stat['pf_admin'] + $stat['pf_edli']
                + $stat['esi_employer'] + $stat['lwf_employer'], 2);

            $rows[] = [
                'worker_id' => $w->id, 'name' => $w->name, 'emp_code' => $w->emp_code,
                'vendor' => $w->vendor?->name, 'vendor_id' => $w->vendor_id,
                'days' => $cells,
                'present_days' => $present, 'absent_days' => $absent,
                'off_days' => $woDays, 'holidays' => $holiDays,
                'hours' => round($minutes / 60, 2), 'ot_hours' => round($otHours, 2),
                'monthly_rate' => $rate, 'wage_divisor' => $wDiv, 'ot_divisor' => $oDiv,
                'day_rate' => round($dayRate, 2), 'ot_rate' => round($otRate, 2),
                'base_amount' => $base, 'ot_amount' => $otAmount,
                'wage_type' => $w->wage_type ?? 'daily',
                'daily_rate' => $dayRate,
                'paid_holidays' => $paidHolidays, 'holiday_pay' => $holidayPay,
                'ot_multiplier' => $oMul,
                'heads' => $heads, 'gross' => $gross,
                'statutory' => $stat,
                'total_deductions' => $deductions,
                'employer_cost' => $employerCost,
                'arrear' => $adj['arrear'], 'bonus' => $adj['bonus'],
                'advance' => $adj['advance'], 'deduction' => $adj['deduction'],
                'net_payable' => $net,
                'flags' => array_values(array_filter([
                    $rate <= 0        ? 'no_rate' : null,
                    $missingOut > 0   ? "missing_out:{$missingOut}" : null,
                    $absentWithOt > 0 ? "absent_with_ot:{$absentWithOt}" : null,
                    $offWorked > 0    ? "worked_on_off:{$offWorked}" : null,
                    $unapproved > 0   ? "unapproved_ot:{$unapproved}" : null,
                ])),
            ];
        }

        $sum = fn (string $k) => round(array_sum(array_column($rows, $k)), 2);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'days'   => $days,
            'rows'   => $rows,
            'totals' => [
                'workers' => count($rows),
                'present_days' => $sum('present_days'), 'absent_days' => $sum('absent_days'),
                'ot_hours' => $sum('ot_hours'),
                'base_amount' => $sum('base_amount'), 'ot_amount' => $sum('ot_amount'),
                'gross' => $sum('gross'),
                'total_deductions' => $sum('total_deductions'),
                'employer_cost' => $sum('employer_cost'),
                'pf_employee' => round(array_sum(array_map(fn ($r) => $r['statutory']['pf_employee'], $rows)), 2),
                'pf_employer' => round(array_sum(array_map(fn ($r) => $r['statutory']['pf_employer'], $rows)), 2),
                'esi_employee' => round(array_sum(array_map(fn ($r) => $r['statutory']['esi_employee'], $rows)), 2),
                'esi_employer' => round(array_sum(array_map(fn ($r) => $r['statutory']['esi_employer'], $rows)), 2),
                'net_payable' => $sum('net_payable'),
                'flagged' => count(array_filter($rows, fn ($r) => $r['flags'] !== [])),
            ],
            'rules' => [
                'full_day_hours' => $fullDay,
                'wage_divisor'   => (int) config('payroll.wage_divisor', 26),
                'ot_divisor'     => (int) config('payroll.ot_divisor', 8),
                'weekly_offs'    => $offs,
                'components'     => self::components(),
                'paid_holidays'  => (bool) config('payroll.paid_holidays', true),
                'holiday_ot_multiplier' => (float) config('payroll.holiday_ot_multiplier', 2.0),
                'statutory'      => config('payroll.statutory'),
            ],
        ];
    }
}
