<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PlanUpgradeRequest;
use App\Models\Vendor;
use App\Services\AuditService;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SaaS plans & subscriptions.
 *
 * Org admins:  GET /plan (current plan + usage + all plan cards),
 *              POST /plan/upgrade-request.
 * Super admin: GET /admin/subscriptions, POST /admin/subscriptions/set-plan,
 *              GET /admin/plan-requests, POST /admin/plan-requests/{id}/decide.
 * Payment is offline for now — approval == payment settled outside the app.
 */
class PlanController extends Controller
{
    public function __construct(private AuditService $audit)
    {
    }

    /** Current org's plan, live usage, and the catalogue (for plan cards). */
    public function show(Request $request): JsonResponse
    {
        $ctx = PlanService::orgFor($request->user());
        if (! $ctx) { // super admin has no org/plan
            return response()->json(['plans' => config('plans.plans'), 'feature_labels' => config('plans.feature_labels'), 'org' => null]);
        }
        $org = $ctx['org'];
        $pending = PlanUpgradeRequest::where('org_type', $ctx['type'])
            ->where('org_id', $org->id)->where('status', 'pending')->first();

        return response()->json([
            'org' => [
                'type'   => $ctx['type'],
                'id'     => $org->id,
                'name'   => $org->name,
                'plan'   => $org->plan,
                'limits' => PlanService::limits(PlanService::effectivePlan($org)),
                'usage'  => PlanService::usage($ctx['type'], $org->id),
                'plan_expires_at' => $org->plan_expires_at?->toDateString(),
                'days_left'       => PlanService::daysLeft($org),
                'licence_lapsed'  => PlanService::effectivePlan($org) !== ($org->plan ?? 'trial'),
            ],
            'pending_request' => $pending,
            'payment'         => config('plans.payment'),
            'plans'           => config('plans.plans'),
            'feature_labels'  => config('plans.feature_labels'),
        ]);
    }

    /**
     * Record an offline payment on the org's own pending request: method,
     * reference (UTR/txn/cheque no), amount, optional proof image/PDF.
     * The super admin then verifies it on the Subscriptions page.
     */
    public function recordPayment(Request $request, PlanUpgradeRequest $planRequest): JsonResponse
    {
        $ctx = PlanService::orgFor($request->user());
        abort_unless($ctx && $planRequest->org_type === $ctx['type']
            && $planRequest->org_id === $ctx['org']->id, 403);
        abort_unless(in_array($request->user()->role, ['company_admin', 'vendor_admin'], true), 403,
            'Only the organisation admin can record payments.');
        abort_unless($planRequest->status === 'pending', 422, 'This request was already decided.');

        $data = $request->validate([
            'payment_method'    => 'required|in:upi,bank_transfer,cash,cheque',
            'payment_reference' => 'required|string|max:80',
            'amount'            => 'required|numeric|min:1|max:10000000',
            'proof'             => 'nullable|file|max:5120|mimes:jpeg,png,jpg,pdf',
        ], ['payment_reference.required' => 'Enter the UTR / transaction / cheque number.']);

        if ($request->hasFile('proof')) {
            if ($planRequest->payment_proof_path) {
                \Illuminate\Support\Facades\Storage::disk('private')->delete($planRequest->payment_proof_path);
            }
            $planRequest->forceFill([
                'payment_proof_path' => $request->file('proof')->store('payments', 'private'),
            ]);
        }
        $planRequest->forceFill([
            'payment_method'    => $data['payment_method'],
            'payment_reference' => $data['payment_reference'],
            'amount'            => $data['amount'],
            'paid_at'           => now(),
        ])->save();

        // Tell the platform team there is money to verify.
        $notify = app(\App\Services\NotifyService::class);
        $supers = \App\Models\User::where('role', 'super_admin')->get();
        $notify->inApp($supers, 'plan_payment_recorded',
            "Payment recorded: {$ctx['org']->name} → {$planRequest->requested_plan}",
            strtoupper($data['payment_method'])." ₹{$data['amount']} · ref {$data['payment_reference']} — verify on Subscriptions.");

        $this->audit->log($request->user()->id, 'plan_payment_recorded', PlanUpgradeRequest::class, $planRequest->id, [
            'method' => $data['payment_method'], 'amount' => $data['amount'], 'ref' => $data['payment_reference'],
        ]);

        return response()->json([
            'message' => 'Payment recorded — the platform team will verify and activate your plan.',
            'request' => $planRequest->fresh(),
        ]);
    }

