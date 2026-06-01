<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Services\AuditService;
use App\Services\BiometricService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttendanceController extends Controller
{
    public function __construct(
        private AuditService $audit,
        private BiometricService $biometric,
    ) {}

    /** Demo deployments run with BIOMETRIC_SIM=true so the fingerprint flow is
     *  usable without a SecuGen device. MUST be false in production. */
    private function simulationEnabled(): bool
    {
        return (bool) config('biometric.simulation', false);
    }

    // ─── Daily summary (one row per worker per day) ───────────────────────────

    public function dailySummary(Request $request): JsonResponse
    {
        $user      = $request->user();
        $companyId = $user->isCompanyUser() ? $user->company_id : null;

        $rows = \DB::table('attendance_logs as al')
            ->join('workers as w',      'w.id',  '=', 'al.worker_id')
            ->leftJoin('vendors as v',   'v.id',  '=', 'w.vendor_id')
            ->leftJoin('companies as c', 'c.id',  '=', 'al.company_id')
            ->selectRaw("
                al.worker_id,
                al.company_id,
                w.name                                                          as worker_name,
                v.name                                                          as vendor_name,
                c.name                                                          as company_name,
                DATE(al.marked_at)                                              as work_date,
                MIN(CASE WHEN al.type='IN'  THEN al.marked_at ELSE NULL END)    as first_in,
                MAX(CASE WHEN al.type='OUT' THEN al.marked_at ELSE NULL END)    as last_out,
                COUNT(*)                                                        as total_events,
                SUM(CASE WHEN al.type='IN'  THEN 1 ELSE 0 END)                 as in_count,
                SUM(CASE WHEN al.type='OUT' THEN 1 ELSE 0 END)                 as out_count,
                GROUP_CONCAT(DISTINCT al.location_name SEPARATOR ', ')          as locations
            ")
            ->when($companyId,               fn($q) => $q->where('al.company_id', $companyId))
            ->when($user->isVendorUser(),    fn($q) => $q->where('w.vendor_id', $user->vendor_id))
            ->when($request->date,           fn($q, $d) => $q->whereDate('al.marked_at', $d))
            ->when($request->search,         fn($q, $s) => $q->where('w.name', 'like', "%{$s}%"))
            ->when($request->deployment === 'current', fn($q) =>
                $q->whereExists(function ($sub) use ($companyId) {
                    $sub->from('worker_assignments')
                        ->whereColumn('worker_assignments.worker_id', 'al.worker_id')
                        ->where('worker_assignments.status', 'active')
                        ->where('worker_assignments.start_date', '<=', today())
                        ->where('worker_assignments.end_date', '>=', today());
                    if ($companyId) {
                        $sub->where('worker_assignments.company_id', $companyId);
                    }
                })
            )
            ->when($request->deployment === 'previous', fn($q) =>
                $q->whereNotExists(function ($sub) use ($companyId) {
                    $sub->from('worker_assignments')
                        ->whereColumn('worker_assignments.worker_id', 'al.worker_id')
                        ->where('worker_assignments.status', 'active')
                        ->where('worker_assignments.start_date', '<=', today())
                        ->where('worker_assignments.end_date', '>=', today());
                    if ($companyId) {
                        $sub->where('worker_assignments.company_id', $companyId);
                    }
                })
            )
            ->groupBy('al.worker_id', 'al.company_id', \DB::raw('DATE(al.marked_at)'))
            ->orderByRaw('MIN(al.marked_at) DESC');

        return response()->json($rows->paginate(30));
    }

    // ─── List attendance ──────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = AttendanceLog::with([
                'worker:id,name,vendor_id,aadhaar_number_masked',
                'worker.vendor:id,name',
                'company:id,name',
                'markedBy:id,name',
            ])
            ->when($user->isCompanyUser(), fn($q) => $q->where('company_id', $user->company_id))
            ->when($user->isVendorUser(), fn($q) =>
                $q->whereHas('worker', fn($wq) => $wq->where('vendor_id', $user->vendor_id))
            )
            ->when($request->date,      fn($q, $d) => $q->whereDate('marked_at', $d))
            ->when($request->worker_id, fn($q, $id) => $q->where('worker_id', $id))
            ->when($request->type,      fn($q, $t) => $q->where('type', strtoupper($t)))
            ->when($request->location,  fn($q, $l) => $q->where('location_name', $l))
            ->when($request->deployment === 'current', fn($q) =>
                $q->whereHas('worker.assignments', fn($q2) =>
                    $q2->where('status', 'active')
                       ->where('start_date', '<=', today())
                       ->where('end_date', '>=', today())
                )
            )
            ->when($request->deployment === 'previous', fn($q) =>
                $q->whereHas('worker.assignments')
                  ->whereDoesntHave('worker.assignments', fn($q2) =>
                      $q2->where('status', 'active')
                         ->where('start_date', '<=', today())
                         ->where('end_date', '>=', today())
                  )
            )
            ->orderByDesc('marked_at');

        return response()->json($query->paginate(50));
    }

    // ─── Deployed workers for the fingerprint screen ─────────────────────────
    // NOTE: fingerprint templates are NEVER returned to the client. Matching is
    // performed server-side via the identify() endpoint. This list only carries
    // display metadata + whether each worker is enrolled.

    public function workerTemplates(Request $request): JsonResponse
    {
        $user      = $request->user();
        $companyId = $request->input('company_id') ?? $user->company_id;

        if (! $companyId) {
            return response()->json(['message' => 'company_id is required.'], 422);
        }

        if ($user->isCompanyUser() && $user->company_id !== (int) $companyId) {
            return response()->json(['message' => 'Unauthorized company.'], 403);
        }

        // Only workers with an active deployment covering today
        $activeWorkerIds = WorkerAssignment::where('company_id', $companyId)
            ->where('status', WorkerAssignment::STATUS_ACTIVE)
            ->where('start_date', '<=', today())
            ->where('end_date', '>=', today())
            ->pluck('worker_id');

        $workers = Worker::with('vendor')
            ->whereIn('id', $activeWorkerIds)
            ->whereNotNull('fingerprint_template')
            ->where('status', Worker::STATUS_ACTIVE)
            ->get();

        // Use the gate user's own location for pending-type determination
        $gateLocation = ($user->isGateUser() && $user->location_name)
            ? $user->location_name
            : AttendanceLog::DEFAULT_LOCATION_NAME;

        $result = $workers->map(function ($worker) use ($companyId, $gateLocation) {
            $lastLog = AttendanceLog::where('worker_id', $worker->id)
                ->where('company_id', $companyId)
                ->where('location_name', $gateLocation)
                ->today()
                ->valid()
                ->orderByDesc('marked_at')
                ->first();

            $pendingType = ($lastLog?->type === AttendanceLog::TYPE_IN)
                ? AttendanceLog::TYPE_OUT
                : AttendanceLog::TYPE_IN;

            return [
                'worker_id'             => $worker->id,
                'name'                  => $worker->name,
                'photo_url'             => $worker->photo_url,
                'aadhaar_number_masked' => $worker->aadhaar_number_masked,
                'vendor'                => $worker->vendor?->name,
                'assignment_id'         => null,
                'pending_type'          => $pendingType,
                'enrolled'              => true,
            ];
        });

        return response()->json($result);
    }

    // ─── Assigned workers list (for photo / manual attendance) ───────────────

    public function assignedWorkers(Request $request): JsonResponse
    {
        $user      = $request->user();
        $companyId = $request->input('company_id') ?? $user->company_id;

        if (! $companyId) {
            return response()->json(['message' => 'company_id is required.'], 422);
        }

        if ($user->isCompanyUser() && $user->company_id !== (int) $companyId) {
            return response()->json(['message' => 'Unauthorized company.'], 403);
        }

        $activeWorkerIds = WorkerAssignment::where('company_id', $companyId)
            ->where('status', WorkerAssignment::STATUS_ACTIVE)
            ->where('start_date', '<=', today())
            ->where('end_date', '>=', today())
            ->pluck('worker_id');

        $workers = Worker::with('vendor')
            ->whereIn('id', $activeWorkerIds)
            ->where('status', Worker::STATUS_ACTIVE)
            ->when($request->search, fn($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('name')
            ->get();

        $gateLocation = ($user->isGateUser() && $user->location_name)
            ? $user->location_name
            : AttendanceLog::DEFAULT_LOCATION_NAME;

        $result = $workers->map(function ($worker) use ($companyId, $gateLocation) {
            $lastLog = AttendanceLog::where('worker_id', $worker->id)
                ->where('company_id', $companyId)
                ->where('location_name', $gateLocation)
                ->today()->valid()
                ->orderByDesc('marked_at')
                ->first();

            return [
                'worker_id'    => $worker->id,
                'name'         => $worker->name,
                'photo_url'    => $worker->photo_url,
                'vendor'       => $worker->vendor?->name,
                'pending_type' => ($lastLog?->type === 'IN') ? 'OUT' : 'IN',
            ];
        });

        return response()->json($result);
    }

    // ─── Identify worker by fingerprint (server-side 1:N match) ──────────────
    // The probe template is matched against stored templates ON THE SERVER so
    // raw templates never reach the browser and the match cannot be forged
    // client-side. Returns the matched worker (no template) or 404.

    public function identify(Request $request): JsonResponse
    {
        $request->validate([
            'company_id'     => 'required|integer|exists:companies,id',
            'probe_template' => 'required|string',
        ]);

        $user      = $request->user();
        $companyId = (int) $request->input('company_id');

        abort_unless($user->isSuperAdmin() || $user->isCompanyUser(), 403, 'Not permitted.');
        if ($user->isCompanyUser() && $user->company_id !== $companyId) {
            return response()->json(['message' => 'Unauthorized company.'], 403);
        }

        $activeWorkerIds = WorkerAssignment::where('company_id', $companyId)
            ->where('status', WorkerAssignment::STATUS_ACTIVE)
            ->where('start_date', '<=', today())
            ->where('end_date', '>=', today())
            ->pluck('worker_id');

        $workers = Worker::with('vendor')
            ->whereIn('id', $activeWorkerIds)
            ->whereNotNull('fingerprint_template')
            ->where('status', Worker::STATUS_ACTIVE)
            ->get();

        if ($workers->isEmpty()) {
            return response()->json(['message' => 'No enrolled workers deployed today.'], 404);
        }

        $best = ['worker' => null, 'score' => 0];

        if ($this->simulationEnabled()) {
            // Demo only: no real finger, so synthesise an identification.
            $worker = $workers->random();
            $best = ['worker' => $worker, 'score' => 150 + random_int(0, 49)];
        } else {
            $probe = $request->input('probe_template');
            foreach ($workers as $worker) {
                $result = $this->biometric->matchTemplates($probe, decrypt($worker->fingerprint_template));
                if (($result['score'] ?? 0) > $best['score']) {
                    $best = ['worker' => $worker, 'score' => $result['score']];
                }
            }
        }

        if (! $best['worker'] || $best['score'] < $this->biometric->threshold()) {
            return response()->json(['message' => 'No fingerprint match found.'], 404);
        }

        $worker       = $best['worker'];
        $gateLocation = ($user->isGateUser() && $user->location_name)
            ? $user->location_name
            : AttendanceLog::DEFAULT_LOCATION_NAME;

        $lastLog = AttendanceLog::where('worker_id', $worker->id)
            ->where('company_id', $companyId)
            ->where('location_name', $gateLocation)
            ->today()->valid()
            ->orderByDesc('marked_at')
            ->first();

        return response()->json([
            'worker_id'    => $worker->id,
            'name'         => $worker->name,
            'photo_url'    => $worker->photo_url,
            'vendor'       => $worker->vendor?->name,
            'pending_type' => ($lastLog?->type === AttendanceLog::TYPE_IN) ? 'OUT' : 'IN',
            'score'        => $best['score'],
        ]);
    }

    // ─── Mark attendance ──────────────────────────────────────────────────────

    public function mark(Request $request): JsonResponse
    {
        $data = $request->validate([
            'worker_id'         => 'required|integer|exists:workers,id',
            'company_id'        => 'required|integer|exists:companies,id',
            'assignment_id'     => 'nullable|integer|exists:worker_assignments,id',
            'type'              => 'required|in:IN,OUT',
            'method'            => 'required|in:fingerprint,photo,manual,id_card',
            'fingerprint_score' => 'nullable|integer|min:0|max:200',
            'probe_template'    => 'nullable|string',
            'override_reason'   => 'nullable|string',
            'gate'              => 'nullable|string',
            'device_id'         => 'nullable|string',
            'location_type'     => 'nullable|in:main_gate,department,checkpoint',
            'location_name'     => 'nullable|string|max:100',
            'parent_id'         => 'nullable|integer|exists:attendance_logs,id',
        ]);

        $user = $request->user();

        // Only super-admins and company-side users may mark attendance.
        abort_unless($user->isSuperAdmin() || $user->isCompanyUser(), 403, 'Not permitted to mark attendance.');

        // Company users may only mark for their own company.
        if ($user->isCompanyUser() && (int) $data['company_id'] !== (int) $user->company_id) {
            return response()->json(['message' => 'Unauthorized company.'], 403);
        }

        // The worker must be actively deployed at this company today.
        $deployed = WorkerAssignment::where('worker_id', $data['worker_id'])
            ->where('company_id', $data['company_id'])
            ->where('status', WorkerAssignment::STATUS_ACTIVE)
            ->where('start_date', '<=', today())
            ->where('end_date', '>=', today())
            ->exists();
        if (! $deployed) {
            return response()->json(['message' => 'Worker is not deployed at this company today.'], 422);
        }

        // For fingerprint marks, the score is established SERVER-SIDE — never
        // trust a client-asserted score. In simulation we accept without a real
        // match; otherwise we re-verify the probe against the worker's template.
        $serverScore = null;
        if ($data['method'] === 'fingerprint') {
            if ($this->simulationEnabled()) {
                $serverScore = 150 + random_int(0, 49);
            } else {
                $worker = Worker::findOrFail($data['worker_id']);
                abort_unless($worker->fingerprint_template, 422, 'Worker has no enrolled fingerprint.');
                abort_unless(! empty($data['probe_template']), 422, 'Fingerprint probe required.');
                $result = $this->biometric->matchTemplates($data['probe_template'], decrypt($worker->fingerprint_template));
                if (! ($result['matched'] ?? false)) {
                    return response()->json(['message' => 'Fingerprint did not match this worker.'], 422);
                }
                $serverScore = $result['score'];
            }
        }

        // Gate users always stamp their configured location — ignore frontend values
        if ($user->isGateUser() && $user->location_name) {
            $data['location_type'] = $user->location_type ?? AttendanceLog::LOCATION_MAIN_GATE;
            $data['location_name'] = $user->location_name;
        }

        $locationName = $data['location_name'] ?? AttendanceLog::DEFAULT_LOCATION_NAME;

        $error = $this->validateAttendanceMark($data['worker_id'], $data['company_id'], $data['type'], $locationName);
        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        // Save proof photo if provided (multipart form upload)
        $authProofPath = null;
        if ($request->hasFile('photo')) {
            $request->validate(['photo' => 'image|max:5120|mimes:jpeg,png,jpg']);
            $authProofPath = $request->file('photo')
                ->store('attendance/photos/' . today()->format('Y/m/d'), 'private');
        }

        $log = AttendanceLog::create([
            'parent_id'         => $data['parent_id'] ?? null,
            'worker_id'         => $data['worker_id'],
            'company_id'        => $data['company_id'],
            'assignment_id'     => $data['assignment_id'] ?? null,
            'type'              => $data['type'],
            'marked_at'         => now(),
            'marked_by'         => $user->id,
            'method'            => $data['method'],
            'fingerprint_score' => $serverScore,
            'auth_proof_path'   => $authProofPath,
            'override_reason'   => $data['override_reason'] ?? null,
            'gate'              => $data['gate'] ?? null,
            'device_id'         => $data['device_id'] ?? null,
            'location_type'     => $data['location_type'] ?? AttendanceLog::LOCATION_MAIN_GATE,
            'location_name'     => $locationName,
            'ip_address'        => $request->ip(),
            'is_valid'          => true,
        ]);

        $this->lockActiveDeployment($data['worker_id'], $data['company_id']);

        $this->audit->log($user->id, 'attendance_marked', AttendanceLog::class, $log->id, [
            'worker_id'     => $data['worker_id'],
            'type'          => $data['type'],
            'method'        => $data['method'],
            'location_name' => $locationName,
        ]);

        return response()->json([
            'message' => "Attendance {$data['type']} marked at {$locationName}.",
            'log'     => $log->load('worker:id,name'),
        ], 201);
    }

    // ─── Serve proof photo ────────────────────────────────────────────────────

    public function proofPhoto(Request $request, AttendanceLog $log)
    {
        abort_unless($log->auth_proof_path, 404);
        $user = $request->user();
        abort_unless(
            $user->isSuperAdmin()
            || ($user->isCompanyUser() && $user->company_id === $log->company_id)
            || ($user->isVendorUser() && $log->worker?->vendor_id === $user->vendor_id),
            403
        );

        return Storage::disk('private')->response($log->auth_proof_path);
    }

    // ─── Today's attendance ───────────────────────────────────────────────────

    public function today(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = AttendanceLog::with(['worker:id,name,photo_path', 'markedBy:id,name'])
            ->today()
            ->when($user->isCompanyUser(), fn($q) => $q->where('company_id', $user->company_id))
            ->orderByDesc('marked_at');

        return response()->json($query->get());
    }

    // ─── Worker history ───────────────────────────────────────────────────────

    public function workerHistory(Request $request, Worker $worker): JsonResponse
    {
        $user = $request->user();
        $this->assertWorkerVisible($user, $worker);

        $logs = AttendanceLog::with(['company:id,name', 'markedBy:id,name'])
            ->where('worker_id', $worker->id)
            ->when($user->isCompanyUser(), fn($q) => $q->where('company_id', $user->company_id))
            ->when($request->from, fn($q, $d) => $q->whereDate('marked_at', '>=', $d))
            ->when($request->to,   fn($q, $d) => $q->whereDate('marked_at', '<=', $d))
            ->orderByDesc('marked_at')
            ->paginate(30);

        return response()->json($logs);
    }

    // ─── Exceptions ───────────────────────────────────────────────────────────

    public function exceptions(Request $request): JsonResponse
    {
        $user = $request->user();
        $date = $request->date ?? today()->toDateString();

        $missingOut = AttendanceLog::select('worker_id', 'company_id', 'location_name')
            ->where('type', AttendanceLog::TYPE_IN)
            ->where('is_valid', true)
            ->whereDate('marked_at', $date)
            ->when($user->isCompanyUser(), fn($q) => $q->where('company_id', $user->company_id))
            ->whereNotExists(function ($query) use ($date) {
                $query->from('attendance_logs as out_log')
                    ->whereColumn('out_log.worker_id', 'attendance_logs.worker_id')
                    ->whereColumn('out_log.company_id', 'attendance_logs.company_id')
                    ->whereColumn('out_log.location_name', 'attendance_logs.location_name')
                    ->where('out_log.type', AttendanceLog::TYPE_OUT)
                    ->where('out_log.is_valid', true)
                    ->whereDate('out_log.marked_at', $date);
            })
            ->with(['worker:id,name', 'company:id,name'])
            ->get();

        return response()->json([
            'date'        => $date,
            'missing_out' => $missingOut,
            'total'       => $missingOut->count(),
        ]);
    }

    // ─── Report ───────────────────────────────────────────────────────────────

    public function report(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'from'       => 'required|date',
            'to'         => 'required|date|after_or_equal:from',
            'company_id' => 'nullable|integer',
            'worker_id'  => 'nullable|integer',
        ]);

        $query = AttendanceLog::with(['worker:id,name', 'company:id,name'])
            ->whereDate('marked_at', '>=', $request->from)
            ->whereDate('marked_at', '<=', $request->to)
            ->where('is_valid', true)
            ->when($user->isCompanyUser(), fn($q) => $q->where('company_id', $user->company_id))
            ->when($user->isVendorUser(), fn($q) =>
                $q->whereHas('worker', fn($wq) => $wq->where('vendor_id', $user->vendor_id)))
            ->when($request->company_id && $user->isSuperAdmin(), fn($q) => $q->where('company_id', $request->company_id))
            ->when($request->worker_id, fn($q) => $q->where('worker_id', $request->worker_id))
            ->orderBy('marked_at');

        return response()->json($query->paginate(100));
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function validateAttendanceMark(int $workerId, int $companyId, string $type, string $locationName): ?string
    {
        $lastLog = AttendanceLog::where('worker_id', $workerId)
            ->where('company_id', $companyId)
            ->where('location_name', $locationName)
            ->today()
            ->valid()
            ->orderByDesc('marked_at')
            ->first();

        if ($type === AttendanceLog::TYPE_IN && $lastLog?->type === AttendanceLog::TYPE_IN) {
            return "Worker already marked IN at '{$locationName}'. Mark OUT first.";
        }

        if ($type === AttendanceLog::TYPE_OUT && (! $lastLog || $lastLog->type === AttendanceLog::TYPE_OUT)) {
            return "Cannot mark OUT at '{$locationName}' — no prior IN recorded today.";
        }

        return null;
    }

    /** Ensure the actor may see this worker (tenant scoping). Aborts 403 otherwise. */
    private function assertWorkerVisible(User $user, Worker $worker): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }
        if ($user->isVendorUser()) {
            abort_unless($worker->vendor_id === $user->vendor_id, 403, 'Access denied.');
            return;
        }
        if ($user->isCompanyUser()) {
            $related = AttendanceLog::where('worker_id', $worker->id)->where('company_id', $user->company_id)->exists()
                || WorkerAssignment::where('worker_id', $worker->id)->where('company_id', $user->company_id)->exists();
            abort_unless($related, 403, 'Worker not associated with your company.');
            return;
        }
        abort(403, 'Access denied.');
    }

    private function lockActiveDeployment(int $workerId, int $companyId): void
    {
        WorkerAssignment::where('worker_id', $workerId)
            ->where('company_id', $companyId)
            ->where('status', WorkerAssignment::STATUS_ACTIVE)
            ->where('start_date', '<=', today())
            ->where('end_date', '>=', today())
            ->where('is_locked', false)
            ->update(['is_locked' => true]);
    }
}
