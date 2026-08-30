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

        $holidays = DB::table('company_holidays')
            ->where('company_id', $companyId)
            ->whereBetween('holiday_date', [$from->toDateString(), $to->toDateString()])
            ->pluck('name', 'holiday_date');

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

            $rate    = (float) ($w->monthly_rate ?? 0);
            $wDiv    = (int) ($w->wage_divisor ?: config('payroll.wage_divisor', 26));
            $oDiv    = (int) ($w->ot_divisor ?: config('payroll.ot_divisor', 8));
            $oMul    = (float) ($w->ot_multiplier ?: config('payroll.ot_multiplier', 1.0));
            $dayRate = $wDiv > 0 ? $rate / $wDiv : 0.0;
            $otRate  = $oDiv > 0 ? ($dayRate / $oDiv) * $oMul : 0.0;

            $cells = [];
            $present = $absent = $woDays = $holiDays = 0;
            $minutes = 0; $otHours = 0.0;
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

            $base     = round($dayRate * $present, 2);
            $otAmount = round($otHours * $otRate, 2);
            $net      = round($base + $otAmount + $adj['arrear'] + $adj['bonus'] - $adj['advance'] - $adj['deduction'], 2);

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
                'net_payable' => $sum('net_payable'),
                'flagged' => count(array_filter($rows, fn ($r) => $r['flags'] !== [])),
            ],
            'rules' => [
                'full_day_hours' => $fullDay,
                'wage_divisor'   => (int) config('payroll.wage_divisor', 26),
                'ot_divisor'     => (int) config('payroll.ot_divisor', 8),
                'weekly_offs'    => $offs,
            ],
        ];
    }
}
