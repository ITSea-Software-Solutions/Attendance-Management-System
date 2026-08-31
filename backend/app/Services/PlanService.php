<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;

/**
 * SaaS plan limits — resolution, usage counting, and enforcement.
 * Limits come from config/plans.php; null = unlimited.
 */
class PlanService
{
    /** Resolve the org (company|vendor model) a user belongs to; null for super admin. */
    public static function orgFor(User $user): ?array
    {
        if ($user->company_id) {
            $org = Company::find($user->company_id);
            return $org ? ['type' => 'company', 'org' => $org] : null;
        }
        if ($user->vendor_id) {
            $org = Vendor::find($user->vendor_id);
            return $org ? ['type' => 'vendor', 'org' => $org] : null;
        }
        return null;
    }

    public static function limits(string $plan): array
    {
        return config("plans.plans.$plan") ?? config('plans.plans.trial');
    }

    /**
     * Licence check: the plan that is actually IN FORCE for an org.
     * A paid plan past its plan_expires_at degrades to trial at read time —
     * nothing is mutated; a verified renewal instantly restores it.
     */
    public static function effectivePlan($org): string
    {
        $plan = $org->plan ?? 'trial';
        if ($plan !== 'trial'
            && $org->plan_expires_at
            && now()->greaterThan($org->plan_expires_at)) {
            return 'trial';
        }

        return $plan;
    }

    /** Days until the licence lapses (null = no expiry; negative = lapsed). */
    public static function daysLeft($org): ?int
    {
        if (! $org->plan_expires_at || ($org->plan ?? 'trial') === 'trial') {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($org->plan_expires_at, false);
    }

    /** Whether a plan includes a named feature (see config/plans.php). */
    public static function hasFeature(string $plan, string $feature): bool
    {
        return in_array($feature, self::limits($plan)['features'] ?? [], true);
    }

    /** Feature check straight from a user (super admin has everything). */
    public static function userHasFeature(User $user, string $feature): bool
    {
        $ctx = self::orgFor($user);
        if (! $ctx) {
            return true; // super admin
        }

        return self::hasFeature(self::effectivePlan($ctx['org']), $feature);
    }

    /** Current usage counters for an org. */
    public static function usage(string $type, int $orgId): array
    {
        if ($type === 'company') {
            return [
                'users'   => User::where('company_id', $orgId)->count(),
                'workers' => null, // workers belong to vendors
                'links'   => DB::table('company_vendors')
                    ->where('company_id', $orgId)->where('status', 'approved')->count(),
            ];
        }
        return [
            'users'   => User::where('vendor_id', $orgId)->count(),
            // A worker consumes quota only once they have ACTUALLY WORKED
            // (deployed + at least one attendance event). Registering and
            // deleting workers is free; deleting a worked worker does NOT
            // free the slot (withTrashed) — no delete-to-reset gaming.
            'workers' => Worker::withTrashed()->where('vendor_id', $orgId)
                ->whereHas('attendanceLogs')->count(),
            'links'   => DB::table('company_vendors')
                ->where('vendor_id', $orgId)->where('status', 'approved')->count(),
        ];
    }

    /**
     * Enforcement gate. $metric: users|workers|links. Returns null when the
     * org may add one more, or a ready-to-send error payload when the plan
     * limit is reached. Super admin context (no org) is never limited.
     */
    public static function deny(?array $orgCtx, string $metric): ?array
    {
        if (! $orgCtx) {
            return null;
        }
        $org    = $orgCtx['org'];
        // Licence-aware: an expired paid plan enforces TRIAL limits until renewed.
        $plan   = self::effectivePlan($org);
        $lapsed = $plan !== ($org->plan ?? 'trial');
        $limit  = self::limits($plan)[$metric] ?? null;
        if ($limit === null) {
            return null;
        }
        $used = self::usage($orgCtx['type'], $org->id)[$metric] ?? 0;
        if ($used < $limit) {
            return null;
        }
        $labels = ['users' => 'users', 'workers' => 'workers', 'links' => 'company–vendor links'];
        return [
            'message' => $lapsed
                ? "Your {$org->plan} licence has EXPIRED — trial limits apply ({$limit} {$labels[$metric]}). Renew on the Billing page to restore your plan."
                : ucfirst(self::limits($plan)['label']) . " plan limit reached ({$limit} {$labels[$metric]}). Upgrade your plan to add more.",
            'code'    => $lapsed ? 'PLAN_EXPIRED' : 'PLAN_LIMIT',
            'metric'  => $metric,
            'limit'   => $limit,
            'plan'    => $plan,
        ];
    }

    /** Convenience: resolve org by explicit type/id (e.g. counting against a target vendor). */
    public static function ctx(string $type, int $orgId): ?array
    {
        $org = $type === 'company' ? Company::find($orgId) : Vendor::find($orgId);
        return $org ? ['type' => $type, 'org' => $org] : null;
    }
}
