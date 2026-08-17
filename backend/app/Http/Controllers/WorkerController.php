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
            // Vendor filter (company/super users narrowing a mixed list)
            ->when($request->vendor_id, fn($q, $v) => $q->where('vendor_id', $v))
            // "Inside now": the worker's LATEST event is an IN with no OUT yet
            // (date-agnostic so night shifts crossing midnight stay visible —
            // same semantics as the Exceptions page).
            ->when($request->boolean('inside'), function ($q) use ($user) {
                $q->whereRaw("(
                    SELECT al.type FROM attendance_logs al
                    WHERE al.worker_id = workers.id"
                    .($user->isCompanyUser() ? ' AND al.company_id = '.((int) $user->company_id) : '')."
                    ORDER BY al.marked_at DESC LIMIT 1
                ) = 'IN'");
            })
            // Deployment-state filter: undeployed | expiring (≤3 days) | deployed
            ->when($request->deploy_state === 'undeployed', fn($q) =>
                $q->whereDoesntHave('assignments', fn($a) => $a
                    ->where('status', 'active')->where('approval_status', 'approved')
                    ->where('end_date', '>=', today())))
            ->when($request->deploy_state === 'deployed', fn($q) =>
                $q->whereHas('assignments', fn($a) => $a
                    ->where('status', 'active')->where('approval_status', 'approved')
                    ->where('start_date', '<=', today())->where('end_date', '>=', today())))
            ->when($request->deploy_state === 'expiring', fn($q) =>
                $q->whereHas('assignments', fn($a) => $a
                    ->where('status', 'active')->where('approval_status', 'approved')
                    ->whereBetween('end_date', [today(), today()->addDays(3)])))
            // "Present today": any attendance event today
            ->when($request->boolean('present_today'), function ($q) use ($user) {
                $q->whereHas('attendanceLogs', fn($lq) => $lq
                    ->whereDate('marked_at', today())
                    ->when($user->isCompanyUser(), fn($c) => $c->where('company_id', $user->company_id)));
            })
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
            // DPDP: registering org must confirm the worker's informed consent
            'consent'                => 'accepted',
            'vendor_id'              => [
                Rule::requiredIf(! $user->isVendorUser()),
                'nullable',
                'integer',
                'exists:vendors,id',
            ],
        ], [
            'aadhaar_number.regex' => 'Aadhaar number must be exactly 12 digits.',
            'consent.accepted'     => 'Please confirm the worker has given informed consent for identity and biometric processing.',
        ]);
        unset($data['consent']);

        if ($user->isVendorUser()) {
            $data['vendor_id'] = $user->vendor_id;
        }

        if (empty($data['vendor_id'])) {
            return response()->json(['message' => 'vendor_id is required.'], 422);
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
            'aadhaar_hash'         => $aadhaarHash,
            'consent_confirmed_at' => now(),
            'status'               => Worker::STATUS_PENDING,
            'registered_by'        => $user->id,
        ])->save();

        $this->audit->log($user->id, 'worker_created', Worker::class, $worker->id, [
            'worker_name' => $worker->name,
        ]);

        // Notification center: tell the vendor org's admins (except the actor).
        $admins = User::where('vendor_id', $worker->vendor_id)
            ->where('role', 'vendor_admin')->where('id', '!=', $user->id)->get();
        app(\App\Services\NotifyService::class)->inApp($admins, 'worker_registered',
            "Worker registered: {$worker->name}",
            "{$worker->aadhaar_number_masked} · status {$worker->status}",
            ['worker_id' => $worker->id]);

        return response()->json($worker->load('vendor'), 201);
    }

    /**
     * CSV export of the caller-visible workers (bulk_import_export feature).
     */
    public function export(Request $request)
    {
        $user = $request->user();
        abort_unless(\App\Services\PlanService::userHasFeature($user, 'bulk_import_export'), 403,
            'Bulk export is a Professional/Enterprise feature.');
        $q = Worker::with('vendor');
        if ($user->isVendorUser()) {
            $q->where('vendor_id', $user->vendor_id);
        } elseif ($user->isCompanyUser()) {
            $q->whereHas('assignments', fn ($a) => $a->where('company_id', $user->company_id));
        }
        $rows = $q->orderBy('name')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel-friendly BOM
            fputcsv($out, ['Name', 'Aadhaar (masked)', 'DOB', 'Gender', 'Phone', 'Email', 'Vendor', 'Status', 'Fingerprint', 'Face', 'Email verified', 'Phone verified']);
            foreach ($rows as $w) {
                fputcsv($out, [
                    $w->name, $w->aadhaar_number_masked,
                    optional($w->dob)->format('Y-m-d'), $w->gender, $w->phone, $w->email,
                    $w->vendor?->name, $w->status,
                    $w->fingerprint_enrolled_at ? 'yes' : 'no',
                    $w->face_enrolled_at ? 'yes' : 'no',
                    $w->email_verified_at ? 'yes' : 'no',
                    $w->phone_verified_at ? 'yes' : 'no',
                ]);
            }
            fclose($out);
        }, 'truecrew-workers-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * CSV bulk import (vendor users). Columns: name, aadhaar_number(12),
     * dob(YYYY-MM-DD), gender(M/F/O), phone, email — header row required.
     * Workers land as PENDING (biometrics still happen in person); every row
     * is validated and reported individually — one bad row never kills the file.
     */
    public function import(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isVendorUser() || $user->isSuperAdmin(), 403);
        abort_unless(\App\Services\PlanService::userHasFeature($user, 'bulk_import_export'), 403,
            'Bulk import is a Professional/Enterprise feature.');
        $request->validate([
            'file'      => 'required|file|mimes:csv,txt|max:2048',
            'vendor_id' => $user->isSuperAdmin() ? 'required|integer|exists:vendors,id' : 'nullable',
        ]);
        $vendorId = $user->isVendorUser() ? $user->vendor_id : (int) $request->input('vendor_id');

        $fh = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), fgetcsv($fh) ?: []);
        $idx = fn (string $col) => array_search($col, $header, true);
        if ($idx('name') === false || $idx('aadhaar_number') === false) {
            return response()->json(['message' => 'CSV must have header columns: name, aadhaar_number (plus optional dob, gender, phone, email).'], 422);
        }

        $created = 0;
        $errors = [];
        $line = 1;
        while (($row = fgetcsv($fh)) !== false) {
            $line++;
            $name = trim((string) ($row[$idx('name')] ?? ''));
            $aadhaar = preg_replace('/\D+/', '', (string) ($row[$idx('aadhaar_number')] ?? ''));
            if ($name === '' && $aadhaar === '') {
                continue; // blank line
            }
            if ($name === '' || strlen($aadhaar) !== 12) {
                $errors[] = "line {$line}: name and a 12-digit aadhaar_number are required";
                continue;
            }
            $resolved = $this->resolveAadhaar(['aadhaar_number' => $aadhaar]);
            if ($resolved instanceof JsonResponse) {
                $errors[] = "line {$line}: duplicate or invalid Aadhaar";
                continue;
            }
            [$masked, $hash] = $resolved;
            $g = strtoupper(substr(trim((string) ($idx('gender') !== false ? ($row[$idx('gender')] ?? '') : '')), 0, 1));
            $worker = new Worker([
                'vendor_id' => $vendorId,
                'name'      => $name,
                'dob'       => $idx('dob') !== false ? (trim((string) ($row[$idx('dob')] ?? '')) ?: null) : null,
                'gender'    => in_array($g, ['M', 'F', 'O'], true) ? $g : null,
                'phone'     => $idx('phone') !== false ? (trim((string) ($row[$idx('phone')] ?? '')) ?: null) : null,
                'email'     => $idx('email') !== false ? (trim((string) ($row[$idx('email')] ?? '')) ?: null) : null,
                'aadhaar_number_masked' => $masked,
            ]);
            $worker->forceFill([
                'aadhaar_hash'         => $hash,
                'consent_confirmed_at' => now(), // importer attests consent for the batch
                'status'               => Worker::STATUS_PENDING,
                'registered_by'        => $user->id,
            ])->save();
            $created++;
        }
        fclose($fh);
        $this->audit->log($user->id, 'workers_imported', Worker::class, null, [
            'created' => $created, 'errors' => count($errors),
        ]);

        return response()->json([
            'message' => "{$created} worker(s) imported".(count($errors) ? ', '.count($errors).' row(s) skipped' : '.'),
            'created' => $created,
            'errors'  => array_slice($errors, 0, 50),
        ]);
    }

    /**
     * Store the photo EXTRACTED from the Aadhaar PDF (uploaded by the app
     * after sync). Kept separately from the live registration photo so gate
     * screens can show document-vs-live side by side.
     */
    public function uploadAadhaarPhoto(Request $request, Worker $worker): JsonResponse
    {
        $this->authorizeWorkerAccess($request->user(), $worker);
        $request->validate(['photo' => 'required|image|max:2048|mimes:jpeg,png,jpg']);
        if ($worker->aadhaar_photo_path) {
            Storage::disk('private')->delete($worker->aadhaar_photo_path);
        }
        $path = $request->file('photo')->store('workers/aadhaar_photos', 'private');
        $worker->forceFill(['aadhaar_photo_path' => $path])->save();

        return response()->json(['message' => 'Aadhaar photo stored.']);
    }

    /** Serve the Aadhaar-document photo (authenticated, role-scoped). */
    public function serveAadhaarPhoto(Request $request, Worker $worker)
    {
        $this->authorizeWorkerAccess($request->user(), $worker);
        abort_unless($worker->aadhaar_photo_path
            && Storage::disk('private')->exists($worker->aadhaar_photo_path), 404);

        return Storage::disk('private')->response($worker->aadhaar_photo_path);
    }

    /**
     * Manual verification steps (until OTP providers are wired): mark the
     * worker's email/phone as verified by the vendor. Aadhaar/fingerprint/
     * face steps are set by their own flows.
     */
    public function verifyStep(Request $request, Worker $worker): JsonResponse
    {
        $this->authorizeWorkerAccess($request->user(), $worker);
        $data = $request->validate(['step' => 'required|in:email,phone']);
        abort_if($data['step'] === 'email' && ! $worker->email, 422, 'Worker has no email on record.');
        abort_if($data['step'] === 'phone' && ! ($worker->phone || $worker->mobile), 422, 'Worker has no phone on record.');

        $worker->forceFill([$data['step'].'_verified_at' => now()])->save();
        $this->audit->log($request->user()->id, "worker_{$data['step']}_verified", Worker::class, $worker->id);

        return response()->json(['message' => ucfirst($data['step']).' marked verified.', 'worker' => $worker->fresh()]);
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

        // Test environments may disable dedup (AADHAAR_DEDUP=false) to allow
        // the same Aadhaar on multiple demo workers. ALWAYS on in production.
        $dupe = config('biometric.aadhaar_dedup', true)
            ? Worker::withTrashed()
                ->where('aadhaar_hash', $hash)
                ->when($ignoreWorkerId, fn ($q) => $q->where('id', '!=', $ignoreWorkerId))
                ->first()
            : null;

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
        $user = $request->user();
        // Deleting a worker is the OWNING VENDOR's call (or super admin) —
        // companies interact through deployments, not the worker record.
        abort_unless($user->isSuperAdmin()
            || ($user->isVendorUser() && $worker->vendor_id === $user->vendor_id), 403);

        $lastLog = AttendanceLog::where('worker_id', $worker->id)
            ->latest('marked_at')->first();
        if ($lastLog && $lastLog->type === AttendanceLog::TYPE_IN) {
            return response()->json([
                'message' => 'Worker is currently checked IN — the company must mark them OUT before deletion.',
            ], 422);
        }

        // Once a worker is engaged with a company, the vendor can't pull them
        // out unilaterally — cancel the deployment first. Super admin bypasses.
        if ($blocked = $this->vendorEngagementBlock($user, $worker, 'delete them')) {
            return $blocked;
        }

        WorkerAssignment::where('worker_id', $worker->id)
            ->where('status', WorkerAssignment::STATUS_ACTIVE)
            ->update(['status' => WorkerAssignment::STATUS_CANCELLED]);

        $worker->delete(); // soft delete — attendance history is preserved
        $this->audit->log($user->id, 'worker_deleted', Worker::class, $worker->id, [
            'worker_name' => $worker->name,
        ]);

        return response()->json(['message' => 'Worker deleted. Attendance history is preserved; plan usage counts only workers who actually worked.']);
    }

    // ─── Fingerprint Enrollment ───────────────────────────────────────────────

    public function storeFingerprint(Request $request, Worker $worker): JsonResponse
    {
        $this->authorizeWorkerAccess($request->user(), $worker);

        $data = $request->validate([
            'template' => 'required|string',
            'quality'  => 'required|integer|min:0|max:100',
        ]);

        // Enrollment quality floor: a poor enrollment haunts every future
        // match. SecuGen quality < 30 means smudged/partial — rescan now.
        if ($data['quality'] < 30 && ! config('biometric.simulation')) {
            return response()->json([
                'message' => "Fingerprint image quality too low ({$data['quality']}/100) — clean the sensor and finger, then scan again.",
            ], 422);
        }

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
        $user = $request->user();
        $this->authorizeWorkerAccess($user, $worker);
        // The fingerprint belongs to the vendor's registration — companies
        // interact through deployments, never the biometric record.
        abort_if($user->isCompanyUser(), 403, 'Only the owning vendor can remove a fingerprint.');

        if ($blocked = $this->vendorEngagementBlock($user, $worker, 'remove the fingerprint')) {
            return $blocked;
        }

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

        // Best-effort face enrollment from the same photo (camera-based
        // attendance needs no extra hardware). Failure is non-fatal — the
        // photo is stored either way and fingerprint flows are unaffected.
        $faceEnrolled = false;
        try {
            $embedding = app(\App\Services\FaceService::class)
                ->embed(file_get_contents($request->file('photo')->getRealPath()));
            if ($embedding) {
                $worker->forceFill([
                    'face_descriptor'  => $embedding,
                    'face_enrolled_at' => now(),
                ])->save();
                $faceEnrolled = true;
                $this->audit->log($request->user()->id, 'face_enrolled', Worker::class, $worker->id);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'message'       => $faceEnrolled
                ? 'Photo uploaded — face enrolled for camera attendance.'
                : 'Photo uploaded. (No clear face detected — camera attendance not enabled for this worker.)',
            'photo_path'    => $path,
            'face_enrolled' => $faceEnrolled,
        ]);
    }

    public function activate(Request $request, Worker $worker): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['super_admin', 'company_admin', 'vendor_admin']), 403);
        $this->authorizeWorkerAccess($user, $worker);

        $worker->forceFill(['status' => Worker::STATUS_ACTIVE])->save();
        $this->audit->log($user->id, 'worker_activated', Worker::class, $worker->id);

        return response()->json(['message' => 'Worker activated.']);
    }

    public function deactivate(Request $request, Worker $worker): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['super_admin', 'company_admin', 'vendor_admin']), 403);
        $this->authorizeWorkerAccess($user, $worker);

        if ($blocked = $this->vendorEngagementBlock($user, $worker, 'deactivate them')) {
            return $blocked;
        }

        $worker->forceFill(['status' => Worker::STATUS_INACTIVE])->save();
        $this->audit->log($user->id, 'worker_deactivated', Worker::class, $worker->id);

        return response()->json(['message' => 'Worker deactivated.']);
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    /**
     * Business rule: once a worker is engaged with a company — an active,
     * approved deployment that hasn't ended, or currently checked IN — the
     * VENDOR cannot delete, deactivate, or deregister them. The company relies
     * on that worker for attendance; the vendor must cancel the deployment
     * (and the company mark them OUT) first. Returns a 422 response to send,
     * or null when the action may proceed.
     */
    private function vendorEngagementBlock(User $user, Worker $worker, string $action): ?JsonResponse
    {
        if (! $user->isVendorUser()) {
            return null;
        }

        $dep = $worker->assignments()
            ->with('company:id,name')
            ->where('status', WorkerAssignment::STATUS_ACTIVE)
            ->where('approval_status', 'approved')
            ->where('end_date', '>=', today())
            ->orderByDesc('end_date')
            ->first();
        if ($dep) {
            return response()->json([
                'message' => "{$worker->name} is deployed to ".(optional($dep->company)->name ?? 'a company')
                    .' till '.$dep->end_date?->format('d M Y')
                    ." — cancel the deployment first, then {$action}.",
            ], 422);
        }

        $lastLog = AttendanceLog::where('worker_id', $worker->id)->latest('marked_at')->first();
        if ($lastLog && $lastLog->type === AttendanceLog::TYPE_IN) {
            return response()->json([
                'message' => "{$worker->name} is currently checked IN — the company must mark them OUT before you {$action}.",
            ], 422);
        }

        return null;
    }

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
