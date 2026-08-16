<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NotifyService;
use App\Services\PlanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Monday-morning attendance summary of the PREVIOUS week, per company —
 * Enterprise feature (`weekly_reports`). In-app + templated email to the
 * company admins.
 *
 *   php artisan truecrew:weekly-report [--week-of=YYYY-MM-DD]
 */
class SendWeeklyReports extends Command
{
    protected $signature = 'truecrew:weekly-report {--week-of=}';

    protected $description = 'Email company admins the previous week\'s attendance summary (Enterprise)';

    public function handle(NotifyService $notify): int
    {
        $anchor = $this->option('week-of') ? now()->parse($this->option('week-of')) : now();
        $start  = $anchor->copy()->subWeek()->startOfWeek(); // previous Mon
        $end    = $start->copy()->endOfWeek();               // previous Sun
        $week   = $start->format('d M').' – '.$end->format('d M Y');

        // Per company + worker: days present and paired-hours for the week.
        $rows = DB::select("
            SELECT al.company_id, c.name company_name, c.plan,
                   w.name worker_name,
                   COUNT(DISTINCT DATE(al.marked_at)) days_present,
                   SUM(CASE WHEN al.type = 'IN'  THEN -UNIX_TIMESTAMP(al.marked_at) ELSE 0 END)
                 + SUM(CASE WHEN al.type = 'OUT' THEN  UNIX_TIMESTAMP(al.marked_at) ELSE 0 END) AS paired_seconds
            FROM attendance_logs al
            JOIN workers w   ON w.id = al.worker_id
            JOIN companies c ON c.id = al.company_id
            WHERE al.marked_at BETWEEN ? AND ?
            GROUP BY al.company_id, c.name, c.plan, w.id, w.name
            ORDER BY c.name, w.name
        ", [$start->toDateTimeString(), $end->toDateTimeString()]);

        foreach (collect($rows)->groupBy('company_id') as $companyId => $workers) {
            $company = $workers->first();
            if (! PlanService::hasFeature($company->plan ?? 'trial', 'weekly_reports')) {
                continue; // Enterprise only
            }
            $totalDays = (int) $workers->sum('days_present');
            $totalSecs = max(0, (int) $workers->sum('paired_seconds'));
            $hours = intdiv($totalSecs, 3600).':'.str_pad((string) intdiv($totalSecs % 3600, 60), 2, '0', STR_PAD_LEFT);
            $lines = $workers->map(function ($w) {
                $s = max(0, (int) $w->paired_seconds);
                $h = intdiv($s, 3600).':'.str_pad((string) intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT);

                return "• {$w->worker_name} — {$w->days_present} day(s), {$h} h";
            })->implode("\n");

            $admins = User::where('company_id', $companyId)->where('role', 'company_admin')->get();
            $notify->inApp($admins, 'weekly_report',
                "Weekly attendance summary — {$week}", $lines);
            foreach ($admins as $a) {
                $notify->email($a->email, 'weekly_report', [
                    'week'          => $week,
                    'company_name'  => $company->company_name,
                    'summary_lines' => $lines,
                    'total_days'    => $totalDays,
                    'total_hours'   => $hours,
                ], 'company', (int) $companyId, $company->plan ?? 'trial');
            }
            $this->info("{$company->company_name}: {$workers->count()} workers, {$totalDays} days");
        }

        return self::SUCCESS;
    }
}
