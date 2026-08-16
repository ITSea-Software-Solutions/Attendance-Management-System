<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NotifyService;
use App\Services\PlanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily digest: workers whose LAST event today is IN (never marked OUT).
 * Sent per company to its admins (in-app always; email when the plan has
 * missing_out_alerts). Schedule: evening, e.g. 21:00 IST.
 *
 *   php artisan truecrew:missing-out-alerts [--date=YYYY-MM-DD]
 */
class SendMissingOutAlerts extends Command
{
    protected $signature = 'truecrew:missing-out-alerts {--date=}';

    protected $description = 'Notify company admins about workers IN but never OUT for the day';

    public function handle(NotifyService $notify): int
    {
        $date = $this->option('date') ?: now()->toDateString();

        // Last event per worker+company for the day; keep the INs.
        $rows = DB::select("
            SELECT al.company_id, c.name company_name, c.plan, w.name worker_name,
                   v.name vendor_name, MAX(al.marked_at) last_at
            FROM attendance_logs al
            JOIN workers w  ON w.id = al.worker_id
            JOIN vendors v  ON v.id = w.vendor_id
            JOIN companies c ON c.id = al.company_id
            WHERE DATE(al.marked_at) = ?
            GROUP BY al.company_id, c.name, c.plan, w.id, w.name, v.name
            HAVING (SELECT a2.type FROM attendance_logs a2
                    WHERE a2.worker_id = w.id AND a2.company_id = al.company_id
                      AND DATE(a2.marked_at) = ?
                    ORDER BY a2.marked_at DESC LIMIT 1) = 'IN'
        ", [$date, $date]);

        $byCompany = collect($rows)->groupBy('company_id');
        foreach ($byCompany as $companyId => $workers) {
            $company = $workers->first();
            $lines = $workers->map(fn ($w) => "• {$w->worker_name} ({$w->vendor_name}) — IN since ".substr((string) $w->last_at, 11, 5))->implode("\n");
            $admins = User::where('company_id', $companyId)->where('role', 'company_admin')->get();

            $notify->inApp($admins, 'missing_out',
                "{$workers->count()} worker(s) IN but never OUT — {$date}", $lines,
                ['date' => $date, 'company_id' => (int) $companyId]);

            if (PlanService::hasFeature($company->plan ?? 'trial', 'missing_out_alerts')) {
                foreach ($admins as $a) {
                    $notify->email($a->email, 'missing_out_alert', [
                        'date'         => $date,
                        'count'        => $workers->count(),
                        'worker_lines' => $lines,
                    ], 'company', (int) $companyId, $company->plan ?? 'trial');
                }
            }
            $this->info("company {$company->company_name}: {$workers->count()} missing OUT");
        }
        if ($byCompany->isEmpty()) {
            $this->info('No missing-OUT workers today.');
        }

        return self::SUCCESS;
    }
}
