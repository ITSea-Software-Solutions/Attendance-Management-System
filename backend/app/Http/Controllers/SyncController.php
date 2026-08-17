<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Services\AadhaarService;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Offline-first client app sync (see CLIENT_APP_DESIGN.md §4).
 *
 *  GET  /api/sync/pull  — role-scoped bundle (workers, assignments, attendance)
 *  POST /api/sync/push  — idempotent batch upload (registrations + marks),
 *                         keyed by client-generated UUIDs.
 *
 * Conflict model: master data = server wins (client refreshes on pull);
 * events (marks, registrations) = append-only, server re-validates.
 */
class SyncController extends Controller
{
    public function __construct(private AuditService $audit)
    {
    }

    // ─────────────────────────────────────────────────────────────────────────
    public function pull(Request $request): JsonResponse
    {
        $user  = $request->user();
        $today = today()->toDateString();

        if ($user->isVendorUser()) {
            $workers = Worker::where('vendor_id', $user->vendor_id)->get();
            $assignments = WorkerAssignment::with('company:id,name')
                ->whereIn('worker_id', $workers->pluck('id'))
                ->where('status', 'active')
                ->get();
            $attendance = collect();
        } else {
            // company_admin / company_gate / super_admin
            $assignQ = WorkerAssignment::with('company:id,name')
                ->where('status', 'active')
                ->whereDate('end_date', '>=', today()->subDays(7));
            if (! $user->isSuperAdmin()) {
                $assignQ->where('company_id', $user->company_id);
            }
            $assignments = $assignQ->get();
            $workers = Worker::whereIn('id', $assignments->pluck('worker_id'))->get();

            $attQ = AttendanceLog::with('worker:id,name')
                ->where('marked_at', '>=', now()->subDays(7));
            if (! $user->isSuperAdmin()) {
                $attQ->where('company_id', $user->company_id);
            }
            $attendance = $attQ->orderByDesc('marked_at')->limit(500)->get();
        }

        // Marking-capable devices (gate/company/super) need the enrolled
        // templates locally for OFFLINE 1:N matching at the gate — the exact
        // mirror of the web gate's /attendance/worker-templates. Decrypted
        // here just like there; vendors don't mark, so they don't get them.
        $withTemplates = ! $user->isVendorUser();

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'workers'     => $workers->map(fn ($w) => [
                'id'                    => $w->id,
                'client_uuid'           => $w->client_uuid,
                'name'                  => $w->name,
                'aadhaar_number_masked' => $w->aadhaar_number_masked,
                'dob'                   => $w->dob?->toDateString(),
                'gender'                => $w->gender,
                'phone'                 => $w->phone,
                'status'                => $w->status,
                'vendor_id'             => $w->vendor_id,
                'updated_at'            => $w->updated_at?->toIso8601String(),
                'fingerprint_template'  => $withTemplates && $w->fingerprint_template
                    ? (function () use ($w) {
                        try { return decrypt($w->fingerprint_template); } catch (\Throwable) { return null; }
                    })()
                    : null,
            ]),
            'assignments' => $assignments->map(fn ($a) => [
                'id'           => $a->id,
                'worker_id'    => $a->worker_id,
                'company_id'   => $a->company_id,
                'company_name' => $a->company?->name,
                'start_date'   => $a->start_date?->toDateString(),
                'end_date'     => $a->end_date?->toDateString(),
                'status'       => $a->status,
            ]),
            'attendance'  => $attendance->map(fn ($m) => [
                'id'                => $m->id,
                'client_uuid'       => $m->client_uuid,
                'worker_id'         => $m->worker_id,
                'worker_name'       => $m->worker?->name,
                'company_id'        => $m->company_id,
                'type'              => $m->type,
                'marked_at'         => $m->marked_at?->toIso8601String(),
                'method'            => $m->method,
                'fingerprint_score' => $m->fingerprint_score,
                'location_type'     => $m->location_type,
                'location_name'     => $m->location_name,
            ]),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    public function push(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'device_id'                      => 'required|string|max:64',
            'registrations'                  => 'array',
            'registrations.*.uuid'           => 'required|uuid',
            'registrations.*.name'           => 'required|string|max:120',
            'registrations.*.aadhaar_number' => 'nullable|string',
            'registrations.*.dob'            => 'nullable|date',
            'registrations.*.gender'         => 'nullable|in:M,F,O',
            'registrations.*.phone'          => 'nullable|string|max:20',
            'registrations.*.consent'        => 'nullable|boolean',
            'registrations.*.fingerprint_template' => 'nullable|string',
            'registrations.*.fingerprint_quality'  => 'nullable|integer|min:0|max:100',
            'marks'                          => 'array',
            'marks.*.uuid'                   => 'required|uuid',
            'marks.*.worker_id'              => 'nullable|integer',
            'marks.*.worker_uuid'            => 'nullable|string',
            'marks.*.type'                   => 'required|in:IN,OUT',
            'marks.*.marked_at'              => 'required|date',
            'marks.*.method'                 => 'nullable|in:fingerprint,manual',
            'marks.*.score'                  => 'nullable|integer|min:0|max:200',
            'marks.*.simulated'              => 'nullable|boolean',
        ]);

