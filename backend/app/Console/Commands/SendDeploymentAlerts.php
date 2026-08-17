<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Services\NotifyService;
use Illuminate\Console\Command;

/**
 * Daily vendor digests (in-app always; email on Professional+):
 *  - ACTIVE workers with no current/upcoming approved deployment ("benched")
 *  - deployments ending within 3 days (renew or plan the exit)
 *
 *   php artisan truecrew:deployment-alerts
 */
class SendDeploymentAlerts extends Command
{
    protected $signature = 'truecrew:deployment-alerts';

    protected $description = 'Notify vendors about undeployed workers and deployments nearing expiry';

    public function handle(NotifyService $notify): int
    {
        $vendors = \App\Models\Vendor::where('status', 'active')->get();
        foreach ($vendors as $vendor) {
            $users = User::where('vendor_id', $vendor->id)->get();
            if ($users->isEmpty()) {
                continue;
            }

            // Benched: active workers without a current-or-future approved deployment
            $benched = Worker::where('vendor_id', $vendor->id)
                ->where('status', Worker::STATUS_ACTIVE)
                ->whereDoesntHave('assignments', fn ($q) => $q
                    ->where('status', WorkerAssignment::STATUS_ACTIVE)
                    ->where('approval_status', 'approved')
                    ->where('end_date', '>=', today()))
                ->pluck('name');

            if ($benched->isNotEmpty()) {
                $notify->inApp($users, 'workers_undeployed',
                    $benched->count().' worker(s) ready but not deployed',
                    $benched->take(8)->implode(', ').($benched->count() > 8 ? '…' : ''));
            }

            // Expiring: approved deployments ending within 3 days
            $expiring = WorkerAssignment::with(['worker:id,name', 'company:id,name'])
                ->where('vendor_id', $vendor->id)
                ->where('status', WorkerAssignment::STATUS_ACTIVE)
                ->where('approval_status', 'approved')
                ->whereBetween('end_date', [today(), today()->addDays(3)])
                ->get();

            if ($expiring->isNotEmpty()) {
                $lines = $expiring->map(fn ($a) => '• '.optional($a->worker)->name
                    .' @ '.optional($a->company)->name
                    .' — ends '.$a->end_date?->format('d M'))->implode("\n");
                $notify->inApp($users, 'deployment_expiring',
                    $expiring->count().' deployment(s) ending within 3 days', $lines);
                foreach ($users->where('role', 'vendor_admin') as $u) {
                    $notify->email($u->email, 'deployment_expiring', [
                        'count' => $expiring->count(),
                        'lines' => $lines,
                    ], 'vendor', $vendor->id, $vendor->plan ?? 'trial');
                }
            }

            if ($benched->isNotEmpty() || $expiring->isNotEmpty()) {
                $this->info("{$vendor->name}: benched={$benched->count()} expiring={$expiring->count()}");
            }
        }

        return self::SUCCESS;
    }
}
