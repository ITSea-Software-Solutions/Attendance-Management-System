<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkerAssignmentController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = WorkerAssignment::with(['worker:id,name,status', 'company:id,name', 'vendor:id,name'])
            ->when($user->isCompanyUser(), fn($q) => $q->where('company_id', $user->company_id))
            ->when($user->isVendorUser(),  fn($q) => $q->where('vendor_id', $user->vendor_id))
            ->when($request->status,  fn($q, $s) => $q->where('status', $s))
            ->when($request->date, fn($q, $d) => $q->where('start_date', '<=', $d)->where('end_date', '>=', $d))
            ->when($request->deployment === 'current', fn($q) =>
                $q->where('status', WorkerAssignment::STATUS_ACTIVE)
                  ->where('start_date', '<=', today())
                  ->where('end_date', '>=', today())
            )
            ->when($request->deployment === 'previous', fn($q) =>
                $q->where(fn($q2) =>
                    $q2->where('status', WorkerAssignment::STATUS_CANCELLED)
                       ->orWhere('end_date', '<', today())
                )
            )
            ->orderByDesc('start_date');

        return response()->json($query->paginate(30));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'worker_id'  => 'required|integer|exists:workers,id',
            'company_id' => 'required|integer|exists:companies,id',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'shift'      => 'nullable|string|in:morning,afternoon,night,general',
            'gate'       => 'nullable|string',
            'notes'      => 'nullable|string',
        ]);

        $worker  = Worker::findOrFail($data['worker_id']);
        $company = Company::findOrFail($data['company_id']);

        abort_if(
            $user->isVendorUser() && $worker->vendor_id !== $user->vendor_id,
            403, 'Cannot deploy a worker from another vendor.'
        );

        $isApproved = $company->vendors()
            ->where('vendor_id', $worker->vendor_id)
            ->where('company_vendors.status', 'approved')
            ->exists();

        abort_unless($isApproved, 422, 'Your contractor is not approved for this company. Request approval first.');

        abort_unless(
            $worker->status === Worker::STATUS_ACTIVE,
            422, 'Worker enrollment is incomplete. Fingerprint must be enrolled first.'
        );

        $overlap = WorkerAssignment::where('worker_id', $data['worker_id'])
            ->where('company_id', $data['company_id'])
            ->where('status', WorkerAssignment::STATUS_ACTIVE)
            ->where('start_date', '<=', $data['end_date'])
            ->where('end_date', '>=', $data['start_date'])
            ->exists();

        abort_if($overlap, 422, 'Worker already has an overlapping active deployment at this company.');

        // Plan quota bites HERE, not at registration: a worker counts only
        // once they actually work. First-ever deployment of a fresh worker
        // is the moment they are about to consume a slot.
        $hasWorked = AttendanceLog::where('worker_id', $worker->id)->exists();
        if (! $hasWorked) {
            if ($deny = \App\Services\PlanService::deny(\App\Services\PlanService::ctx('vendor', $worker->vendor_id), 'workers')) {
                return response()->json($deny, 403);
            }
        }

        $data['vendor_id']   = $worker->vendor_id;
        $data['assigned_by'] = $user->id;
        $data['status']      = WorkerAssignment::STATUS_ACTIVE;
        $data['is_locked']   = false;

        $assignment = WorkerAssignment::create($data);

        // Company-controlled approval: every vendor deployment WAITS for
        // HR/manager approval (who may restrict it to specific
        // gates/departments) — ON by default; a company can opt out in
        // Settings. Deployments made by the company's own staff (or the
        // platform owner) are self-approval and skip the queue.
        $requiresApproval = (bool) (((array) ($company->settings ?? []))['require_deployment_approval'] ?? true);
        if ($user->isSuperAdmin()) {
            $requiresApproval = false; // platform owner deploys are self-approved
        }
        if ($requiresApproval) {
            $assignment->forceFill(['approval_status' => 'pending'])->save();
            $notify = app(\App\Services\NotifyService::class);
            $admins = \App\Models\User::where('company_id', $company->id)
                ->whereIn('role', ['company_admin', 'company_hr'])->get();
            $notify->inApp($admins, 'deployment_requested',
                "Deployment approval needed: {$worker->name}",
                'Vendor '.(optional($worker->vendor)->name ?? '')." requests {$data['start_date']} → {$data['end_date']}.",
                ['assignment_id' => $assignment->id]);
            foreach ($admins as $a) {
                $notify->email($a->email, 'deployment_requested', [
                    'worker_name'  => $worker->name,
                    'vendor_name'  => optional($worker->vendor)->name ?? '',
                    'company_name' => $company->name,
                    'dates'        => "{$data['start_date']} to {$data['end_date']}",
                ], 'company', $company->id, $company->plan ?? 'trial');
            }
        } else {
            $assignment->forceFill(['approval_status' => 'approved', 'approved_at' => now()])->save();
        }

        $this->audit->log($user->id, 'assignment_created', WorkerAssignment::class, $assignment->id, [
            'worker_id'  => $data['worker_id'],
            'company_id' => $data['company_id'],
            'period'     => "{$data['start_date']} → {$data['end_date']}",
        ]);

        $payload = $assignment->load(['worker', 'company', 'vendor'])->toArray();
        $payload['message'] = $requiresApproval
            ? 'Deployment submitted — waiting for the company\'s approval.'
            : 'Worker deployed.';

        return response()->json($payload, 201);
    }

    /** Pending deployment requests for the caller's company (HR approvals). */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || in_array($user->role, ['company_admin', 'company_hr'], true), 403);
        $q = WorkerAssignment::with(['worker:id,name,aadhaar_number_masked', 'vendor:id,name'])
            ->where('approval_status', 'pending')
            ->orderBy('created_at');
        if (! $user->isSuperAdmin()) {
            $q->where('company_id', $user->company_id);
        }

        return response()->json(['pending' => $q->get()]);
    }

    /**
     * Bulk approve deployments — one or many workers at once, optionally
     * restricted to specific gates/departments (null = every gate).
     */
    public function approve(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || in_array($user->role, ['company_admin', 'company_hr'], true), 403);
        $data = $request->validate([
            'ids'                 => 'required|array|min:1',
            'ids.*'               => 'integer',
            'allowed_locations'   => 'nullable|array',
            'allowed_locations.*' => 'string|max:100',
        ]);
        $q = WorkerAssignment::whereIn('id', $data['ids'])->where('approval_status', 'pending');
        if (! $user->isSuperAdmin()) {
            $q->where('company_id', $user->company_id);
        }
        $rows = $q->get();
        $locations = empty($data['allowed_locations'])
            ? null
            : array_values(array_unique($data['allowed_locations']));
        foreach ($rows as $a) {
            $a->forceFill([
                'approval_status'   => 'approved',
                'approved_by'       => $user->id,
                'approved_at'       => now(),
                'allowed_locations' => $locations,
            ])->save();
            $this->audit->log($user->id, 'deployment_approved', WorkerAssignment::class, $a->id, [
                'locations' => $locations,
            ]);
        }
        $notify = app(\App\Services\NotifyService::class);
        foreach ($rows->groupBy('vendor_id') as $vendorId => $group) {
            $vUsers = \App\Models\User::where('vendor_id', $vendorId)->get();
            $names = $group->map(fn ($g) => optional($g->worker)->name)->filter()->implode(', ');
            $notify->inApp($vUsers, 'deployment_decided',
                'Deployment approved: '.$names,
                $locations ? 'Allowed gates: '.implode(', ', $locations) : 'All gates allowed.');
        }

        return response()->json([
            'message' => count($rows).' deployment(s) approved.'
                .($locations ? ' Restricted to: '.implode(', ', $locations) : ' All gates.'),
        ]);
    }

    /** Reject a pending deployment with a reason. */
    public function reject(Request $request, WorkerAssignment $assignment): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin()
            || (in_array($user->role, ['company_admin', 'company_hr'], true)
                && $user->company_id === $assignment->company_id), 403);
        abort_unless($assignment->approval_status === 'pending', 422, 'Already decided.');
        $data = $request->validate(['reason' => 'required|string|min:3|max:300']);
        $assignment->forceFill([
            'approval_status'  => 'rejected',
            'approved_by'      => $user->id,
            'approved_at'      => now(),
            'rejection_reason' => $data['reason'],
        ])->save();
        $this->audit->log($user->id, 'deployment_rejected', WorkerAssignment::class, $assignment->id);
        $vUsers = \App\Models\User::where('vendor_id', $assignment->vendor_id)->get();
        app(\App\Services\NotifyService::class)->inApp($vUsers, 'deployment_decided',
            'Deployment rejected: '.optional($assignment->worker)->name,
            'Reason: '.$data['reason']);

        return response()->json(['message' => 'Deployment rejected.']);
    }

    /** Distinct gate/department names of a company (approval multi-select). */
    public function companyLocations(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || $user->company_id === $company->id, 403);
        $fromUsers = \App\Models\User::where('company_id', $company->id)
            ->whereNotNull('location_name')->pluck('location_name');
        $fromLogs = AttendanceLog::where('company_id', $company->id)
            ->whereNotNull('location_name')->distinct()->pluck('location_name');
        $presets = collect(config('departments.presets', []));

        return response()->json([
            'locations' => $presets->merge($fromUsers)->merge($fromLogs)
                ->unique()->values(),
        ]);
    }

    public function show(Request $request, WorkerAssignment $assignment): JsonResponse
    {
        $this->assertAssignmentVisible($request->user(), $assignment);
        return response()->json($assignment->load(['worker', 'company', 'vendor', 'attendanceLogs']));
    }

    public function update(Request $request, WorkerAssignment $assignment): JsonResponse
    {
        $this->assertAssignmentVisible($request->user(), $assignment);

        if ($assignment->is_locked) {
            return response()->json([
                'message' => 'This deployment is locked — attendance has already been marked. Dates cannot be changed.',
            ], 422);
        }

        $data = $request->validate([
            'start_date' => 'nullable|date|after_or_equal:today',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
            'shift'      => 'nullable|string',
            'gate'       => 'nullable|string',
            'status'     => 'nullable|in:active,cancelled',
            'notes'      => 'nullable|string',
        ]);

        $assignment->update(array_filter($data, fn($v) => $v !== null));
        $this->audit->log($request->user()->id, 'assignment_updated', WorkerAssignment::class, $assignment->id);

        return response()->json($assignment->fresh());
    }

    public function destroy(Request $request, WorkerAssignment $assignment): JsonResponse
    {
        $this->assertAssignmentVisible($request->user(), $assignment);

        if ($assignment->is_locked) {
            // Allow cancel only when no pending IN exists (worker is fully out)
            $latestLog = AttendanceLog::where('worker_id', $assignment->worker_id)
                ->where('company_id', $assignment->company_id)
                ->latest('marked_at')
                ->first();

            if ($latestLog && $latestLog->type === AttendanceLog::TYPE_IN) {
                return response()->json([
                    'message' => 'Worker is currently checked IN. Please mark them OUT before cancelling this deployment.',
                ], 422);
            }
        }

        $assignment->update(['status' => WorkerAssignment::STATUS_CANCELLED]);
        $this->audit->log($request->user()->id, 'assignment_cancelled', WorkerAssignment::class, $assignment->id);

        return response()->json(['message' => 'Deployment cancelled.']);
    }

    public function todayForCompany(Request $request, Company $company): JsonResponse
    {
        $assignments = WorkerAssignment::with([
            'worker:id,name,photo_path,fingerprint_template,status',
            'vendor:id,name',
        ])
            ->forToday()
            ->forCompany($company->id)
            ->get()
            ->map(function ($a) {
                $a->worker->has_fingerprint = $a->worker->hasFingerprint();
                unset($a->worker->fingerprint_template);
                return $a;
            });

        return response()->json($assignments);
    }

    public function forWorker(Request $request, Worker $worker): JsonResponse
    {
        $user = $request->user();
        // Vendor users may only see their own workers' deployments.
        if ($user->isVendorUser()) {
            abort_unless($worker->vendor_id === $user->vendor_id, 403, 'Access denied.');
        }

        $assignments = WorkerAssignment::with(['company:id,name'])
            ->where('worker_id', $worker->id)
            // Company users only see deployments at their own company.
            ->when($user->isCompanyUser(), fn($q) => $q->where('company_id', $user->company_id))
            ->orderByDesc('start_date')
            ->paginate(20);

        return response()->json($assignments);
    }

    /** Tenant scoping for a single assignment. Aborts 403 otherwise. */
    private function assertAssignmentVisible($user, WorkerAssignment $assignment): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }
        if ($user->isVendorUser()) {
            abort_unless($assignment->vendor_id === $user->vendor_id, 403, 'Access denied.');
            return;
        }
        if ($user->isCompanyUser()) {
            abort_unless($assignment->company_id === $user->company_id, 403, 'Access denied.');
            return;
        }
        abort(403, 'Access denied.');
    }
}