        $regResults  = [];
        $markResults = [];

        // ── Registrations (vendor roles + super admin) ────────────────────────
        foreach ($data['registrations'] ?? [] as $reg) {
            $regResults[] = $this->pushRegistration($user, $reg, $data['device_id']);
        }

        // ── Attendance marks (gate / company admin / super admin) ─────────────
        foreach ($data['marks'] ?? [] as $mark) {
            $markResults[] = $this->pushMark($user, $mark, $data['device_id']);
        }

        return response()->json([
            'server_time'   => now()->toIso8601String(),
            'registrations' => $regResults,
            'marks'         => $markResults,
        ]);
    }

    private function pushRegistration($user, array $reg, string $deviceId): array
    {
        $uuid = $reg['uuid'];

        $existing = Worker::withTrashed()->where('client_uuid', $uuid)->first();
        if ($existing) {
            return [
                'uuid'                  => $uuid,
                'status'                => 'duplicate_uuid',
                'server_id'             => $existing->id,
                'aadhaar_number_masked' => $existing->aadhaar_number_masked,
            ];
        }

        if (! $user->isVendorUser() && ! $user->isSuperAdmin()) {
            return ['uuid' => $uuid, 'status' => 'error', 'message' => 'This role cannot register workers.'];
        }
        $vendorId = $user->isVendorUser() ? $user->vendor_id : null;
        if (! $vendorId) {
            return ['uuid' => $uuid, 'status' => 'error', 'message' => 'No vendor for this account.'];
        }

        if ($deny = \App\Services\PlanService::deny(\App\Services\PlanService::ctx('vendor', $vendorId), 'workers')) {
            return ['uuid' => $uuid, 'status' => 'error', 'message' => $deny['message']];
        }

        if (empty($reg['consent'])) {
            return ['uuid' => $uuid, 'status' => 'error', 'message' => 'Worker consent confirmation is required — update the app and re-register.'];
        }

        $num = preg_replace('/\D+/', '', (string) ($reg['aadhaar_number'] ?? ''));
        if (strlen($num) !== 12) {
            return ['uuid' => $uuid, 'status' => 'error', 'message' => 'Aadhaar is mandatory (12 digits).'];
        }
        $hash   = AadhaarService::hashNumber($num);
        $masked = 'XXXX-XXXX-' . substr($num, -4);

        if (Worker::withTrashed()->where('aadhaar_hash', $hash)->exists()) {
            return ['uuid' => $uuid, 'status' => 'error', 'message' => 'A worker with this Aadhaar number is already registered.'];
        }

        $worker = new Worker([
            'vendor_id' => $vendorId,
            'name'      => $reg['name'],
            'dob'       => $reg['dob'] ?? null,
            'gender'    => $reg['gender'] ?? null,
            'phone'     => $reg['phone'] ?? null,
        ]);
        $hasFp = ! empty($reg['fingerprint_template']);
        $worker->forceFill([
            'client_uuid'           => $uuid,
            'aadhaar_number_masked' => $masked,
            'aadhaar_hash'          => $hash,
            'consent_confirmed_at'  => now(),
            // Fingerprint captured on-device (real via SGIBIOSRV on Windows,
            // SIM elsewhere) — encrypted at rest exactly like web enrollment.
            'fingerprint_template'    => $hasFp ? encrypt($reg['fingerprint_template']) : null,
            'fingerprint_quality'     => $hasFp ? ($reg['fingerprint_quality'] ?? null) : null,
            'fingerprint_enrolled_at' => $hasFp ? now() : null,
            'status'                => $hasFp ? Worker::STATUS_ACTIVE : Worker::STATUS_PENDING,
            'registered_by'         => $user->id,
        ])->save();

        $this->audit->log($user->id, 'worker_created_via_sync', Worker::class, $worker->id, [
            'device_id' => $deviceId,
        ]);

        return [
            'uuid'                  => $uuid,
            'status'                => 'created',
            'server_id'             => $worker->id,
            'aadhaar_number_masked' => $masked,
        ];
    }

    private function pushMark($user, array $mark, string $deviceId): array
    {
        $uuid = $mark['uuid'];

        $existing = AttendanceLog::where('client_uuid', $uuid)->first();
        if ($existing) {
            return ['uuid' => $uuid, 'status' => 'duplicate_uuid', 'server_id' => $existing->id];
        }

        if (! in_array($user->role, ['company_gate', 'company_admin', 'super_admin'], true)) {
            return ['uuid' => $uuid, 'status' => 'error', 'message' => 'This role cannot mark attendance.'];
        }

        // Resolve worker by server id or by the client uuid it was created under.
        $worker = null;
        if (! empty($mark['worker_id'])) {
            $worker = Worker::find($mark['worker_id']);
        }
        if (! $worker && ! empty($mark['worker_uuid'])) {
            $worker = Worker::where('client_uuid', $mark['worker_uuid'])->first();
        }
        if (! $worker) {
            return ['uuid' => $uuid, 'status' => 'error', 'message' => 'Worker not found on server (sync registrations first).'];
        }

        $markedAt = Carbon::parse($mark['marked_at']);

        // Deployment check on the DAY the mark happened (late-synced allowed).
        $assignment = WorkerAssignment::where('worker_id', $worker->id)
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $markedAt->toDateString())
            ->whereDate('end_date', '>=', $markedAt->toDateString())
            ->when(! $user->isSuperAdmin(), fn ($q) => $q->where('company_id', $user->company_id))
            ->first();

        $companyId = $assignment?->company_id ?? $user->company_id;
        if (! $companyId) {
            return ['uuid' => $uuid, 'status' => 'error', 'message' => 'No company context for this mark.'];
        }

        $log = new AttendanceLog([
            'worker_id'         => $worker->id,
            'company_id'        => $companyId,
            'assignment_id'     => $assignment?->id,
            'type'              => $mark['type'],
            'marked_at'         => $markedAt,
            'marked_by'         => $user->id,
            'method'            => $mark['method'] ?? 'fingerprint',
            'fingerprint_score' => $mark['score'] ?? null,
            'device_id'         => $deviceId,
            // Gate users may not have a location configured — column is NOT NULL
            'location_type'     => $user->location_type ?? 'main_gate',
            'location_name'     => $user->location_name ?? 'Main Gate',
            'ip_address'        => request()->ip(),
            'is_valid'          => $assignment !== null,
            'invalidation_reason' => $assignment ? null : 'No active deployment at mark time (synced from device)',
        ]);
        $log->forceFill(['client_uuid' => $uuid])->save();

        if ($assignment && ! $assignment->is_locked) {
            $assignment->forceFill(['is_locked' => true])->save();
        }

        return ['uuid' => $uuid, 'status' => 'created', 'server_id' => $log->id];
    }
}
