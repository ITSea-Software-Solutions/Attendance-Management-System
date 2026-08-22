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
            'host_id'     => 'required|integer|exists:company_hosts,id',
            'guest_name'  => 'required|string|max:120',
            'guest_phone' => 'nullable|string|regex:/^[6-9]\d{9}$/',
            'purpose'     => 'nullable|string|max:200',
            'photo'       => 'nullable|image|max:5120|mimes:jpeg,png,jpg',
        ], ['guest_phone.regex' => 'Guest phone must be a 10-digit mobile number.']);

        $host = CompanyHost::where('company_id', $cid)->where('is_active', true)->findOrFail($data['host_id']);

        $photoPath = $request->hasFile('photo')
            ? $request->file('photo')->store('visitors/'.today()->format('Y/m/d'), 'private')
            : null;

        $seq  = GatePass::whereDate('created_at', today())->count() + 1;
        $pass = GatePass::create([
            'code'          => sprintf('GP-%s-%04d', today()->format('Ymd'), $seq),
            'company_id'    => $cid,
            'host_id'       => $host->id,
            'guest_name'    => $data['guest_name'],
            'guest_phone'   => $data['guest_phone'] ?? null,
            'purpose'       => $data['purpose'] ?? null,
            'status'        => GatePass::STATUS_PENDING,
            'location_name' => ($user->isGateUser() && $user->location_name) ? $user->location_name : 'Main Gate',
            'created_by'    => $user->id,
        ]);
        if ($photoPath) {
            $pass->forceFill(['photo_path' => $photoPath])->save();
        }

        // Ask the host on WhatsApp (no-op until provider credentials are set —
        // the manual decision path below keeps the gate moving either way).
        $company = \App\Models\Company::find($cid);
        app(\App\Services\NotifyService::class)->whatsapp(
            $host->phone, 'gatepass_request', [
                'guest_name'   => $pass->guest_name,
                'guest_phone'  => $pass->guest_phone ? " ({$pass->guest_phone})" : '',
                'purpose'      => $pass->purpose ?: '-',
                'code'         => $pass->code,
                'gate'         => $pass->location_name,
                'company_name' => $company?->name ?? '',
                'host_name'    => $host->name,
            ],
            'company', $cid, $company?->plan ?? 'trial',
            (array) ($company?->settings ?? [])
        );

        $this->audit->log($user->id, 'gatepass_created', GatePass::class, $pass->id, [
            'guest' => $pass->guest_name, 'host' => $host->name, 'code' => $pass->code,
        ]);

        return response()->json($pass->load('host:id,name,position,department'), 201);
    }

    /** Manual decision: host answered by phone/in person, or WhatsApp is not set up. */
    public function decidePass(Request $request, GatePass $pass): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['super_admin', 'company_admin', 'company_gate', 'company_hr']), 403);
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

    public function passPhoto(Request $request, GatePass $pass)
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || ($user->isCompanyUser() && $pass->company_id === $user->company_id), 403);
        abort_unless($pass->photo_path, 404);

        return Storage::disk('private')->response($pass->photo_path);
    }

    // ─── WhatsApp inbound webhook (Meta Cloud API) ───────────────────────────

    /** GET: Meta's verification handshake. */
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
