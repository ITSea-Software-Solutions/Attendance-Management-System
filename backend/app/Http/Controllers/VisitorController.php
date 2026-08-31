<?php

namespace App\Http\Controllers;

use App\Models\CompanyHost;
use App\Models\GatePass;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Visitor / Gate-Pass module.
 *
 * Hosts (who may receive visitors) are maintained by company HR/admin.
 * Gate users create passes (guest + live photo + host); the host gets a
 * WhatsApp asking YES/NO (via the Meta webhook below). Until WhatsApp
 * credentials are configured — or when the host answers by phone — the
 * gate/HR can record a MANUAL decision, always with a note and audit row.
 */
class VisitorController extends Controller
{
    public function __construct(private AuditService $audit)
    {
    }

    private function companyId(Request $request): int
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || $user->isCompanyUser(), 403, 'Company-side feature.');
        $cid = $user->isCompanyUser() ? $user->company_id : $request->integer('company_id');
        abort_unless($cid, 422, 'company_id required.');

        return $cid;
    }

    // ─── Hosts (HR-maintained) ───────────────────────────────────────────────

    public function hosts(Request $request): JsonResponse
    {
        $cid = $this->companyId($request);
        $q = CompanyHost::where('company_id', $cid)
            ->when($request->boolean('active_only'), fn ($qq) => $qq->where('is_active', true))
            ->when($request->search, fn ($qq, $s) => $qq->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('department', 'like', "%{$s}%")
                ->orWhere('position', 'like', "%{$s}%")))
            ->orderBy('name');

        return response()->json($q->get());
    }

    public function storeHost(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['super_admin', 'company_admin', 'company_hr']), 403,
            'Only company HR/admin manage the visitor host list.');
        $cid  = $this->companyId($request);
        $data = $request->validate([
            'name'       => 'required|string|max:120',
            'phone'      => 'required|string|regex:/^[6-9]\d{9}$/',
            'position'   => 'nullable|string|max:80',
            'department' => 'nullable|string|max:80',
        ], ['phone.regex' => 'Phone must be a 10-digit Indian mobile number.']);

        $host = CompanyHost::create($data + ['company_id' => $cid, 'is_active' => true]);
        $this->audit->log($user->id, 'visitor_host_created', CompanyHost::class, $host->id, ['name' => $host->name]);

        return response()->json($host, 201);
    }

    public function updateHost(Request $request, CompanyHost $host): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['super_admin', 'company_admin', 'company_hr']), 403);
        abort_unless($user->isSuperAdmin() || $host->company_id === $user->company_id, 403);
        $data = $request->validate([
            'name'       => 'sometimes|string|max:120',
            'phone'      => 'sometimes|string|regex:/^[6-9]\d{9}$/',
            'position'   => 'nullable|string|max:80',
            'department' => 'nullable|string|max:80',
            'is_active'  => 'sometimes|boolean',
        ]);
        $host->fill($data)->save();
        $this->audit->log($user->id, 'visitor_host_updated', CompanyHost::class, $host->id);

        return response()->json($host);
    }

    public function destroyHost(Request $request, CompanyHost $host): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['super_admin', 'company_admin', 'company_hr']), 403);
        abort_unless($user->isSuperAdmin() || $host->company_id === $user->company_id, 403);
        $host->delete();
        $this->audit->log($user->id, 'visitor_host_deleted', CompanyHost::class, $host->id, ['name' => $host->name]);

        return response()->json(['message' => 'Host removed.']);
    }

    // ─── Gate passes ─────────────────────────────────────────────────────────

    public function passes(Request $request): JsonResponse
    {
        $cid = $this->companyId($request);
        $q = GatePass::with(['host:id,name,position,department', 'creator:id,name'])
            ->where('company_id', $cid)
            ->when($request->date, fn ($qq, $d) => $qq->whereDate('created_at', $d),
                fn ($qq) => $qq->whereDate('created_at', today()))
            ->when($request->status, fn ($qq, $s) => $qq->where('status', $s))
            ->orderByDesc('created_at');

        return response()->json($q->limit(200)->get());
    }

    public function storePass(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['super_admin', 'company_admin', 'company_gate', 'company_hr']), 403);
        $cid  = $this->companyId($request);
        $data = $request->validate([
            'host_id'        => 'required|integer|exists:company_hosts,id',
            'guest_name'     => 'required|string|max:120',
            'guest_phone'    => 'nullable|string|regex:/^[6-9]\d{9}$/',
            'purpose'        => 'nullable|string|max:200',
            'vehicle_number' => 'nullable|string|max:20',
            'photo'          => 'nullable|image|max:5120|mimes:jpeg,png,jpg',
            'vehicle_photo'  => 'nullable|image|max:5120|mimes:jpeg,png,jpg',
        ], ['guest_phone.regex' => 'Guest phone must be a 10-digit mobile number.']);

        // A pass without any picture is worthless at the gate — require at
        // least one of the two (the visitor, or the vehicle they arrived in).
        if (! $request->hasFile('photo') && ! $request->hasFile('vehicle_photo')) {
            return response()->json([
                'message' => 'Capture at least one photo — the visitor or the vehicle.',
                'errors'  => ['photo' => ['Capture at least one photo — the visitor or the vehicle.']],
            ], 422);
        }

        $host = CompanyHost::where('company_id', $cid)->where('is_active', true)->findOrFail($data['host_id']);

        $dir = 'visitors/'.today()->format('Y/m/d');
        $photoPath        = $request->hasFile('photo') ? $request->file('photo')->store($dir, 'private') : null;
        $vehiclePhotoPath = $request->hasFile('vehicle_photo') ? $request->file('vehicle_photo')->store($dir, 'private') : null;

        // Host approval is a per-company policy. When it is switched off the
        // gate can admit the visitor straight away; the pass still records who
        // raised it, the photos and the host being visited.
        $company  = \App\Models\Company::find($cid);
        $needsOk  = (bool) (((array) ($company->settings ?? []))['require_visitor_approval'] ?? true);

        $seq  = GatePass::whereDate('created_at', today())->count() + 1;
        $pass = GatePass::create([
            'code'          => sprintf('GP-%s-%04d', today()->format('Ymd'), $seq),
            'company_id'    => $cid,
            'host_id'       => $host->id,
            'guest_name'    => $data['guest_name'],
            'guest_phone'   => $data['guest_phone'] ?? null,
            'purpose'       => $data['purpose'] ?? null,
            'vehicle_number' => ! empty($data['vehicle_number'])
                ? strtoupper(preg_replace('/\s+/', '', $data['vehicle_number'])) : null,
            'status'        => $needsOk ? GatePass::STATUS_PENDING : GatePass::STATUS_APPROVED,
            'decided_via'   => $needsOk ? null : 'auto',
            'decision_note' => $needsOk ? null : 'Host approval not required for this company.',
            'decided_at'    => $needsOk ? null : now(),
            'location_name' => ($user->isGateUser() && $user->location_name) ? $user->location_name : 'Main Gate',
            'created_by'    => $user->id,
        ]);
        $pass->forceFill(array_filter([
            'photo_path'         => $photoPath,
            'vehicle_photo_path' => $vehiclePhotoPath,
            // Set outside mass assignment on purpose: this token is the only
            // thing standing between a link and a decision.
            'approval_token'     => $needsOk ? \Illuminate\Support\Str::random(48) : null,
        ]))->save();

        // Ask the host on WhatsApp only when their approval is actually needed.
        if ($needsOk) {
        app(\App\Services\NotifyService::class)->whatsapp(
            $host->phone, 'gatepass_request', [
                'guest_name'   => $pass->guest_name,
                'guest_phone'  => $pass->guest_phone ? " ({$pass->guest_phone})" : '',
                'purpose'      => $pass->purpose ?: '-',
                'code'         => $pass->code,
                'gate'         => $pass->location_name,
                'company_name' => $company?->name ?? '',
                'host_name'    => $host->name,
                'approve_url'  => $pass->approval_token
                    ? rtrim(config('app.url'), '/').'/visitor-approval/'.$pass->approval_token
                    : '',
            ],
            'company', $cid, $company?->plan ?? 'trial',
            (array) ($company?->settings ?? [])
        );
        }

        $this->audit->log($user->id, 'gatepass_created', GatePass::class, $pass->id, [
            'guest' => $pass->guest_name, 'host' => $host->name, 'code' => $pass->code,
            'vehicle' => $pass->vehicle_number, 'approval_required' => $needsOk,
        ]);

        return response()->json($pass->load('host:id,name,position,department'), 201);
    }

    /**
     * Manual decision: the host answered by phone or in person, or WhatsApp is
     * not set up. Deliberately NOT available to gate logins — the gate raises
     * the request, it does not get to approve its own visitor. Admin and HR
     * record the host's answer; the host can also reply on WhatsApp directly.
     */
    public function decidePass(Request $request, GatePass $pass): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['super_admin', 'company_admin', 'company_hr']), 403,
            'Only the company admin or HR can record the host\'s decision.');
        abort_unless($user->isSuperAdmin() || $pass->company_id === $user->company_id, 403);
        abort_unless($pass->status === GatePass::STATUS_PENDING, 422, 'Pass already decided.');
        $data = $request->validate([
            'decision' => 'required|in:approved,denied',
            'note'     => 'required|string|max:200',
        ], ['note.required' => 'Say how the host approved (e.g. "confirmed on phone call").']);

        $pass->forceFill([
            'status'        => $data['decision'],
            'decided_via'   => 'manual',
            'decision_note' => $data['note'],
            'decided_at'    => now(),
        ])->save();
        $this->audit->log($user->id, 'gatepass_decided_manual', GatePass::class, $pass->id, $data);

        return response()->json($pass->fresh()->load('host:id,name,position,department'));
    }

    /** Record the visitor walking in (approved passes only) / walking out. */
    public function movePass(Request $request, GatePass $pass): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['super_admin', 'company_admin', 'company_gate', 'company_hr']), 403);
        abort_unless($user->isSuperAdmin() || $pass->company_id === $user->company_id, 403);
        $dir = $request->validate(['direction' => 'required|in:entry,exit'])['direction'];

        if ($dir === 'entry') {
            abort_unless($pass->status === GatePass::STATUS_APPROVED, 422, 'Pass is not approved yet.');
            abort_if($pass->entry_at, 422, 'Entry already recorded.');
            $pass->forceFill(['entry_at' => now()])->save();
        } else {
            abort_unless($pass->entry_at, 422, 'No entry recorded for this pass.');
            abort_if($pass->exit_at, 422, 'Exit already recorded.');
            $pass->forceFill(['exit_at' => now()])->save();
        }
        $this->audit->log($user->id, 'gatepass_'.$dir, GatePass::class, $pass->id);

        return response()->json($pass->fresh()->load('host:id,name,position,department'));
    }

    /** ?type=vehicle serves the vehicle shot; default is the visitor's photo. */
    public function passPhoto(Request $request, GatePass $pass)
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || ($user->isCompanyUser() && $pass->company_id === $user->company_id), 403);
        $path = $request->input('type') === 'vehicle' ? $pass->vehicle_photo_path : $pass->photo_path;
        abort_unless($path, 404);

        return Storage::disk('private')->response($path);
    }

    // ─── WhatsApp inbound webhook (Meta Cloud API) ───────────────────────────

    /** GET: Meta's verification handshake. */
    /**
     * PUBLIC — what the host sees when they tap the link. The token is the
     * credential, so only the bare minimum needed to make the decision is
     * returned, and only while the pass is still open.
     */
    public function publicPass(string $token): JsonResponse
    {
        $pass = GatePass::with('host:id,name,department')->where('approval_token', $token)->first();
        abort_unless($pass, 404, 'This link is not valid.');

        return response()->json([
            'code'         => $pass->code,
            'guest_name'   => $pass->guest_name,
            'guest_phone'  => $pass->guest_phone,
            'purpose'      => $pass->purpose,
            'vehicle_number' => $pass->vehicle_number,
            'has_photo'         => $pass->has_photo,
            'has_vehicle_photo' => $pass->has_vehicle_photo,
            'host_name'    => $pass->host?->name,
            'company_name' => \App\Models\Company::find($pass->company_id)?->name,
            'gate'         => $pass->location_name,
            'requested_at' => $pass->created_at,
            'status'       => $pass->status,
            'decided_at'   => $pass->decided_at,
            // Expired links stay readable but can no longer be acted on.
            'actionable'   => $pass->status === GatePass::STATUS_PENDING
                              && $pass->created_at->isToday(),
        ]);
    }

    /** PUBLIC — the host taps Approve or Deny. */
    public function publicDecide(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['decision' => 'required|in:approved,denied']);

        $pass = GatePass::where('approval_token', $token)->first();
        abort_unless($pass, 404, 'This link is not valid.');
        abort_unless($pass->status === GatePass::STATUS_PENDING, 422,
            'This visitor has already been '.$pass->status.'.');
        abort_unless($pass->created_at->isToday(), 422, 'This link has expired.');

        $pass->forceFill([
            'status'        => $data['decision'],
            'decided_via'   => 'link',
            'decision_note' => 'Answered by the host from the approval link.',
            'decided_at'    => now(),
            'approval_token' => null,   // one decision per link
        ])->save();

        $this->audit->log(null, 'gatepass_decided_link', GatePass::class, $pass->id, $data);

        return response()->json([
            'message' => $data['decision'] === 'approved'
                ? 'Approved — the gate can let them in.'
                : 'Denied — the gate has been told.',
            'status'  => $pass->status,
        ]);
    }

    /** PUBLIC — the guest/vehicle photo behind an approval token. */
    public function publicPassPhoto(Request $request, string $token)
    {
        $pass = GatePass::where('approval_token', $token)->first();
        abort_unless($pass, 404);
        $path = $request->input('type') === 'vehicle' ? $pass->vehicle_photo_path : $pass->photo_path;
        abort_unless($path, 404);

        return Storage::disk('private')->response($path);
    }

    public function webhookVerify(Request $request)
    {
        $verify = config('services.whatsapp.webhook_verify', env('WHATSAPP_WEBHOOK_VERIFY', 'truecrew'));
        if ($request->query('hub_mode') === 'subscribe'
            && $request->query('hub_verify_token') === $verify) {
            return response($request->query('hub_challenge'), 200);
        }

        return response('forbidden', 403);
    }

    /**
     * POST: incoming messages. A host replying YES/NO decides their most
     * recent pending pass from today. Unknown senders/messages are ignored.
     */
    public function webhookReceive(Request $request): JsonResponse
    {
        try {
            foreach (($request->input('entry') ?? []) as $entry) {
                foreach (($entry['changes'] ?? []) as $change) {
                    foreach (($change['value']['messages'] ?? []) as $msg) {
                        $from = preg_replace('/\D/', '', $msg['from'] ?? '');
                        $text = strtolower(trim($msg['text']['body'] ?? ''));
                        if (! $from || $text === '') {
                            continue;
                        }
                        $decision = null;
                        if (in_array($text, ['yes', 'y', 'ok', 'allow', 'approve', 'haan', 'ha'])) {
                            $decision = GatePass::STATUS_APPROVED;
                        } elseif (in_array($text, ['no', 'n', 'deny', 'reject', 'nahi'])) {
                            $decision = GatePass::STATUS_DENIED;
                        }
                        if (! $decision) {
                            continue;
                        }
                        $local = strlen($from) === 12 && str_starts_with($from, '91')
                            ? substr($from, 2) : $from;
                        $host = CompanyHost::where('phone', $local)->where('is_active', true)->first();
                        if (! $host) {
                            continue;
                        }
                        $pass = GatePass::where('host_id', $host->id)
                            ->where('status', GatePass::STATUS_PENDING)
                            ->whereDate('created_at', today())
                            ->orderByDesc('created_at')
                            ->first();
                        if (! $pass) {
                            continue;
                        }
                        $pass->forceFill([
                            'status'        => $decision,
                            'decided_via'   => 'whatsapp',
                            'decision_note' => 'WhatsApp reply: '.$text,
                            'decided_at'    => now(),
                        ])->save();
                        $this->audit->log($host->id, 'gatepass_decided_whatsapp', GatePass::class, $pass->id, [
                            'decision' => $decision, 'host' => $host->name,
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['status' => 'ok']); // Meta requires 200
    }
}
