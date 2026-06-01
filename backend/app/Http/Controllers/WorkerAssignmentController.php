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

        abort_unless($isApproved, 422, 'Your vendor is not approved for this company. Request approval first.');

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

        $data['vendor_id']   = $worker->vendor_id;
        $data['assigned_by'] = $user->id;
        $data['status']      = WorkerAssignment::STATUS_ACTIVE;
        $data['is_locked']   = false;

        $assignment = WorkerAssignment::create($data);

        $this->audit->log($user->id, 'assignment_created', WorkerAssignment::class, $assignment->id, [
            'worker_id'  => $data['worker_id'],
            'company_id' => $data['company_id'],
            'period'     => "{$data['start_date']} → {$data['end_date']}",
        ]);

        return response()->json($assignment->load(['worker', 'company', 'vendor']), 201);
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
