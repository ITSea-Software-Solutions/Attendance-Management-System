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
            return response()->json(['plans' => config('plans.plans'), 'org' => null]);
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
                'limits' => PlanService::limits($org->plan),
                'usage'  => PlanService::usage($ctx['type'], $org->id),
            ],
            'pending_request' => $pending,
            'plans'           => config('plans.plans'),
        ]);
    }

    public function requestUpgrade(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => 'required|in:professional,enterprise',
            'note' => 'nullable|string|max:500',
        ]);
        $ctx = PlanService::orgFor($request->user());
        if (! $ctx) {
            return response()->json(['message' => 'Super admin sets plans directly on the Subscriptions page.'], 422);
        }
        if (! in_array($request->user()->role, ['company_admin', 'vendor_admin'], true)) {
            return response()->json(['message' => 'Only the organisation admin can request an upgrade.'], 403);
        }
        if ($ctx['org']->plan === $data['plan']) {
            return response()->json(['message' => 'You are already on this plan.'], 422);
        }
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
                    'usage'    => PlanService::usage($type, $org->id),
                    'limits'   => PlanService::limits($org->plan),
                ];
            }
        }
        $pending = PlanUpgradeRequest::with('requester:id,name,email')
            ->where('status', 'pending')->orderBy('created_at')->get();

        return response()->json(['orgs' => $rows, 'pending_requests' => $pending, 'plans' => config('plans.plans')]);
    }

    /** Directly set any org's plan (enrolment / after offline payment). */
    public function setPlan(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate([
            'org_type' => 'required|in:company,vendor',
            'org_id'   => 'required|integer',
            'plan'     => 'required|in:trial,professional,enterprise',
        ]);
        $org = $data['org_type'] === 'company' ? Company::find($data['org_id']) : Vendor::find($data['org_id']);
        if (! $org) {
            return response()->json(['message' => 'Organisation not found.'], 404);
        }
        $org->forceFill(['plan' => $data['plan'], 'plan_started_at' => now()])->save();

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
            $planRequest->org()?->forceFill([
                'plan'            => $planRequest->requested_plan,
                'plan_started_at' => now(),
            ])->save();
        }
        try {
            $org = $planRequest->org();
            if ($org && $org->contact_email) {
                $approved = $data['action'] === 'approve';
                \Illuminate\Support\Facades\Mail::raw(
                    $approved
                        ? "Your TrueCrew plan upgrade to {$planRequest->requested_plan} is ACTIVE. Thank you!"
                        : "Your TrueCrew plan upgrade request to {$planRequest->requested_plan} was declined. Reply to this email or contact support for details.",
                    fn ($m) => $m->to($org->contact_email)->subject('TrueCrew — plan upgrade ' . ($approved ? 'activated' : 'declined'))
                );
            }
        } catch (\Throwable $e) { report($e); }

        $this->audit->log($request->user()->id, "plan_request_{$data['action']}", PlanUpgradeRequest::class, $planRequest->id);

        return response()->json(['message' => $data['action'] === 'approve'
            ? 'Approved — the organisation is now on ' . $planRequest->requested_plan . '.'
            : 'Request rejected.']);
    }
}
