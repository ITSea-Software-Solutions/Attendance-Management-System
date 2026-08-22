<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Models\Vendor;
use App\Services\NotifyService;
use App\Services\PlanService;
use Illuminate\Console\Command;

/**
 * Licence lifecycle digests (daily 09:00 IST):
 *  - paid licences expiring within 7 days → renew reminder to org admins
 *  - licences that lapsed in the last day → trial-limits notice
 *
 *   php artisan truecrew:license-alerts
 */
class SendLicenseAlerts extends Command
{
    protected $signature = 'truecrew:license-alerts';

    protected $description = 'Remind orgs about expiring licences; notify freshly-lapsed ones';

    public function handle(NotifyService $notify): int
    {
        foreach (['company' => Company::all(), 'vendor' => Vendor::all()] as $type => $orgs) {
            foreach ($orgs as $org) {
                if (($org->plan ?? 'trial') === 'trial' || ! $org->plan_expires_at) {
                    continue;
                }
                $admins = User::where("{$type}_id", $org->id)
                    ->whereIn('role', [$type.'_admin'])->get();
                if ($admins->isEmpty()) {
                    continue;
                }
                $days = PlanService::daysLeft($org);

                if ($days !== null && $days >= 0 && $days <= 7) {
                    $notify->inApp($admins, 'license_expiring',
                        "Your {$org->plan} licence expires in {$days} day(s)",
                        'Renew from Plan & Billing (record the payment there) to avoid dropping to trial limits on '
                        .$org->plan_expires_at->format('d M Y').'.');
                    foreach ($admins as $a) {
                        $notify->email($a->email, 'license_expiring', [
                            'plan'    => $org->plan,
                            'days'    => $days,
                            'date'    => $org->plan_expires_at->format('d M Y'),
                            'org_name' => $org->name,
                        ], $type, $org->id, $org->plan ?? 'trial');
                    }
                    $this->info("{$org->name}: expiring in {$days}d");
                } elseif ($days !== null && $days < 0 && $days >= -1) {
                    $notify->inApp($admins, 'license_lapsed',
                        "Your {$org->plan} licence has expired — trial limits now apply",
                        'Nothing was deleted. Renew from Plan & Billing and your plan restores instantly after verification.');
                    $this->info("{$org->name}: lapsed");
                }
            }
        }

        return self::SUCCESS;
    }
}
