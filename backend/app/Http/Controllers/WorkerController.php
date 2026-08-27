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
            ->when($request->search, fn($q, $s) => $q->where(fn($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('emp_code', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")))
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
            ->when($request->aadhaar === 'unverified', fn($q) => $q->whereNull('aadhaar_verified_at'))
            ->when($request->aadhaar === 'verified',   fn($q) => $q->whereNotNull('aadhaar_verified_at'))
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
            'emp_code'               => 'nullable|string|max:30',
            'pan_number'             => ['nullable', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'joining_date'           => 'nullable|date',
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
            fputcsv($out, ['Name', 'Emp code', 'Aadhaar (masked)', 'Aadhaar verified', 'PAN', 'Joining date', 'DOB', 'Gender', 'Phone', 'Email', 'Address', 'Vendor', 'Status', 'Fingerprint', 'Face', 'Email verified', 'Phone verified']);
            foreach ($rows as $w) {
                fputcsv($out, [
                    $w->name, $w->emp_code, $w->aadhaar_number_masked,
                    $w->aadhaar_verified_at ? 'yes' : 'NO — pending',
                    $w->pan_number,
                    optional($w->joining_date)->format('Y-m-d'),
                    optional($w->dob)->format('Y-m-d'), $w->gender, $w->phone, $w->email,
                    $w->address,
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
        // Workers BELONG to vendors — vendors import their own; super admin
        // may import on a vendor's behalf. Companies never own workers
        // (they only receive deployments), so no company role imports.
        abort_unless($user->isVendorUser() || $user->isSuperAdmin(), 403);
        abort_unless(\App\Services\PlanService::userHasFeature($user, 'bulk_import_export'), 403,
            'Bulk import is a Professional/Enterprise feature.');
        $request->validate([
            'file'      => 'required|file|mimes:csv,txt|max:2048',
            'vendor_id' => $user->isVendorUser() ? 'nullable' : 'required|integer|exists:vendors,id',
        ]);
        $vendorId = $user->isVendorUser() ? $user->vendor_id : (int) $request->input('vendor_id');

        $fh = fopen($request->file('file')->getRealPath(), 'r');
        $rawHeader = fgetcsv($fh) ?: [];
        // Flexible headers: trim, lowercase, strip spaces/underscores, and
        // accept the aliases people actually type in Excel.
        $norm = fn ($h) => preg_replace('/[^a-z0-9]/', '', strtolower(trim((string) $h)));
        $aliases = [
            'name'          => ['name', 'workername', 'fullname'],
            'emp_code'      => ['empcode', 'employeecode', 'empno', 'employeeno', 'empid', 'staffcode', 'code'],
            'phone'         => ['phone', 'mobile', 'phoneno', 'mobileno', 'contact'],
            'joining_date'  => ['joiningdate', 'doj', 'dateofjoining', 'joindate'],
            'aadhaar'       => ['aadhaarnumber', 'aadhaarno', 'aadhaar', 'adharno', 'adhar', 'adhaar', 'aadharnumber', 'aadharno', 'aadhar', 'uid'],
            'pan'           => ['pannumber', 'panno', 'pan', 'pancard'],
            'address'       => ['address', 'addr', 'fulladdress'],
            'dob'           => ['dob', 'dateofbirth', 'birthdate'],
            'gender'        => ['gender', 'sex'],
            'email'         => ['email', 'emailid', 'mail'],
        ];
        $cols = [];
        foreach ($rawHeader as $i => $h) {
            $n = $norm($h);
            foreach ($aliases as $field => $alts) {
                if (in_array($n, $alts, true)) {
                    $cols[$field] = $i;
                }
            }
        }
        if (! isset($cols['name'])) {
            return response()->json(['message' => 'CSV needs at least a "name" column. Recognised columns: name, phone, joining_date, aadhaar_number (OPTIONAL — verify later), pan_number, address, dob, gender, email.'], 422);
        }
        $cell = function (array $row, string $field) use ($cols): string {
            return isset($cols[$field]) ? trim((string) ($row[$cols[$field]] ?? '')) : '';
        };
        // dd/mm/yyyy · dd-mm-yyyy · yyyy-mm-dd → Y-m-d (null when unparseable)
        $parseDate = function (string $v): ?string {
            if ($v === '') {
                return null;
            }
            if (preg_match('~^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$~', $v, $m)) {
                return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            }
            if (preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})$~', $v, $m)) {
                return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
            }
            return null;
        };

        $created = 0;
        $imported_unverified = 0;
        $errors = [];
        $line = 1;
        while (($row = fgetcsv($fh)) !== false) {
            $line++;
            $name    = $cell($row, 'name');
            $aadhaar = preg_replace('/\D+/', '', $cell($row, 'aadhaar'));
            if ($name === '' && $aadhaar === '' && $cell($row, 'phone') === '') {
                continue; // blank line
            }
            if ($name === '') {
                $errors[] = "line {$line}: name is required";
                continue;
            }

            // Aadhaar is OPTIONAL on import — when present it must be valid
            // and unique; when absent the worker imports UNVERIFIED and the
            // number is added later (edit / app), which sets the flag.
            $masked = null;
            $hash = null;
            if ($aadhaar !== '') {
                if (strlen($aadhaar) !== 12) {
                    $errors[] = "line {$line}: aadhaar_number must be 12 digits (or leave it empty to verify later)";
                    continue;
                }
                $resolved = $this->resolveAadhaar(['aadhaar_number' => $aadhaar]);
                if ($resolved instanceof JsonResponse) {
                    $errors[] = "line {$line}: duplicate or invalid Aadhaar";
                    continue;
                }
                [$masked, $hash] = $resolved;
            }

            $empCode = strtoupper($cell($row, 'emp_code'));
            if ($empCode !== '' && Worker::withTrashed()->where('vendor_id', $vendorId)->where('emp_code', $empCode)->exists()) {
                $errors[] = "line {$line}: emp_code '{$empCode}' already exists for this vendor";
                continue;
            }

            $pan = strtoupper($cell($row, 'pan'));
            if ($pan !== '' && ! preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) {
                $errors[] = "line {$line}: PAN '{$pan}' is not a valid format (AAAAA9999A) — row skipped";
                continue;
            }

            $g = strtoupper(substr($cell($row, 'gender'), 0, 1));
            $worker = new Worker([
                'vendor_id'    => $vendorId,
                'name'         => $name,
                'emp_code'     => $empCode ?: null,
                'dob'          => $parseDate($cell($row, 'dob')),
                'joining_date' => $parseDate($cell($row, 'joining_date')),
                'gender'       => in_array($g, ['M', 'F', 'O'], true) ? $g : null,
                'phone'        => $cell($row, 'phone') ?: null,
                'email'        => $cell($row, 'email') ?: null,
                'address'      => $cell($row, 'address') ?: null,
                'pan_number'   => $pan ?: null,
                'aadhaar_number_masked' => $masked,
            ]);
            $worker->forceFill([
                'aadhaar_hash'         => $hash,
                'aadhaar_verified_at'  => $hash ? now() : null,
                'consent_confirmed_at' => now(), // importer attests consent for the batch
                'status'               => Worker::STATUS_PENDING,
                'registered_by'        => $user->id,
            ])->save();
            $created++;
            if (! $hash) {
                $imported_unverified++;
            }
        }
        fclose($fh);
        $this->audit->log($user->id, 'workers_imported', Worker::class, null, [
            'created' => $created, 'errors' => count($errors),
        ]);

        return response()->json([
            'message' => "{$created} worker(s) imported"
                .($imported_unverified ? " ({$imported_unverified} without Aadhaar — verify later)" : '')
                .(count($errors) ? ', '.count($errors).' row(s) skipped' : '.'),
            'created'             => $created,
            'without_aadhaar'     => $imported_unverified,
            'errors'              => array_slice($errors, 0, 50),
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
     * OTP phone verification — the real (non-attest) path. Sends a 6-digit
     * code to the worker's phone via the configured SMS provider; without
     * provider credentials the code is returned in the response ONLY in
     * debug mode (same pattern as the dev password-reset link).
     */
    public function sendPhoneOtp(Request $request, Worker $worker): JsonResponse
    {
        $this->authorizeWorkerAccess($request->user(), $worker);
        $phone = $worker->mobile ?: $worker->phone;
        abort_unless($phone, 422, 'Worker has no phone on record.');

        $key = "wotp-send:{$worker->id}";
        abort_if(cache()->get($key, 0) >= 3, 429, 'Too many OTPs sent — try again in 10 minutes.');
        cache()->put($key, cache()->get($key, 0) + 1, now()->addMinutes(10));

        $otp = (string) random_int(100000, 999999);
        cache()->put("wotp:{$worker->id}", hash('sha256', $otp), now()->addMinutes(10));

        $sent = $this->sendSms($phone, "TrueCrew verification code: {$otp}. Valid 10 minutes.");
        $this->audit->log($request->user()->id, 'worker_phone_otp_sent', Worker::class, $worker->id);

        $payload = ['message' => $sent
            ? "OTP sent to {$phone}."
            : 'SMS provider not configured — ask your administrator (or use manual attest).'];
        if (! $sent && config('app.debug')) {
            $payload['dev_otp'] = $otp; // demo mode only — never in production
            $payload['message'] = 'SMS provider not configured — demo OTP included (debug mode).';
        }

        return response()->json($payload, $sent || config('app.debug') ? 200 : 503);
    }

    public function verifyPhoneOtp(Request $request, Worker $worker): JsonResponse
    {
        $this->authorizeWorkerAccess($request->user(), $worker);
        $data = $request->validate(['otp' => 'required|digits:6']);

        $stored = cache()->get("wotp:{$worker->id}");
        abort_unless($stored, 422, 'No OTP pending — send one first (codes expire in 10 minutes).');
        if (! hash_equals($stored, hash('sha256', $data['otp']))) {
            return response()->json(['message' => 'Wrong code — check and try again.'], 422);
        }

        cache()->forget("wotp:{$worker->id}");
        $worker->forceFill(['phone_verified_at' => now()])->save();
        $this->audit->log($request->user()->id, 'worker_phone_otp_verified', Worker::class, $worker->id);

        return response()->json(['message' => 'Phone verified by OTP.', 'worker' => $worker->fresh()]);
    }

    /** Pluggable SMS send (MSG91 flow API); returns false when unconfigured. */
    private function sendSms(string $phone, string $text): bool
    {
        $key = config('services.sms.msg91_key', env('MSG91_AUTHKEY'));
        if (! $key) {
            return false;
        }
        try {
            $msisdn = preg_replace('/\D/', '', $phone);
            if (strlen($msisdn) === 10) {
                $msisdn = '91'.$msisdn;
            }
            \Illuminate\Support\Facades\Http::withHeaders(['authkey' => $key])
                ->timeout(8)
                ->post('https://control.msg91.com/api/v5/flow/', [
                    'template_id' => config('services.sms.msg91_template', env('MSG91_TEMPLATE_ID')),
                    'recipients'  => [['mobiles' => $msisdn, 'otp' => preg_replace('/\D/', '', $text)]],
                ]);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
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
            'emp_code'     => 'nullable|string|max:30',
            'pan_number'   => ['nullable', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'joining_date' => 'nullable|date',
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
            // Adding/confirming the Aadhaar IS the verification moment for
            // workers imported without one.
            $worker->forceFill(['aadhaar_hash' => $hash, 'aadhaar_verified_at' => $worker->aadhaar_verified_at ?? now()]);
            $data['aadhaar_number_masked'] = $masked;
        }
        unset($data['aadhaar_number'], $data['aadhaar_hash']);

        if (! empty($data['emp_code'])) {
            $data['emp_code'] = strtoupper($data['emp_code']);
            $clash = Worker::withTrashed()->where('vendor_id', $worker->vendor_id)
                ->where('emp_code', $data['emp_code'])->where('id', '!=', $worker->id)->exists();
            abort_if($clash, 422, "Employee code {$data['emp_code']} is already used by another worker of this vendor.");
        }

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
            'slot'     => 'nullable|integer|in:1,2', // 2 = backup finger (any enrolled finger verifies at the gate)
        ]);
        $slot = (int) ($data['slot'] ?? 1);

        // Enrollment quality floor: a poor enrollment haunts every future
        // match. SecuGen quality < 30 means smudged/partial — rescan now.
        if ($data['quality'] < 30 && ! config('biometric.simulation')) {
            return response()->json([
                'message' => "Fingerprint image quality too low ({$data['quality']}/100) — clean the sensor and finger, then scan again.",
            ], 422);
        }

        if ($slot === 2) {
            if (empty($worker->fingerprint_template)) {
                return response()->json(['message' => 'Enroll the primary finger first.'], 422);
            }
            $worker->forceFill([
                'fingerprint_template_2' => encrypt($data['template']),
                'fingerprint_quality_2'  => $data['quality'],
            ])->save();
        } else {
            $worker->forceFill([
                'fingerprint_template'    => encrypt($data['template']),
                'fingerprint_quality'     => $data['quality'],
                'fingerprint_enrolled_at' => now(),
                'status'                  => Worker::STATUS_ACTIVE, // active once fingerprint is enrolled
            ])->save();
        }

        $this->audit->log($request->user()->id, 'fingerprint_enrolled', Worker::class, $worker->id, [
            'quality' => $data['quality'],
            'slot'    => $slot,
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

        // slot=2 removes only the backup finger; default deregisters biometrics fully.
        if ((int) $request->input('slot') === 2) {
            $worker->forceFill([
                'fingerprint_template_2' => null,
                'fingerprint_quality_2'  => null,
            ])->save();
            $this->audit->log($request->user()->id, 'fingerprint_deleted', Worker::class, $worker->id, ['slot' => 2]);

            return response()->json(['message' => 'Backup fingerprint removed.']);
        }

        $worker->forceFill([
            'fingerprint_template'    => null,
            'fingerprint_quality'     => null,
            'fingerprint_template_2'  => null,
            'fingerprint_quality_2'   => null,
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
