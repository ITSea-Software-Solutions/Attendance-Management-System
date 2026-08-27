<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Vendor;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return response()->json([
                'companies'          => Company::count(),
                'vendors'            => Vendor::count(),
                'workers'            => Worker::count(),
                'active_workers'     => Worker::where('status', 'active')->count(),
                'pending_workers'    => Worker::where('status', 'pending')->count(),
                'today_assignments'  => WorkerAssignment::forToday()->count(),
                'today_in'           => AttendanceLog::today()->where('type', 'IN')->where('is_valid', true)->count(),
                'today_out'          => AttendanceLog::today()->where('type', 'OUT')->where('is_valid', true)->count(),
                'pending_vendor_approvals' => \DB::table('company_vendors')->where('status', 'pending')->count(),
            ]);
        }

        if ($user->isCompanyUser()) {
            $cid = $user->company_id;
            return response()->json([
                'approved_vendors'   => Company::findOrFail($cid)->approvedVendors()->count(),
                'today_assignments'  => WorkerAssignment::forToday()->forCompany($cid)->count(),
                'today_in'           => AttendanceLog::today()->forCompany($cid)->where('type', 'IN')->where('is_valid', true)->count(),
                'today_out'          => AttendanceLog::today()->forCompany($cid)->where('type', 'OUT')->where('is_valid', true)->count(),
                'pending_in'         => $this->missingOutCount($cid),
                'pending_approvals'  => \DB::table('company_vendors')->where('company_id', $cid)->where('status', 'pending')->count(),
            ]);
        }

        if ($user->isVendorUser()) {
            $vid = $user->vendor_id;
            return response()->json([
                'total_workers'     => Worker::where('vendor_id', $vid)->count(),
                'active_workers'    => Worker::where('vendor_id', $vid)->where('status', 'active')->count(),
                'pending_workers'   => Worker::where('vendor_id', $vid)->where('status', 'pending')->count(),
                'approved_companies' => Vendor::findOrFail($vid)->approvedCompanies()->count(),
                'today_assignments' => WorkerAssignment::forToday()->forVendor($vid)->count(),
                'today_present'     => AttendanceLog::today()
                    ->where('is_valid', true)
                    ->where('type', 'IN')
                    ->whereHas('worker', fn($q) => $q->where('vendor_id', $vid))
                    ->count(),
            ]);
        }

        return response()->json([]);
    }

    public function todayAttendance(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = AttendanceLog::with(['worker:id,name', 'markedBy:id,name'])
            ->today()
            ->where('is_valid', true)
            ->when($user->isCompanyUser(), fn($q) => $q->where('company_id', $user->company_id))
            ->when($user->isVendorUser(), fn($q) => $q->whereHas('worker', fn($wq) => $wq->where('vendor_id', $user->vendor_id)))
            ->orderByDesc('marked_at')
            ->limit(50);

        return response()->json($query->get());
    }

    public function recentActivity(Request $request): JsonResponse
    {
        $user = $request->user();

        $logs = \App\Models\AuditLog::with('user:id,name')
            ->when(! $user->isSuperAdmin(), fn($q) => $q->where('user_id', $user->id))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json($logs);
    }

    /**
     * One-call, role-scoped dashboard payload: KPIs, 30-day trend,
     * week/month comparisons, today's hourly flow, a presence breakdown,
     * a donut split, actionable "attention" chips, and the recent feed.
     * Everything is computed from a handful of grouped queries.
     */
    public function overview(Request $request): JsonResponse
    {
        $user  = $request->user();
        $today = today();

        // ── Role scope applied to every attendance query ────────────────────
        $scoped = function () use ($user) {
            return AttendanceLog::query()
                ->where('is_valid', true)
                ->when($user->isCompanyUser(), fn ($q) => $q->where('company_id', $user->company_id))
                ->when($user->isVendorUser(), fn ($q) => $q->whereHas(
                    'worker', fn ($w) => $w->where('vendor_id', $user->vendor_id)));
        };

        // Daily series (last 65 days covers this month + all of last month)
        $daily = $scoped()
            ->where('marked_at', '>=', $today->copy()->subDays(64)->startOfDay())
            ->selectRaw("DATE(marked_at) as d,
                         COUNT(DISTINCT CASE WHEN type = 'IN' THEN worker_id END) as present,
                         COUNT(*) as marks")
            ->groupBy('d')->orderBy('d')->get()->keyBy('d');

        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = $today->copy()->subDays($i)->toDateString();
            $row = $daily->get($d);
            $series[] = ['d' => $d, 'present' => (int) ($row->present ?? 0), 'marks' => (int) ($row->marks ?? 0)];
        }

        $sumPresent = function ($from, $to) use ($daily) {
            $s = 0;
            foreach ($daily as $d => $row) {
                if ($d >= $from && $d <= $to) {
                    $s += (int) $row->present;
                }
            }
            return $s; // unique worker-DAYS in the window
        };
        $weekCompare = [
            'this_week' => $sumPresent($today->copy()->startOfWeek()->toDateString(), $today->toDateString()),
            'last_week' => $sumPresent($today->copy()->subWeek()->startOfWeek()->toDateString(),
                                       $today->copy()->subWeek()->endOfWeek()->toDateString()),
        ];
        $monthCompare = [
            'this_month' => $sumPresent($today->copy()->startOfMonth()->toDateString(), $today->toDateString()),
            'last_month' => $sumPresent($today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                                        $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()),
        ];

        $presentToday     = (int) ($daily->get($today->toDateString())->present ?? 0);
        $presentYesterday = (int) ($daily->get($today->copy()->subDay()->toDateString())->present ?? 0);

        // Hourly IN flow today
        $hourlyRaw = $scoped()->whereDate('marked_at', $today)->where('type', 'IN')
            ->selectRaw('HOUR(marked_at) as h, COUNT(*) as c')->groupBy('h')->pluck('c', 'h');
        $hourly = [];
        for ($h = 0; $h < 24; $h++) {
            $hourly[] = (int) ($hourlyRaw[$h] ?? 0);
        }

        // Recent feed (today, newest first)
        $recent = $scoped()->with('worker:id,name')->whereDate('marked_at', $today)
            ->orderByDesc('marked_at')->limit(8)->get()
            ->map(fn ($l) => [
                'id'     => $l->id,
                'worker' => $l->worker?->name,
                'worker_id' => $l->worker_id,
                'type'   => $l->type,
                'time'   => $l->marked_at?->format('H:i'),
                'gate'   => $l->location_name,
                'method' => $l->method,
            ]);

        // Active approved deployments covering today, role-scoped
        $deployQ = fn () => WorkerAssignment::where('status', WorkerAssignment::STATUS_ACTIVE)
            ->where('approval_status', 'approved')
            ->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)
            ->when($user->isCompanyUser(), fn ($q) => $q->where('company_id', $user->company_id))
            ->when($user->isVendorUser(), fn ($q) => $q->whereHas(
                'worker', fn ($w) => $w->where('vendor_id', $user->vendor_id)));
        $deployedToday = (int) $deployQ()->distinct('worker_id')->count('worker_id');

        $kpis = [];
        $breakdown = [];
        $donut = [];
        $attention = [];
        $roleView = 'company';

        if ($user->isSuperAdmin()) {
            $roleView = 'super';
            $pendingVendorApprovals = \DB::table('company_vendors')->where('status', 'pending')->count();
            $pendingUpgrades = \App\Models\PlanUpgradeRequest::where('status', 'pending')->count();
            $expiring = Company::whereBetween('plan_expires_at', [now(), now()->addDays(7)])->count()
                      + Vendor::whereBetween('plan_expires_at', [now(), now()->addDays(7)])->count();

            $kpis = [
                'companies'      => Company::count(),
                'vendors'        => Vendor::count(),
                'workers_active' => Worker::where('status', 'active')->count(),
                'present_today'  => $presentToday,
                'deployed_today' => $deployedToday,
                'marks_today'    => (int) ($daily->get($today->toDateString())->marks ?? 0),
            ];

            // Presence per company (top 8)
            $present = AttendanceLog::today()->valid()->where('type', 'IN')
                ->join('companies', 'companies.id', '=', 'attendance_logs.company_id')
                ->groupBy('companies.id', 'companies.name')
                ->selectRaw('companies.name as label, COUNT(DISTINCT attendance_logs.worker_id) as present')
                ->orderByDesc('present')->limit(8)->get();
            $deployed = WorkerAssignment::where('worker_assignments.status', WorkerAssignment::STATUS_ACTIVE)
                ->where('worker_assignments.approval_status', 'approved')
                ->whereDate('worker_assignments.start_date', '<=', $today)
                ->whereDate('worker_assignments.end_date', '>=', $today)
                ->join('companies', 'companies.id', '=', 'worker_assignments.company_id')
                ->groupBy('companies.name')
                ->selectRaw('companies.name as label, COUNT(DISTINCT worker_assignments.worker_id) as deployed')
                ->pluck('deployed', 'label');
            $breakdown = $present->map(fn ($r) => [
                'label' => $r->label, 'present' => (int) $r->present,
                'deployed' => (int) ($deployed[$r->label] ?? 0),
            ])->values();

            // Orgs by plan
            $plans = [];
            foreach ([Company::class, Vendor::class] as $m) {
                foreach ($m::selectRaw("COALESCE(plan,'trial') as p, COUNT(*) c")->groupBy('p')->pluck('c', 'p') as $p => $c) {
                    $plans[$p] = ($plans[$p] ?? 0) + $c;
                }
            }
            foreach (['trial', 'professional', 'enterprise'] as $p) {
                if (($plans[$p] ?? 0) > 0) {
                    $donut[] = ['label' => ucfirst($p), 'value' => $plans[$p]];
                }
            }

            if ($pendingUpgrades)        $attention[] = ['label' => 'Plan requests to verify', 'count' => $pendingUpgrades, 'to' => '/subscriptions'];
            if ($expiring)               $attention[] = ['label' => 'Licences expiring in 7 days', 'count' => $expiring, 'to' => '/subscriptions'];
            if ($pendingVendorApprovals) $attention[] = ['label' => 'Vendor approvals pending', 'count' => $pendingVendorApprovals, 'to' => '/vendors/approval'];
        } elseif ($user->isCompanyUser()) {
            $cid = $user->company_id;
            $stillInside = $this->missingOutCount($cid);
            $pendingDeploys = WorkerAssignment::where('company_id', $cid)
                ->where('approval_status', 'pending')->where('status', WorkerAssignment::STATUS_ACTIVE)
                ->whereDate('end_date', '>=', $today)->count();
            $pendingVendors = \DB::table('company_vendors')->where('company_id', $cid)->where('status', 'pending')->count();
            $pendingPasses = \App\Models\GatePass::where('company_id', $cid)
                ->where('status', \App\Models\GatePass::STATUS_PENDING)->whereDate('created_at', $today)->count();

            $kpis = [
                'present_today'  => $presentToday,
                'deployed_today' => $deployedToday,
                'still_inside'   => $stillInside,
                'today_in'       => (int) $scoped()->whereDate('marked_at', $today)->where('type', 'IN')->count(),
                'today_out'      => (int) $scoped()->whereDate('marked_at', $today)->where('type', 'OUT')->count(),
                'vendors'        => Company::findOrFail($cid)->approvedVendors()->count(),
            ];

            // Presence per vendor today
            $present = AttendanceLog::today()->valid()->forCompany($cid)->where('type', 'IN')
                ->join('workers', 'workers.id', '=', 'attendance_logs.worker_id')
                ->join('vendors', 'vendors.id', '=', 'workers.vendor_id')
                ->groupBy('vendors.id', 'vendors.name')
                ->selectRaw('vendors.name as label, COUNT(DISTINCT attendance_logs.worker_id) as present')
                ->orderByDesc('present')->limit(8)->get();
            $deployed = WorkerAssignment::where('worker_assignments.company_id', $cid)
                ->where('worker_assignments.status', WorkerAssignment::STATUS_ACTIVE)
                ->where('worker_assignments.approval_status', 'approved')
                ->whereDate('worker_assignments.start_date', '<=', $today)
                ->whereDate('worker_assignments.end_date', '>=', $today)
                ->join('workers', 'workers.id', '=', 'worker_assignments.worker_id')
                ->join('vendors', 'vendors.id', '=', 'workers.vendor_id')
                ->groupBy('vendors.name')
                ->selectRaw('vendors.name as label, COUNT(DISTINCT worker_assignments.worker_id) as deployed')
                ->pluck('deployed', 'label');
            $labels = $present->pluck('label')->all();
            foreach ($deployed as $label => $c) {
                if (! in_array($label, $labels)) {
                    $present->push((object) ['label' => $label, 'present' => 0]);
                }
            }
            $breakdown = $present->map(fn ($r) => [
                'label' => $r->label, 'present' => (int) $r->present,
                'deployed' => (int) ($deployed[$r->label] ?? 0),
            ])->values();

            $absent = max(0, $deployedToday - $presentToday);
            $donut = array_values(array_filter([
                ['label' => 'Present', 'value' => $presentToday],
                ['label' => 'Not arrived', 'value' => $absent],
            ], fn ($x) => $x['value'] > 0));

            if ($pendingDeploys) $attention[] = ['label' => 'Deployments awaiting approval', 'count' => $pendingDeploys, 'to' => '/workers/assign'];
            if ($stillInside)    $attention[] = ['label' => 'Workers still inside', 'count' => $stillInside, 'to' => '/attendance/exceptions'];
            if ($pendingVendors) $attention[] = ['label' => 'Vendor requests pending', 'count' => $pendingVendors, 'to' => '/vendors/approval'];
            if ($pendingPasses)  $attention[] = ['label' => 'Gate passes awaiting decision', 'count' => $pendingPasses, 'to' => '/visitors'];
        } elseif ($user->isVendorUser()) {
            $roleView = 'vendor';
            $vid = $user->vendor_id;
            $workersTotal  = Worker::where('vendor_id', $vid)->count();
            $workersActive = Worker::where('vendor_id', $vid)->where('status', 'active')->count();
            $aadhaarPending = Worker::where('vendor_id', $vid)->whereNull('aadhaar_verified_at')->count();
            $fpPending = Worker::where('vendor_id', $vid)->where('status', 'pending')->count();
            $deployedIds = $deployQ()->pluck('worker_id')->unique();
            $benched = Worker::where('vendor_id', $vid)->where('status', 'active')
                ->whereNotIn('id', $deployedIds)->count();
            $expiring = WorkerAssignment::where('status', WorkerAssignment::STATUS_ACTIVE)
                ->where('approval_status', 'approved')
                ->whereHas('worker', fn ($w) => $w->where('vendor_id', $vid))
                ->whereDate('end_date', '>=', $today)
                ->whereDate('end_date', '<=', $today->copy()->addDays(3))->count();

            $kpis = [
                'workers_total'  => $workersTotal,
                'workers_active' => $workersActive,
                'present_today'  => $presentToday,
                'deployed_today' => $deployedToday,
                'benched'        => $benched,
                'companies'      => Vendor::findOrFail($vid)->approvedCompanies()->count(),
            ];

            // Presence per company today (their workers)
            $present = AttendanceLog::today()->valid()->where('type', 'IN')
                ->whereHas('worker', fn ($w) => $w->where('vendor_id', $vid))
                ->join('companies', 'companies.id', '=', 'attendance_logs.company_id')
                ->groupBy('companies.id', 'companies.name')
                ->selectRaw('companies.name as label, COUNT(DISTINCT attendance_logs.worker_id) as present')
                ->orderByDesc('present')->limit(8)->get();
            $deployed = WorkerAssignment::where('worker_assignments.status', WorkerAssignment::STATUS_ACTIVE)
                ->where('worker_assignments.approval_status', 'approved')
                ->whereDate('worker_assignments.start_date', '<=', $today)
                ->whereDate('worker_assignments.end_date', '>=', $today)
                ->whereHas('worker', fn ($w) => $w->where('vendor_id', $vid))
                ->join('companies', 'companies.id', '=', 'worker_assignments.company_id')
                ->groupBy('companies.name')
                ->selectRaw('companies.name as label, COUNT(DISTINCT worker_assignments.worker_id) as deployed')
                ->pluck('deployed', 'label');
            $labels = $present->pluck('label')->all();
            foreach ($deployed as $label => $c) {
                if (! in_array($label, $labels)) {
                    $present->push((object) ['label' => $label, 'present' => 0]);
                }
            }
            $breakdown = $present->map(fn ($r) => [
                'label' => $r->label, 'present' => (int) $r->present,
                'deployed' => (int) ($deployed[$r->label] ?? 0),
            ])->values();

            $donut = array_values(array_filter([
                ['label' => 'Deployed', 'value' => $deployedToday],
                ['label' => 'On bench', 'value' => $benched],
                ['label' => 'Pending enroll', 'value' => $fpPending],
            ], fn ($x) => $x['value'] > 0));

            if ($expiring)       $attention[] = ['label' => 'Deployments ending in 3 days', 'count' => $expiring, 'to' => '/workers/assign'];
            if ($benched)        $attention[] = ['label' => 'Active workers not deployed', 'count' => $benched, 'to' => '/workers'];
            if ($aadhaarPending) $attention[] = ['label' => 'Aadhaar verification pending', 'count' => $aadhaarPending, 'to' => '/workers'];
            if ($fpPending)      $attention[] = ['label' => 'Fingerprint enrollment pending', 'count' => $fpPending, 'to' => '/workers'];
        }

        return response()->json([
            'role_view'         => $roleView,
            'today'             => $today->toDateString(),
            'kpis'              => $kpis,
            'present_today'     => $presentToday,
            'present_yesterday' => $presentYesterday,
            'trend'             => $series,
            'week_compare'      => $weekCompare,
            'month_compare'     => $monthCompare,
            'hourly'            => $hourly,
            'breakdown'         => $breakdown,
            'donut'             => $donut,
            'attention'         => $attention,
            'recent'            => $recent,
        ]);
    }

    private function missingOutCount(int $companyId): int
    {
        return AttendanceLog::where('type', 'IN')
            ->where('company_id', $companyId)
            ->where('is_valid', true)
            ->whereDate('marked_at', today())
            ->whereNotExists(function ($query) use ($companyId) {
                $query->from('attendance_logs as out')
                    ->whereColumn('out.worker_id', 'attendance_logs.worker_id')
                    ->where('out.company_id', $companyId)
                    ->where('out.type', 'OUT')
                    ->where('out.is_valid', true)
                    ->whereDate('out.marked_at', today());
            })
            ->count();
    }
}