    /** Serve the payment proof (super admin, or the org's own admin). */
    public function paymentProof(Request $request, PlanUpgradeRequest $planRequest)
    {
        $user = $request->user();
        if (! $user->isSuperAdmin()) {
            $ctx = PlanService::orgFor($user);
            abort_unless($ctx && $planRequest->org_type === $ctx['type']
                && $planRequest->org_id === $ctx['org']->id, 403);
        }
        abort_unless($planRequest->payment_proof_path, 404);

        return \Illuminate\Support\Facades\Storage::disk('private')->response($planRequest->payment_proof_path);
    }

    public function requestUpgrade(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan'   => 'required|in:professional,enterprise',
            'months' => 'nullable|integer|in:1,3,6,12',
            'note'   => 'nullable|string|max:500',
        ]);
        $ctx = PlanService::orgFor($request->user());
        if (! $ctx) {
            return response()->json(['message' => 'Super admin sets plans directly on the Subscriptions page.'], 422);
        }
        if (! in_array($request->user()->role, ['company_admin', 'vendor_admin'], true)) {
            return response()->json(['message' => 'Only the organisation admin can request an upgrade.'], 403);
        }
        if ($ctx['org']->plan === $data['plan'] && ! $ctx['org']->plan_expires_at) {
            return response()->json(['message' => 'You are already on this plan.'], 422);
        } // same plan WITH an expiry = a renewal — allowed
        $existing = PlanUpgradeRequest::where('org_type', $ctx['type'])
            ->where('org_id', $ctx['org']->id)->where('status', 'pending')->first();
        if ($existing) {
            return response()->json(['message' => 'An upgrade request is already pending — our team will contact you.'], 422);
        }

        $req = PlanUpgradeRequest::create([
            'org_type'       => $ctx['type'],
            'org_id'         => $ctx['org']->id,
            'current_plan'   => $ctx['org']->plan,
            'requested_plan' => $data['plan'],
            'months'         => $data['months'] ?? 1,
            'requested_by'   => $request->user()->id,
            'note'           => $data['note'] ?? null,
        ]);
        $this->audit->log($request->user()->id, 'plan_upgrade_requested', PlanUpgradeRequest::class, $req->id, [
            'to' => $data['plan'],
        ]);

        return response()->json([
            'message' => 'Upgrade request sent. Payment is settled offline — our team will contact you shortly.',
            'request' => $req,
        ], 201);
    }

    // ── Super admin ───────────────────────────────────────────────────────────

    /** Every org with plan, usage, and pending request — the Subscriptions table. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $rows = [];
        foreach (['company' => Company::all(), 'vendor' => Vendor::all()] as $type => $orgs) {
            foreach ($orgs as $org) {
                $rows[] = [
                    'org_type' => $type,
                    'id'       => $org->id,
                    'name'     => $org->name,
                    'code'     => $org->code,
                    'status'   => $org->status,
                    'plan'     => $org->plan,
                    'plan_started_at' => $org->plan_started_at ? substr((string) $org->plan_started_at, 0, 10) : null,
                    'plan_expires_at' => $org->plan_expires_at ? substr((string) $org->plan_expires_at, 0, 10) : null,
                    'days_left'       => PlanService::daysLeft($org),
                    'licence_lapsed'  => PlanService::effectivePlan($org) !== ($org->plan ?? 'trial'),
                    'usage'    => PlanService::usage($type, $org->id),
                    'limits'   => PlanService::limits($org->plan),
                ];
            }
        }
        $pending = PlanUpgradeRequest::with('requester:id,name,email')
            ->where('status', 'pending')->orderBy('created_at')->get();

        return response()->json(['orgs' => $rows, 'pending_requests' => $pending, 'plans' => config('plans.plans'), 'feature_labels' => config('plans.feature_labels')]);
    }

    /** Directly set any org's plan (enrolment / after offline payment). */
    public function setPlan(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate([
            'org_type' => 'required|in:company,vendor',
            'org_id'   => 'required|integer',
            'plan'     => 'required|in:trial,professional,enterprise',
            'months'   => 'nullable|integer|min:1|max:36',
        ]);
        $org = $data['org_type'] === 'company' ? Company::find($data['org_id']) : Vendor::find($data['org_id']);
        if (! $org) {
            return response()->json(['message' => 'Organisation not found.'], 404);
        }
        $org->forceFill([
            'plan'            => $data['plan'],
            'plan_started_at' => now(),
            // months given -> licence expiry; trial or omitted -> open-ended
            'plan_expires_at' => ($data['plan'] !== 'trial' && ! empty($data['months']))
                ? now()->addMonths((int) $data['months']) : null,
        ])->save();

        // Auto-resolve a matching pending request, if any.
        PlanUpgradeRequest::where('org_type', $data['org_type'])->where('org_id', $org->id)
            ->where('status', 'pending')
            ->update(['status' => 'approved', 'decided_by' => $request->user()->id, 'decided_at' => now()]);

        $this->audit->log($request->user()->id, 'plan_set', get_class($org), $org->id, ['plan' => $data['plan']]);

        return response()->json(['message' => "{$org->name} moved to {$data['plan']}.", 'plan' => $data['plan']]);
    }

    /** Approve/reject an upgrade request. Approving applies the plan. */
    public function decide(Request $request, PlanUpgradeRequest $planRequest): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate(['action' => 'required|in:approve,reject']);
        if ($planRequest->status !== 'pending') {
            return response()->json(['message' => 'This request was already decided.'], 422);
        }

        $planRequest->update([
            'status'     => $data['action'] === 'approve' ? 'approved' : 'rejected',
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
        ]);
        if ($data['action'] === 'approve') {
            $orgModel = $planRequest->org();
            if ($orgModel) {
                $months = max(1, (int) ($planRequest->months ?? 1));
                // Renewing before expiry EXTENDS from the current end date;
                // fresh purchases (or lapsed licences) start from today.
                $base = ($orgModel->plan === $planRequest->requested_plan
                        && $orgModel->plan_expires_at
                        && $orgModel->plan_expires_at->isFuture())
                    ? $orgModel->plan_expires_at : now();
                $orgModel->forceFill([
                    'plan'            => $planRequest->requested_plan,
                    'plan_started_at' => now(),
                    'plan_expires_at' => $base->copy()->addMonths($months),
                ])->save();
            }
        }
        $org = $planRequest->org();
        if ($org) {
            $approved = $data['action'] === 'approve';
            $notify = app(\App\Services\NotifyService::class);
            $orgUsers = $planRequest->org_type === 'company'
                ? \App\Models\User::where('company_id', $org->id)->get()
                : \App\Models\User::where('vendor_id', $org->id)->get();
            $notify->inApp($orgUsers->where('role', '!=', 'company_gate'),
                $approved ? 'plan_approved' : 'plan_declined',
                $approved ? "Plan upgrade to {$planRequest->requested_plan} is ACTIVE"
                          : "Plan upgrade to {$planRequest->requested_plan} was declined");
            if ($org->contact_email) {
                $notify->email($org->contact_email,
                    $approved ? 'plan_approved' : 'plan_declined',
                    ['plan' => $planRequest->requested_plan, 'org_name' => $org->name],
                    $planRequest->org_type, $org->id, $org->plan ?? 'trial');
            }
        }

        $this->audit->log($request->user()->id, "plan_request_{$data['action']}", PlanUpgradeRequest::class, $planRequest->id);

        return response()->json(['message' => $data['action'] === 'approve'
            ? 'Approved — the organisation is now on ' . $planRequest->requested_plan . '.'
            : 'Request rejected.']);
    }
}
