<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class WorkerController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $user       = $request->user();
        $deployment = $request->deployment; // current | previous | (empty = all)

        $query = Worker::with(['vendor:id,name', 'idDocuments'])
            ->when($user->isVendorUser(), fn($q) => $q->where('vendor_id', $user->vendor_id))
            ->when($user->isCompanyUser(), function ($q) use ($user, $deployment) {
                if ($deployment === 'previous') {
                    // Workers with any attendance at this company, but no active deployment today
                    $q->whereHas('attendanceLogs', fn($lq) => $lq->where('company_id', $user->company_id))
                      ->whereDoesntHave('assignments', fn($aq) =>
                          $aq->where('company_id', $user->company_id)
                             ->where('status', 'active')
                             ->where('start_date', '<=', today())
                             ->where('end_date', '>=', today())
                      );
                } else {
                    // Default / current: active deployments covering today
                    $ids = \DB::table('worker_assignments')
                        ->where('company_id', $user->company_id)
                        ->where('status', 'active')
                        ->where('start_date', '<=', today())
                        ->where('end_date', '>=', today())
                        ->pluck('worker_id');
                    $q->whereIn('id', $ids);
                }
            })
            ->when(!$user->isCompanyUser(), function ($q) use ($deployment) {
                // For super_admin / vendor users
                if ($deployment === 'current') {
                    $q->whereHas('assignments', fn($q2) =>
                        $q2->where('status', 'active')
                           ->where('start_date', '<=', today())
                           ->where('end_date', '>=', today())
                    );
                } elseif ($deployment === 'previous') {
                    $q->whereHas('assignments')
                      ->whereDoesntHave('assignments', fn($q2) =>
                          $q2->where('status', 'active')
                             ->where('start_date', '<=', today())
                             ->where('end_date', '>=', today())
                      );
                }
            })
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->search, fn($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderByDesc('created_at');

        return response()->json($query->paginate(20));
    }

    public function stats(Request $request, Worker $worker): JsonResponse
    {
        $user      = $request->user();
        $companyId = $user->isCompanyUser() ? $user->company_id : null;

        // Authorization
        if ($user->isVendorUser() && $worker->vendor_id !== $user->vendor_id) {
            abort(403, 'Access denied.');
        }
        if ($user->isCompanyUser()) {
            $related = AttendanceLog::where('worker_id', $worker->id)->where('company_id', $companyId)->exists()
                || WorkerAssignment::where('worker_id', $worker->id)->where('company_id', $companyId)->exists();
            abort_unless($related, 403, 'Worker not associated with your company.');
        }

        // Non-company users can optionally scope to a specific company
        if (!$user->isCompanyUser() && $request->company_id) {
            $companyId = (int) $request->company_id;
        }

        $worker->load(['vendor:id,name']);

        $base = AttendanceLog::where('worker_id', $worker->id)
            ->when($companyId, fn($q) => $q->where('company_id', $companyId));

        $totalIn   = (clone $base)->where('type', 'IN')->count();
        $totalOut  = (clone $base)->where('type', 'OUT')->count();
        $totalDays = (clone $base)->selectRaw('COUNT(DISTINCT DATE(marked_at)) as cnt')->value('cnt') ?? 0;
        $locations = (clone $base)->whereNotNull('location_name')->distinct()->pluck('location_name');

        // Monthly breakdown — last 6 months
        $sixMonthsAgo = now()->subMonths(6)->startOfMonth();

        $monthlyRaw = (clone $base)
            ->selectRaw("DATE_FORMAT(marked_at, '%Y-%m') as month, type, COUNT(*) as cnt")
            ->where('marked_at', '>=', $sixMonthsAgo)
            ->groupBy('month', 'type')
            ->orderByDesc('month')
            ->get();

        $daysPerMonth = (clone $base)
            ->selectRaw("DATE_FORMAT(marked_at, '%Y-%m') as month, COUNT(DISTINCT DATE(marked_at)) as days")
            ->where('marked_at', '>=', $sixMonthsAgo)
            ->groupBy('month')
            ->pluck('days', 'month');

        $monthly = $monthlyRaw->groupBy('month')->map(fn($rows, $month) => [
            'month'     => $month,
            'days'      => $daysPerMonth[$month] ?? 0,
            'in_count'  => $rows->where('type', 'IN')->sum('cnt'),
            'out_count' => $rows->where('type', 'OUT')->sum('cnt'),
        ])->values();

        // Deployments
        $deployments = WorkerAssignment::with(['company:id,name'])
            ->where('worker_id', $worker->id)
            ->when($companyId, fn($q) => $q->where('company_id', $companyId))
            ->orderByDesc('start_date')
            ->get();

        // Recent 30 logs
        $recentLogs = (clone $base)
            ->with(['company:id,name'])
            ->orderByDesc('marked_at')
            ->limit(30)
            ->get();

        return response()->json([
            'worker'      => $worker,
            'summary'     => [
                'total_in'   => $totalIn,
                'total_out'  => $totalOut,
                'total_days' => $totalDays,
                'locations'  => $locations,
            ],
            'monthly'     => $monthly,
            'deployments' => $deployments,
            'recent_logs' => $recentLogs,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'                   => 'required|string|max:120',
            'dob'                    => 'nullable|date|before:today',
            'gender'                 => 'nullable|in:M,F,O',
            'address'                => 'nullable|string',
            'city'                   => 'nullable|string|max:100',
            'state'                  => 'nullable|string|max:100',
            'pin'                    => 'nullable|string|size:6',
            'phone'                  => 'nullable|string|max:15',
            'mobile'                 => 'nullable|string|max:15',
            // Aadhaar is MANDATORY — via one of two paths:
            //   extract path: masked + hash returned by /aadhaar/extract
            //   manual path : full 12-digit number (hashed & masked here, then discarded)
            'aadhaar_number'         => 'nullable|string|regex:/^\d{12}$/',
            'aadhaar_number_masked'  => 'nullable|string|max:20',
            'aadhaar_hash'           => 'nullable|string|size:64',
            'aadhaar_data_extracted' => 'nullable|array',
            'notes'                  => 'nullable|string',
            'vendor_id'              => [
                Rule::requiredIf(! $user->isVendorUser()),
                'nullable',
                'integer',
                'exists:vendors,id',
            ],
        ], [
            'aadhaar_number.regex' => 'Aadhaar number must be exactly 12 digits.',
        ]);

        if ($user->isVendorUser()) {
            $data['vendor_id'] = $user->vendor_id;
        }

        if (empty($data['vendor_id'])) {
            return response()->json(['message' => 'vendor_id is required.'], 422);
        }

        // ── SaaS plan limit: workers per vendor org ───────────────────────────
        if ($deny = \App\Services\PlanService::deny(\App\Services\PlanService::ctx('vendor', $data['vendor_id']), 'workers')) {
            return response()->json($deny, 403);
        }

        // ── Aadhaar mandatory + dedup ─────────────────────────────────────────
        $resolved = $this->resolveAadhaar($data);
        if ($resolved instanceof JsonResponse) {
            return $resolved; // validation / duplicate error
        }
        [$aadhaarMasked, $aadhaarHash] = $resolved;
        unset($data['aadhaar_number'], $data['aadhaar_hash']); // never persist the raw number; hash set via forceFill
        $data['aadhaar_number_masked'] = $aadhaarMasked;

        $worker = new Worker($data);
        $worker->forceFill([
            'aadhaar_hash'  => $aadhaarHash,
            'status'        => Worker::STATUS_PENDING,
            'registered_by' => $user->id,
        ])->save();

        $this->audit->log($user->id, 'worker_created', Worker::class, $worker->id, [
            'worker_name' => $worker->name,
        ]);

        return response()->json($worker->load('vendor'), 201);
    }

    /**
     * Resolve the mandatory Aadhaar input into [masked, hash].
     * Accepts either the extract-path pair (masked + hash) or a manual full
     * 12-digit number (hashed + masked here; the raw number is discarded).
     * Returns a 422 JsonResponse when missing or already registered.
     */
    private function resolveAadhaar(array $data, ?int $ignoreWorkerId = null): array|JsonResponse
    {
        if (! empty($data['aadhaar_number'])) {
            $num    = preg_replace('/\D+/', '', $data['aadhaar_number']);
            $masked = 'XXXX-XXXX-' . substr($num, -4);
            $hash   = \App\Services\AadhaarService::hashNumber($num);
        } elseif (! empty($data['aadhaar_number_masked']) && ! empty($data['aadhaar_hash'])) {
            $masked = $data['aadhaar_number_masked'];
            $hash   = $data['aadhaar_hash'];
        } else {
            return response()->json([
                'message' => 'Aadhaar is mandatory.',
                'errors'  => ['aadhaar_number' => ['Aadhaar is mandatory — upload the Aadhaar PDF or enter the 12-digit number.']],
            ], 422);
        }

        $dupe = Worker::withTrashed()
            ->where('aadhaar_hash', $hash)
            ->when($ignoreWorkerId, fn ($q) => $q->where('id', '!=', $ignoreWorkerId))
            ->first();

        if ($dupe) {
            return response()->json([
                'message' => 'Duplicate Aadhaar.',
                'errors'  => ['aadhaar_number' => ['A worker with this Aadhaar number is already registered.']],
            ], 422);
        }

        return [$masked, $hash];
    }

    public function show(Request $request, Worker $worker): JsonResponse
    {
        $this->authorizeWorkerAccess($request->user(), $worker);

        return response()->json($worker->load(['vendor', 'assignments.company', 'idDocuments']));
    }

    public function update(Request $request, Worker $worker): JsonResponse
    {
        $this->authorizeWorkerAccess($request->user(), $worker);

        $data = $request->validate([
            'name'    => 'sometimes|string|max:120',
            'dob'     => 'sometimes|date|before:today',
            'gender'  => 'sometimes|in:M,F,O',
            'address' => 'sometimes|string',
            'city'    => 'nullable|string',
            'state'   => 'nullable|string',
            'pin'     => 'nullable|string|size:6',
            'phone'   => 'nullable|string|max:15',
            'notes'   => 'nullable|string',
            // Optional on edit: add/replace Aadhaar (legacy workers may lack it)
            'aadhaar_number'        => 'nullable|string|regex:/^\d{12}$/',
            'aadhaar_number_masked' => 'nullable|string|max:20',
            'aadhaar_hash'          => 'nullable|string|size:64',
        ], [
            'aadhaar_number.regex' => 'Aadhaar number must be exactly 12 digits.',
        ]);

        // If any Aadhaar input was supplied, resolve + dedup (ignoring this worker)
        if (! empty($data['aadhaar_number']) || (! empty($data['aadhaar_number_masked']) && ! empty($data['aadhaar_hash']))) {
            $resolved = $this->resolveAadhaar($data, $worker->id);
            if ($resolved instanceof JsonResponse) {
                return $resolved;
            }
            [$masked, $hash] = $resolved;
            $worker->forceFill(['aadhaar_hash' => $hash]);
            $data['aadhaar_number_masked'] = $masked;
        }
        unset($data['aadhaar_number'], $data['aadhaar_hash']);

        $worker->fill($data)->save();
        $this->audit->log($request->user()->id, 'worker_updated', Worker::class, $worker->id);

        return response()->json($worker->fresh());
    }

    public function destroy(Request $request, Worker $worker): JsonResponse
    {
        $this->authorizeWorkerAccess($request->user(), $worker);

        $worker->delete();
        $this->audit->log($request->user()->id, 'worker_deleted', Worker::class, $worker->id);

        return response()->json(['message' => 'Worker deleted.']);
    }

    // ─── Fingerprint Enrollment ───────────────────────────────────────────────

    public function storeFingerprint(Request $request, Worker $worker): JsonResponse
    {
        $this->authorizeWorkerAccess($request->user(), $worker);

        $data = $request->validate([
            'template' => 'required|string',
            'quality'  => 'required|integer|min:0|max:100',
        ]);

        $worker->forceFill([
            'fingerprint_template'    => encrypt($data['template']),
            'fingerprint_quality'     => $data['quality'],
            'fingerprint_enrolled_at' => now(),
            'status'                  => Worker::STATUS_ACTIVE, // active once fingerprint is enrolled
        ])->save();

        $this->audit->log($request->user()->id, 'fingerprint_enrolled', Worker::class, $worker->id, [
            'quality' => $data['quality'],
        ]);

        return response()->json([
            'message'                 => 'Fingerprint enrolled successfully.',
            'status'                  => Worker::STATUS_ACTIVE,
            'fingerprint_quality'     => $data['quality'],
            'fingerprint_enrolled_at' => now(),
        ]);
    }

    public function deleteFingerprint(Request $request, Worker $worker): JsonResponse
    {
        $this->authorizeWorkerAccess($request->user(), $worker);

        $worker->forceFill([
            'fingerprint_template'    => null,
            'fingerprint_quality'     => null,
            'fingerprint_enrolled_at' => null,
            'status'                  => Worker::STATUS_PENDING,
        ])->save();

        $this->audit->log($request->user()->id, 'fingerprint_deleted', Worker::class, $worker->id);

        return response()->json(['message' => 'Fingerprint removed.']);
    }

    // ─── Photo Serve ──────────────────────────────────────────────────────────

    public function servePhoto(Request $request, Worker $worker)
    {
        abort_unless($worker->photo_path, 404);

        $user = $request->user();
        if ($user->isVendorUser() && $worker->vendor_id !== $user->vendor_id) {
            abort(403);
        }

        return Storage::disk('private')->response($worker->photo_path);
    }

    // ─── Photo Upload ─────────────────────────────────────────────────────────

    public function uploadPhoto(Request $request, Worker $worker): JsonResponse
    {
        $this->authorizeWorkerAccess($request->user(), $worker);

        $request->validate(['photo' => 'required|image|max:2048|mimes:jpeg,png,jpg']);

        if ($worker->photo_path) {
            Storage::disk('private')->delete($worker->photo_path);
        }

        $path = $request->file('photo')->store('workers/photos', 'private');
        $worker->forceFill(['photo_path' => $path])->save();

        return response()->json(['message' => 'Photo uploaded.', 'photo_path' => $path]);
    }

    public function activate(Request $request, Worker $worker): JsonResponse
    {
        $worker->forceFill(['status' => Worker::STATUS_ACTIVE])->save();
        $this->audit->log($request->user()->id, 'worker_activated', Worker::class, $worker->id);

        return response()->json(['message' => 'Worker activated.']);
    }

    public function deactivate(Request $request, Worker $worker): JsonResponse
    {
        $worker->forceFill(['status' => Worker::STATUS_INACTIVE])->save();
        $this->audit->log($request->user()->id, 'worker_deactivated', Worker::class, $worker->id);

        return response()->json(['message' => 'Worker deactivated.']);
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function authorizeWorkerAccess(User $user, Worker $worker): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }

        if ($user->isVendorUser() && $worker->vendor_id !== $user->vendor_id) {
            abort(403, 'Access denied to this worker.');
        }

        if ($user->isCompanyUser()) {
            $hasActiveDeployment = $worker->assignments()
                ->where('company_id', $user->company_id)
                ->where('status', 'active')
                ->where('start_date', '<=', today())
                ->where('end_date', '>=', today())
                ->exists();
            if (! $hasActiveDeployment) {
                abort(403, 'Worker not deployed to your company today.');
            }
        }
    }
}
