<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use Carbon\Carbon;
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
        private \App\Services\FaceService $face,
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
        // Gate/department logins are scoped to THEIR location only.
        $gateLoc   = ($user->isGateUser() && $user->location_name) ? $user->location_name : null;

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
                GROUP_CONCAT(DISTINCT al.location_name SEPARATOR ', ')          as locations,
                (w.photo_path IS NOT NULL)                                      as has_photo,
                (w.aadhaar_photo_path IS NOT NULL)                              as has_aadhaar_photo,
                SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN al.type='IN'  AND al.auth_proof_path IS NOT NULL THEN al.id END ORDER BY al.marked_at ASC), ',', 1)  as in_proof_id,
                SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN al.type='OUT' AND al.auth_proof_path IS NOT NULL THEN al.id END ORDER BY al.marked_at DESC), ',', 1) as out_proof_id,
                GROUP_CONCAT(DISTINCT al.method SEPARATOR ', ')                 as methods,
                MAX(al.fingerprint_score)                                       as best_fp_score,
                MAX(al.face_score)                                              as best_face_score,
                MIN(al.proof_face_match)                                        as proof_face_min,
                MAX(al.proof_face_match)                                        as proof_face_max,
                MAX(al.proof_face_score)                                        as best_proof_face_score
            ")
            ->when($companyId,               fn($q) => $q->where('al.company_id', $companyId))
            ->when($gateLoc,                 fn($q, $l) => $q->where('al.location_name', $l))
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

    /**
     * Month export as CSV (Excel-ready). ?month=YYYY-MM&type=daily|monthly
     * Role-scoped exactly like the on-screen lists (company / vendor / gate).
     */
    public function export(Request $request)
    {
        $request->validate([
            'month' => 'required|date_format:Y-m',
            'type'  => 'nullable|in:daily,monthly',
        ]);
        $type = $request->input('type', 'daily');
        $rows = $this->monthRows($request);

        $filename = "truecrew-attendance-{$request->month}-{$type}.csv";
        return response()->streamDownload(function () use ($rows, $type) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel
            if ($type === 'daily') {
                fputcsv($out, ['Date', 'Worker', 'Vendor', 'Company', 'Location(s)', 'First IN', 'Last OUT', 'Hours', 'Status']);
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r->work_date, $r->worker_name, $r->vendor_name, $r->company_name,
                        $r->locations, $r->first_in, $r->last_out,
                        $this->hoursBetween($r->first_in, $r->last_out),
                        $r->last_out ? 'Done' : 'Missing OUT',
                    ]);
                }
            } else {
                fputcsv($out, ['Worker', 'Vendor', 'Company', 'Days Present', 'Total Hours', 'Days Missing OUT']);
                foreach ($this->monthlyTotals($rows) as $t) {
                    fputcsv($out, [$t['worker'], $t['vendor'], $t['company'], $t['days'], $t['hours'], $t['missing']]);
                }
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Print-friendly monthly report (open in a tab → browser "Save as PDF"). */
    public function printable(Request $request)
    {
        $request->validate(['month' => 'required|date_format:Y-m']);
        $rows   = $this->monthRows($request);
        $totals = $this->monthlyTotals($rows);
        $month  = $request->month;
        $org    = $request->user()->isVendorUser()
            ? optional(\App\Models\Vendor::find($request->user()->vendor_id))->name
            : optional(\App\Models\Company::find($request->user()->company_id))->name;

        $body = '';
        foreach ($totals as $t) {
            $body .= '<tr><td>'.e($t['worker']).'</td><td>'.e($t['vendor']).'</td><td>'.e($t['company'])
                   .'</td><td class="n">'.$t['days'].'</td><td class="n">'.$t['hours'].'</td><td class="n">'.$t['missing'].'</td></tr>';
        }
        $daily = '';
        foreach ($rows as $r) {
            $daily .= '<tr><td>'.e($r->work_date).'</td><td>'.e($r->worker_name).'</td><td>'.e($r->locations)
                    .'</td><td>'.e($r->first_in ? substr($r->first_in, 11, 5) : '—').'</td><td>'.e($r->last_out ? substr($r->last_out, 11, 5) : '—')
                    .'</td><td class="n">'.$this->hoursBetween($r->first_in, $r->last_out).'</td></tr>';
        }
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>TrueCrew — Attendance '.$month.'</title>'
              .'<style>body{font:13px/1.5 system-ui;margin:24px;color:#1D2833}h1{font-size:19px;margin:0}h2{font-size:14px;margin:24px 0 6px}'
              .'table{border-collapse:collapse;width:100%;font-size:12px}th,td{border:1px solid #cbd5d1;padding:4px 7px;text-align:left}'
              .'th{background:#e3efec}.n{text-align:right}.muted{color:#5A6470;font-size:12px}@media print{button{display:none}}</style></head><body>'
              .'<button onclick="window.print()" style="float:right;padding:6px 14px">Print / Save as PDF</button>'
              .'<h1>TrueCrew — Attendance Report</h1>'
              .'<p class="muted">'.e($org ?? 'All organisations').' · Month: '.$month.' · Generated: '.now()->format('d M Y H:i').'</p>'
              .'<h2>Monthly totals (muster)</h2><table><tr><th>Worker</th><th>Vendor</th><th>Company</th><th>Days</th><th>Hours</th><th>Missing OUT</th></tr>'.$body.'</table>'
              .'<h2>Daily detail</h2><table><tr><th>Date</th><th>Worker</th><th>Location</th><th>IN</th><th>OUT</th><th>Hours</th></tr>'.$daily.'</table>'
              .'</body></html>';
        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Shared month query — dailySummary's scoping without pagination. */
    private function monthRows(Request $request)
    {
        $user      = $request->user();
        $companyId = $user->isCompanyUser() ? $user->company_id : null;
        $gateLoc   = ($user->isGateUser() && $user->location_name) ? $user->location_name : null;
        [$y, $m]   = explode('-', $request->month);

        return \DB::table('attendance_logs as al')
            ->join('workers as w', 'w.id', '=', 'al.worker_id')
            ->leftJoin('vendors as v', 'v.id', '=', 'w.vendor_id')
            ->leftJoin('companies as c', 'c.id', '=', 'al.company_id')
            ->selectRaw("
                w.name as worker_name, v.name as vendor_name, c.name as company_name,
                DATE(al.marked_at) as work_date,
                MIN(CASE WHEN al.type='IN'  THEN al.marked_at END) as first_in,
                MAX(CASE WHEN al.type='OUT' THEN al.marked_at END) as last_out,
                GROUP_CONCAT(DISTINCT al.location_name SEPARATOR ', ') as locations")
            ->whereYear('al.marked_at', $y)->whereMonth('al.marked_at', $m)
            ->where('al.is_valid', true)
            ->when($companyId, fn($q) => $q->where('al.company_id', $companyId))
            ->when($gateLoc, fn($q, $l) => $q->where('al.location_name', $l))
            ->when($user->isVendorUser(), fn($q) => $q->where('w.vendor_id', $user->vendor_id))
            ->groupBy('al.worker_id', 'al.company_id', \DB::raw('DATE(al.marked_at)'), 'w.name', 'v.name', 'c.name')
            ->orderBy('w.name')->orderBy('work_date')
            ->get();
    }

    private function hoursBetween(?string $in, ?string $out): string
    {
        if (! $in || ! $out) return '';
        $mins = Carbon::parse($out)->diffInMinutes(Carbon::parse($in), true);
        return sprintf('%d:%02d', intdiv((int) $mins, 60), ((int) $mins) % 60);
    }

    private function monthlyTotals($rows): array
    {
        $agg = [];
        foreach ($rows as $r) {
            $k = $r->worker_name.'|'.$r->company_name;
            $agg[$k] ??= ['worker' => $r->worker_name, 'vendor' => $r->vendor_name, 'company' => $r->company_name,
                          'days' => 0, 'mins' => 0, 'missing' => 0];
            $agg[$k]['days']++;
            if ($r->first_in && $r->last_out) {
                $agg[$k]['mins'] += Carbon::parse($r->last_out)->diffInMinutes(Carbon::parse($r->first_in), true);
            } else {
                $agg[$k]['missing']++;
            }
        }
        return array_map(fn($t) => [
            'worker' => $t['worker'], 'vendor' => $t['vendor'], 'company' => $t['company'],
            'days' => $t['days'],
            'hours' => sprintf('%d:%02d', intdiv((int) $t['mins'], 60), ((int) $t['mins']) % 60),
            'missing' => $t['missing'],
        ], array_values($agg));
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
            ->when($user->isGateUser() && $user->location_name,
                fn($q) => $q->where('location_name', $user->location_name))
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
            ->where('approval_status', 'approved')
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
            ->where('approval_status', 'approved')
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

    /**
     * Camera-based 1:N identification (hardware-free): the gate submits a
     * photo; we embed it and cosine-match against enrolled face descriptors
     * of workers deployed to this company today. Same scoping as identify().
     */
    public function identifyFace(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
            'photo'      => 'required|image|max:5120|mimes:jpeg,png,jpg',
        ]);

        $user      = $request->user();
        $companyId = (int) $request->input('company_id');

        abort_unless($user->isSuperAdmin() || $user->isCompanyUser(), 403, 'Not permitted.');
        if ($user->isCompanyUser() && $user->company_id !== $companyId) {
            return response()->json(['message' => 'Unauthorized company.'], 403);
        }

        $activeWorkerIds = WorkerAssignment::where('company_id', $companyId)
            ->where('status', WorkerAssignment::STATUS_ACTIVE)
            ->where('approval_status', 'approved')
            ->where('start_date', '<=', today())
            ->where('end_date', '>=', today())
            ->pluck('worker_id');

        $workers = Worker::with('vendor')
            ->whereIn('id', $activeWorkerIds)
            ->whereNotNull('face_descriptor')
            ->where('status', Worker::STATUS_ACTIVE)
            ->get();

        if ($workers->isEmpty()) {
            return response()->json(['message' => 'No face-enrolled workers deployed today. Upload a worker photo (registration step 4) to enable camera attendance.'], 404);
        }

        $probe = $this->face->embed(file_get_contents($request->file('photo')->getRealPath()));
        if (! $probe) {
            return response()->json(['message' => 'No face detected in the photo — try again with the worker facing the camera.'], 422);
        }
        if ($this->face->spoofSuspected()) {
            return response()->json(['message' => 'Liveness check failed — present the actual person to the camera, not a photo or screen.'], 422);
        }

        $best   = ['worker' => null, 'score' => 0.0];
        $second = 0.0;
        foreach ($workers as $worker) {
            $score = \App\Services\FaceService::cosine($probe, $worker->face_descriptor);
            if ($score > $best['score']) {
                $second = $best['score'];
                $best   = ['worker' => $worker, 'score' => $score];
            } elseif ($score > $second) {
                $second = $score;
            }
        }

        if (! $best['worker'] || $best['score'] < $this->face->threshold()) {
            return response()->json(['message' => 'No face match found among deployed workers.'], 404);
        }
        // 1:N ambiguity margin (siblings / similar faces): when the runner-up
        // is nearly as close as the winner, don't guess — use fingerprint.
        if ($second > 0 && ($best['score'] - $second) < (float) config('biometric.face_margin', 0.08)) {
            return response()->json(['message' => 'Two workers look too similar for a reliable face match — use fingerprint for this worker.'], 409);
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

        $assignment = WorkerAssignment::where('worker_id', $worker->id)
            ->where('company_id', $companyId)
            ->where('status', WorkerAssignment::STATUS_ACTIVE)
            ->where('approval_status', 'approved')
            ->where('start_date', '<=', today())
            ->where('end_date', '>=', today())
            ->first();

        return response()->json([
            'worker_id'     => $worker->id,
            'name'          => $worker->name,
            'vendor'        => $worker->vendor?->name,
            'photo_url'     => $worker->photo_url,
            'assignment_id' => $assignment?->id,
            'pending_type'  => ($lastLog && $lastLog->type === 'IN') ? 'OUT' : 'IN',
            'face_score'    => round($best['score'], 3),
        ]);
    }

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
            ->where('approval_status', 'approved')
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

        $best   = ['worker' => null, 'score' => 0];
        $second = 0;

        if ($this->simulationEnabled()) {
            // Demo only: no real finger, so synthesise an identification.
            $worker = $workers->random();
            $best = ['worker' => $worker, 'score' => 150 + random_int(0, 49)];
        } else {
            // No fake fallback: refuse when no real matcher binary is
            // configured — the gate APPS match on-device (SGFPM) instead.
            if (! $this->biometric->matcherAvailable()) {
                return response()->json([
                    'message' => 'Server-side fingerprint matching is not configured on this server. '
                        .'Use the TrueCrew gate app (matches on the scanner device, works offline), '
                        .'or install the SecuGen matcher binary for the web gate.',
                ], 501);
            }
            $probe = $request->input('probe_template');
            foreach ($workers as $worker) {
                // Any enrolled finger identifies the worker: score the probe
                // against both templates, keep the worker's best. One score
                // per WORKER, so a worker's own two fingers never trip the
                // cross-worker ambiguity margin below.
                $score = 0;
                foreach ([$worker->fingerprint_template, $worker->fingerprint_template_2] as $enc) {
                    if (empty($enc)) {
                        continue;
                    }
                    try {
                        $result = $this->biometric->matchTemplates($probe, decrypt($enc));
                        $score  = max($score, (int) ($result['score'] ?? 0));
                    } catch (\Throwable) {
                        // undecryptable template — skip this finger
                    }
                }
                if ($score > $best['score']) {
                    $second = $best['score'];
                    $best   = ['worker' => $worker, 'score' => $score];
                } elseif ($score > $second) {
                    $second = $score;
                }
            }
        }

        if (! $best['worker'] || $best['score'] < $this->biometric->threshold()) {
            return response()->json(['message' => 'No fingerprint match found.'], 404);
        }
        // 1:N ambiguity margin — two different workers scoring close together
        // means the identification can't be trusted; rescan instead.
        if ($second > 0 && ($best['score'] - $second) < (int) config('biometric.margin', 10)) {
            return response()->json(['message' => 'Match ambiguous between two workers — place the finger again.'], 404);
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
            'method'            => 'required|in:fingerprint,face,photo,manual,id_card,device_auth',
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
            ->where('approval_status', 'approved')
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
                // Web-gate path only. The apps match ON-DEVICE with the real
                // SGFPM matcher and sync their score; this branch re-verifies
                // browser-submitted probes and never fakes a comparison.
                if (! $this->biometric->matcherAvailable()) {
                    return response()->json([
                        'message' => 'Server-side fingerprint verification is not configured — use the TrueCrew gate app for fingerprint attendance.',
                    ], 501);
                }
                $worker = Worker::findOrFail($data['worker_id']);
                abort_unless($worker->fingerprint_template, 422, 'Worker has no enrolled fingerprint.');
                abort_unless(! empty($data['probe_template']), 422, 'Fingerprint probe required.');
                // Verify against ANY enrolled finger (primary or backup).
                $matched = false;
                foreach ([$worker->fingerprint_template, $worker->fingerprint_template_2] as $enc) {
                    if (empty($enc)) {
                        continue;
                    }
                    $result = $this->biometric->matchTemplates($data['probe_template'], decrypt($enc));
                    if ($result['matched'] ?? false) {
                        $matched     = true;
                        $serverScore = max((int) ($serverScore ?? 0), (int) $result['score']);
                    }
                }
                if (! $matched) {
                    return response()->json(['message' => 'Fingerprint did not match this worker.'], 422);
                }
            }
        }

        // Face marks are re-verified SERVER-SIDE from the submitted photo (which
        // also serves as the proof image) — a client can never assert a face match.
        $faceScore = null;
        if ($data['method'] === 'face') {
            abort_unless($request->hasFile('photo'), 422, 'Face marks require the captured photo.');
            $worker = Worker::findOrFail($data['worker_id']);
            abort_unless($worker->face_descriptor, 422, 'Worker has no enrolled face.');
            $probe = $this->face->embed(file_get_contents($request->file('photo')->getRealPath()));
            if (! $probe) {
                return response()->json(['message' => 'No face detected in the photo — retake and try again.'], 422);
            }
            if ($this->face->spoofSuspected()) {
                return response()->json(['message' => 'Liveness check failed — present the actual person to the camera, not a photo or screen.'], 422);
            }
            $faceScore = \App\Services\FaceService::cosine($probe, $worker->face_descriptor);
            if ($faceScore < $this->face->threshold()) {
                return response()->json(['message' => 'Face did not match this worker.'], 422);
            }
            $faceScore = round($faceScore, 3);
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
            'face_score'        => $faceScore,
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

        // Cross-check the capture against the enrolled face (advisory).
        // Face marks were ALREADY verified from this exact photo — record
        // that verdict directly; other methods get the async job.
        if ($authProofPath) {
            if ($data['method'] === 'face' && $faceScore !== null) {
                $log->forceFill([
                    'proof_face_score' => $faceScore,
                    'proof_face_match' => true,
                ])->save();
            } else {
                \App\Jobs\VerifyProofPhoto::dispatch($log->id);
            }
        }

        $this->audit->log($user->id, 'attendance_marked', AttendanceLog::class, $log->id, [
            'worker_id'     => $data['worker_id'],
            'type'          => $data['type'],
            'method'        => $data['method'],
            'location_name' => $locationName,
        ]);

        // WhatsApp IN/OUT ping to the vendor contact + the worker's own number.
        // Triple-gated inside NotifyService: provider creds set + Enterprise
        // plan + the vendor toggled it on. Best-effort, never blocks the mark.
        try {
            $worker = \App\Models\Worker::with('vendor')->find($data['worker_id']);
            $company = \App\Models\Company::find($data['company_id']);
            if ($worker && $worker->vendor) {
                $vars = [
                    'worker_name'  => $worker->name,
                    'type'         => $data['type'],
                    'time'         => now()->format('d M H:i'),
                    'company_name' => $company?->name ?? '',
                    'gate'         => $locationName,
                ];
                $notify   = app(\App\Services\NotifyService::class);
                $vSettings = (array) ($worker->vendor->settings ?? []);
                $notify->whatsapp($worker->vendor->contact_phone, 'attendance_inout', $vars,
                    'vendor', $worker->vendor->id, $worker->vendor->plan ?? 'trial', $vSettings);
                $notify->whatsapp($worker->mobile ?: $worker->phone, 'attendance_inout', $vars,
                    'vendor', $worker->vendor->id, $worker->vendor->plan ?? 'trial', $vSettings);
            }
        } catch (\Throwable $e) {
            report($e);
        }

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

    /**
     * Live Board — one glanceable payload: who is inside right now, per
     * gate/department, today's flow, and the latest events. Role-scoped:
     * company users see their company (gate users only their gate), vendors
     * see their own workers, super admin sees everything (?company_id= to
     * focus). "Inside" = the worker's LATEST valid log is IN (date-agnostic,
     * so night shifts past midnight stay visible).
     */
    public function liveBoard(Request $request): JsonResponse
    {
        $user      = $request->user();
        $companyId = $user->isCompanyUser() ? $user->company_id : $request->integer('company_id');
        $gateLoc   = ($user->isGateUser() && $user->location_name) ? $user->location_name : null;

        $scope = fn ($q) => $q
            ->when($companyId, fn ($qq) => $qq->where('al.company_id', $companyId))
            ->when($gateLoc, fn ($qq) => $qq->where('al.location_name', $gateLoc))
            ->when($user->isVendorUser(), fn ($qq) => $qq->whereIn('al.worker_id',
                fn ($s) => $s->select('id')->from('workers')->where('vendor_id', $user->vendor_id)));

        // Latest valid log per worker(+company) → inside when it's an IN
        $latestIds = \DB::table('attendance_logs as al')
            ->selectRaw('MAX(al.id) as id')
            ->where('al.is_valid', true)
            ->tap($scope)
            ->groupBy('al.worker_id', 'al.company_id')
            ->pluck('id');

        $inside = AttendanceLog::with(['worker:id,name,vendor_id,photo_path', 'worker.vendor:id,name', 'company:id,name'])
            ->whereIn('id', $latestIds)
            ->where('type', AttendanceLog::TYPE_IN)
            ->orderByDesc('marked_at')
            ->get()
            ->map(fn ($l) => [
                'worker_id' => $l->worker_id,
                'name'      => optional($l->worker)->name,
                'vendor'    => optional(optional($l->worker)->vendor)->name,
                'company'   => optional($l->company)->name,
                'gate'      => $l->location_name ?: 'Main Gate',
                'in_at'     => $l->marked_at?->toIso8601String(),
                'has_photo' => ! empty(optional($l->worker)->photo_path),
                'method'    => $l->method,
            ]);

        // Gates: known locations (company presets + settings) + any gate seen
        $gateNames = collect();
        if ($companyId && ($company = \App\Models\Company::find($companyId))) {
            $gateNames = collect(config('departments.presets', []))
                ->merge((array) (((array) ($company->settings ?? []))['locations'] ?? []))
                ->unique()->values();
        }
        $gates = $inside->groupBy('gate')->map(fn ($rows, $gate) => [
            'name'    => $gate,
            'count'   => $rows->count(),
            'last_at' => $rows->max('in_at'),
            'workers' => $rows->take(14)->values(),
        ])->values();
        foreach ($gateNames as $g) {
            if ($gateLoc && $g !== $gateLoc) {
                continue;
            }
            if (! $gates->contains(fn ($x) => $x['name'] === $g)) {
                $gates->push(['name' => $g, 'count' => 0, 'last_at' => null, 'workers' => []]);
            }
        }
        $gates = $gates->sortByDesc('count')->values();

        // Today's flow + recent events
        $todayBase = \DB::table('attendance_logs as al')
            ->where('al.is_valid', true)
            ->whereDate('al.marked_at', today())
            ->tap($scope);
        $inToday  = (clone $todayBase)->where('al.type', 'IN')->count();
        $outToday = (clone $todayBase)->where('al.type', 'OUT')->count();
        $hourly   = (clone $todayBase)
            ->selectRaw("HOUR(al.marked_at) as h,
                SUM(CASE WHEN al.type='IN' THEN 1 ELSE 0 END) as ins,
                SUM(CASE WHEN al.type='OUT' THEN 1 ELSE 0 END) as outs")
            ->groupBy('h')->orderBy('h')->get();

        $recent = AttendanceLog::with(['worker:id,name,photo_path'])
            ->where('is_valid', true)
            ->whereDate('marked_at', today())
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($gateLoc, fn ($q) => $q->where('location_name', $gateLoc))
            ->when($user->isVendorUser(), fn ($q) => $q->whereIn('worker_id',
                fn ($s) => $s->select('id')->from('workers')->where('vendor_id', $user->vendor_id)))
            ->orderByDesc('marked_at')->limit(20)->get()
            ->map(fn ($l) => [
                'id'        => $l->id,
                'worker_id' => $l->worker_id,
                'name'      => optional($l->worker)->name,
                'type'      => $l->type,
                'gate'      => $l->location_name ?: 'Main Gate',
                'at'        => $l->marked_at?->toIso8601String(),
                'method'    => $l->method,
                'has_photo' => ! empty(optional($l->worker)->photo_path),
            ]);

        // Expected today: approved active deployments covering today (scoped)
        $expected = WorkerAssignment::where('status', WorkerAssignment::STATUS_ACTIVE)
            ->where('approval_status', 'approved')
            ->where('start_date', '<=', today())->where('end_date', '>=', today())
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($user->isVendorUser(), fn ($q) => $q->where('vendor_id', $user->vendor_id))
            ->distinct('worker_id')->count('worker_id');

        return response()->json([
            'inside_total' => $inside->count(),
            'expected'     => $expected,
            'in_today'     => $inToday,
            'out_today'    => $outToday,
            'gates'        => $gates,
            'recent'       => $recent,
            'hourly'       => $hourly,
            'gate_scope'   => $gateLoc,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function exceptions(Request $request): JsonResponse
    {
        $user = $request->user();
        $date = $request->date ?? today()->toDateString();

        $missingOut = AttendanceLog::select('worker_id', 'company_id', 'location_name')
            ->where('type', AttendanceLog::TYPE_IN)
            ->where('is_valid', true)
            ->whereDate('marked_at', $date)
            ->when($user->isCompanyUser(), fn($q) => $q->where('company_id', $user->company_id))
            ->when($user->isGateUser() && $user->location_name,
                fn($q) => $q->where('location_name', $user->location_name))
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
        // Deployment must be APPROVED, and when the company restricted it to
        // specific gates/departments, this gate must be one of them.
        // (Deliberately NOT filtered by approval here — a pending/rejected
        // deployment must be FOUND so the operator gets the precise reason.)
        $assignment = WorkerAssignment::where('worker_id', $workerId)
            ->where('company_id', $companyId)
            ->where('status', WorkerAssignment::STATUS_ACTIVE)
            ->where('start_date', '<=', today())
            ->where('end_date', '>=', today())
            ->orderByDesc('id')
            ->first();
        if ($assignment) {
            if ($assignment->approval_status === 'pending') {
                return 'This deployment is awaiting the company\'s approval.';
            }
            if ($assignment->approval_status === 'rejected') {
                return 'This deployment was rejected by the company.';
            }
            $allowed = $assignment->allowed_locations;
            if (is_array($allowed) && $allowed !== [] && ! in_array($locationName, $allowed, true)) {
                return "Worker is not permitted at '{$locationName}'. Allowed: ".implode(', ', $allowed).'.';
            }
        }

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
            ->where('approval_status', 'approved')
            ->where('start_date', '<=', today())
            ->where('end_date', '>=', today())
            ->where('is_locked', false)
            ->update(['is_locked' => true]);
    }

    /**
     * Attach the gate-capture proof photo to an already-synced mark (the
     * offline apps push marks as JSON, then upload the photo separately).
     * Only the org that recorded the mark (or super admin) may attach.
     */
    public function uploadProof(Request $request, AttendanceLog $log): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->isSuperAdmin()
            || ($user->isCompanyUser() && $user->company_id === $log->company_id)
            || ($user->isVendorUser() && $log->worker && $log->worker->vendor_id === $user->vendor_id),
            403
        );
        $request->validate(['photo' => 'required|image|max:5120|mimes:jpeg,png,jpg']);
        if ($log->auth_proof_path) {
            \Illuminate\Support\Facades\Storage::disk('private')->delete($log->auth_proof_path);
        }
        $path = $request->file('photo')->store('attendance/proofs', 'private');
        $log->forceFill(['auth_proof_path' => $path])->save();

        // Advisory cross-check: does the gate capture match the ENROLLED face?
        \App\Jobs\VerifyProofPhoto::dispatch($log->id);

        return response()->json(['message' => 'Proof photo attached.']);
    }

    /**
     * Manual OUT — an administrative correction by the COMPANY side
     * (admin/HR), e.g. to close a forgotten OUT before cancelling a
     * deployment. Vendors deliberately cannot do this: attendance truth
     * belongs to the company whose gate it is.
     */
    public function manualOut(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin()
            || in_array($user->role, ['company_admin', 'company_hr'], true), 403,
            'Only the company (admin/HR) may mark a manual OUT.');
        $data = $request->validate([
            'worker_id'  => 'required|integer|exists:workers,id',
            'company_id' => 'nullable|integer|exists:companies,id',
            'note'       => 'nullable|string|max:200',
        ]);
        $companyId = $user->isSuperAdmin()
            ? ($data['company_id'] ?? null)
            : $user->company_id;
        abort_unless($companyId, 422, 'company_id required.');

        $lastIn = AttendanceLog::where('worker_id', $data['worker_id'])
            ->where('company_id', $companyId)
            ->latest('marked_at')
            ->first();
        if (! $lastIn || $lastIn->type !== AttendanceLog::TYPE_IN) {
            return response()->json(['message' => 'Worker is not currently checked IN at your company.'], 422);
        }

        $log = AttendanceLog::create([
            'worker_id'       => $data['worker_id'],
            'company_id'      => $companyId,
            'assignment_id'   => $lastIn->assignment_id,
            'type'            => AttendanceLog::TYPE_OUT,
            'marked_at'       => now(),
            'marked_by'       => $user->id,
            'method'          => 'manual',
            'override_reason' => 'Manual OUT by '.$user->role.($data['note'] ?? null ? ': '.$data['note'] : ''),
            'location_type'   => $lastIn->location_type ?? AttendanceLog::LOCATION_MAIN_GATE,
            'location_name'   => $lastIn->location_name ?? AttendanceLog::DEFAULT_LOCATION_NAME,
            'ip_address'      => $request->ip(),
            'is_valid'        => true,
        ]);
        $this->audit->log($user->id, 'manual_out', AttendanceLog::class, $log->id, [
            'worker_id' => $data['worker_id'],
        ]);

        return response()->json(['message' => 'Manual OUT recorded.', 'log' => $log]);
    }
}
