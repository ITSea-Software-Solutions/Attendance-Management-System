<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Vendor;
use App\Models\WorkerAssignment;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Company::query()
            // Company users only ever see their own; vendors are scoped by the
            // approved-link check below (their company_id is null).
            ->when($user->isCompanyUser(), fn($q) => $q->where('id', $user->company_id))
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->search, fn($q, $s) => $q->where('name', 'like', "%{$s}%"));

        // Vendor users see only companies that approved them. Note: inside a
        // whereHas closure the builder is a plain query, so the pivot column
        // must be named explicitly — wherePivot() is not available here.
        if ($user->isVendorUser()) {
            $query->whereHas('vendors', fn($q) => $q
                ->where('vendors.id', $user->vendor_id)
                ->where('company_vendors.status', 'approved')
            );
        }

        return response()->json($query->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $data = $request->validate([
            'name'           => 'required|string|max:120|unique:companies',
            'code'           => 'required|string|max:20|unique:companies',
            'address'        => 'required|string',
            'city'           => 'required|string',
            'state'          => 'required|string',
            'pin'            => 'required|string|size:6',
            'contact_person' => 'required|string',
            'contact_email'  => 'required|email',
            'contact_phone'  => 'required|string',
            'gst_number'     => 'nullable|string|max:15',
            'status'         => 'nullable|in:active,inactive',
        ]);

        $company = Company::create($data);
        $this->audit->log($request->user()->id, 'company_created', Company::class, $company->id);

        return response()->json($company, 201);
    }

    public function show(Company $company): JsonResponse
    {
        return response()->json($company->load('approvedVendors'));
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $data = $request->validate([
            'name'           => "sometimes|string|max:120|unique:companies,name,{$company->id}",
            'address'        => 'sometimes|string',
            'city'           => 'sometimes|string',
            'state'          => 'sometimes|string',
            'pin'            => 'sometimes|string|size:6',
            'contact_person' => 'sometimes|string',
            'contact_email'  => 'sometimes|email',
            'contact_phone'  => 'sometimes|string',
            'gst_number'     => 'nullable|string',
            'status'         => 'sometimes|in:active,inactive',
        ]);

        $company->update($data);
        $this->audit->log($request->user()->id, 'company_updated', Company::class, $company->id);

        return response()->json($company->fresh());
    }

    public function destroy(Request $request, Company $company): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $company->delete();
        $this->audit->log($request->user()->id, 'company_deleted', Company::class, $company->id);

        return response()->json(['message' => 'Company deleted.']);
    }

    // ── Vendor-Company relationship management ────────────────────────────────

    public function vendors(Company $company): JsonResponse
    {
        $vendors = $company->vendors()->withPivot(['status', 'approved_at', 'rejection_reason', 'details_consent_at'])->get();
        return response()->json($vendors);
    }

    /**
     * Tab-based vendor detail for COMPANY users: profile, relationship
     * timeline, workers supplied, and attendance history at THIS company.
     * The history requires the vendor's details-sharing consent (given with
     * the access request; implicit for company-created vendors).
     */
    public function vendorDetail(Request $request, Company $company, Vendor $vendor): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->isSuperAdmin() || ($user->isCompanyUser() && $user->company_id === $company->id),
            403
        );

        $link = $company->vendors()->where('vendor_id', $vendor->id)->first();
        abort_unless($link, 404, 'No relationship with this vendor.');
        $pivot = $link->pivot;

        $profile = [
            'id'             => $vendor->id,
            'name'           => $vendor->name,
            'code'           => $vendor->code,
            'contact_person' => $vendor->contact_person,
            'contact_email'  => $vendor->contact_email,
            'contact_phone'  => $vendor->contact_phone,
            'city'           => $vendor->city,
            'state'          => $vendor->state,
            'gst_number'     => $vendor->gst_number,
            'pan_number'     => $vendor->pan_number,
            'status'         => $vendor->status,
            'since'          => $vendor->created_at?->toDateString(),
        ];
        $relationship = [
            'status'             => $pivot->status,
            'requested_at'       => $pivot->created_at,
            'approved_at'        => $pivot->approved_at,
            'rejection_reason'   => $pivot->rejection_reason,
            'details_consent_at' => $pivot->details_consent_at,
        ];

        if (! $pivot->details_consent_at) {
            return response()->json([
                'consented'    => false,
                'profile'      => ['id' => $vendor->id, 'name' => $vendor->name, 'status' => $vendor->status],
                'relationship' => $relationship,
                'message'      => 'This vendor has not consented to share details and history yet — consent is collected with new access requests.',
            ]);
        }

        // ── History with THIS company (consented) ────────────────────────────
        $assignments = WorkerAssignment::with('worker:id,name,status')
            ->where('company_id', $company->id)
            ->where('vendor_id', $vendor->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get()
            ->map(fn ($a) => [
                'id'              => $a->id,
                'worker_id'       => $a->worker_id,
                'worker_name'     => optional($a->worker)->name,
                'worker_status'   => optional($a->worker)->status,
                'start_date'      => $a->start_date?->toDateString(),
                'end_date'        => $a->end_date?->toDateString(),
                'status'          => $a->status,
                'approval_status' => $a->approval_status,
                'requested_at'    => $a->created_at,
                'decided_at'      => $a->approved_at,
            ]);

        $logsBase = \App\Models\AttendanceLog::where('company_id', $company->id)
            ->whereIn('worker_id', function ($q) use ($vendor) {
                $q->select('id')->from('workers')->where('vendor_id', $vendor->id);
            });

        $stats = [
            'workers_ever_deployed' => WorkerAssignment::where('company_id', $company->id)
                ->where('vendor_id', $vendor->id)->distinct('worker_id')->count('worker_id'),
            'active_deployments'    => WorkerAssignment::where('company_id', $company->id)
                ->where('vendor_id', $vendor->id)
                ->where('status', WorkerAssignment::STATUS_ACTIVE)
                ->where('approval_status', 'approved')
                ->where('start_date', '<=', today())->where('end_date', '>=', today())
                ->count(),
            'pending_approvals'     => WorkerAssignment::where('company_id', $company->id)
                ->where('vendor_id', $vendor->id)
                ->where('status', WorkerAssignment::STATUS_ACTIVE)
                ->where('approval_status', 'pending')->count(),
            'total_mandays'         => (clone $logsBase)
                ->selectRaw('COUNT(DISTINCT worker_id, DATE(marked_at)) as c')->value('c'),
            'mandays_30d'           => (clone $logsBase)->where('marked_at', '>=', now()->subDays(30))
                ->selectRaw('COUNT(DISTINCT worker_id, DATE(marked_at)) as c')->value('c'),
            'first_attendance'      => (clone $logsBase)->min('marked_at'),
            'last_attendance'       => (clone $logsBase)->max('marked_at'),
            'currently_inside'      => \App\Models\AttendanceLog::whereIn('id', function ($q) use ($company, $vendor) {
                $q->selectRaw('MAX(al.id)')->from('attendance_logs as al')
                    ->join('workers as w', 'w.id', '=', 'al.worker_id')
                    ->where('al.company_id', $company->id)
                    ->where('w.vendor_id', $vendor->id)
                    ->groupBy('al.worker_id');
            })->where('type', \App\Models\AttendanceLog::TYPE_IN)->count(),
        ];

        // Daily rollup, last 14 days with activity
        $daily = (clone $logsBase)
            ->selectRaw('DATE(marked_at) as d, COUNT(DISTINCT worker_id) as workers, COUNT(*) as events')
            ->groupBy('d')->orderByDesc('d')->limit(14)->get();

        return response()->json([
            'consented'    => true,
            'profile'      => $profile,
            'relationship' => $relationship,
            'stats'        => $stats,
            'deployments'  => $assignments,
            'daily'        => $daily,
        ]);
    }

    public function approveVendor(Request $request, Company $company, Vendor $vendor): JsonResponse
    {
        $this->requireCompanyAdmin($request, $company);

        $existing = $company->vendors()->where('vendor_id', $vendor->id)->first();
        if (! $existing) {
            return response()->json(['message' => 'Vendor has not requested access to this company.'], 422);
        }

        // SaaS: an approved link consumes quota on BOTH orgs (skip when re-approving).
        if ($existing->pivot->status !== 'approved') {
            if ($deny = \App\Services\PlanService::deny(\App\Services\PlanService::ctx('company', $company->id), 'links')) {
                return response()->json($deny, 403);
            }
            if ($deny = \App\Services\PlanService::deny(\App\Services\PlanService::ctx('vendor', $vendor->id), 'links')) {
                $deny['message'] = "The vendor's " . $deny['message'];
                return response()->json($deny, 403);
            }
        }

        $company->vendors()->updateExistingPivot($vendor->id, [
            'status'      => 'approved',
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'rejection_reason' => null,
        ]);

        // Notify the vendor: in-app rows for their users + templated emails.
        $notify = app(\App\Services\NotifyService::class);
        $vendorUsers = \App\Models\User::where('vendor_id', $vendor->id)->get();
        $notify->inApp($vendorUsers, 'vendor_approved',
            "{$company->name} approved your access",
            'You can now deploy workers to their sites.',
            ['company_id' => $company->id]);
        $emails = array_filter(array_unique(array_merge(
            [$vendor->contact_email],
            $vendorUsers->where('role', 'vendor_admin')->pluck('email')->all()
        )));
        foreach ($emails as $to) {
            $notify->email($to, 'vendor_approved', [
                'vendor_name'  => $vendor->name,
                'company_name' => $company->name,
            ], 'company', $company->id, $vendor->plan ?? 'trial');
        }

        $this->audit->log($request->user()->id, 'vendor_approved', Vendor::class, $vendor->id, [
            'company_id' => $company->id,
        ]);

        return response()->json(['message' => "Vendor {$vendor->name} approved for {$company->name}."]);
    }

    public function rejectVendor(Request $request, Company $company, Vendor $vendor): JsonResponse
    {
        $this->requireCompanyAdmin($request, $company);

        $data = $request->validate([
            'reason' => 'required|string|min:5',
        ]);

        $company->vendors()->updateExistingPivot($vendor->id, [
            'status'           => 'rejected',
            'rejection_reason' => $data['reason'],
        ]);

        $notify = app(\App\Services\NotifyService::class);
        $vendorUsers = \App\Models\User::where('vendor_id', $vendor->id)->get();
        $notify->inApp($vendorUsers, 'vendor_rejected',
            "{$company->name} declined your access request",
            "Reason: {$data['reason']}", ['company_id' => $company->id]);
        if ($vendor->contact_email) {
            $notify->email($vendor->contact_email, 'vendor_rejected', [
                'vendor_name'  => $vendor->name,
                'company_name' => $company->name,
                'reason'       => $data['reason'],
            ], 'company', $company->id, $vendor->plan ?? 'trial');
        }

        $this->audit->log($request->user()->id, 'vendor_rejected', Vendor::class, $vendor->id, [
            'company_id' => $company->id,
            'reason'     => $data['reason'],
        ]);

        return response()->json(['message' => 'Vendor rejected.']);
    }

    public function suspendVendor(Request $request, Company $company, Vendor $vendor): JsonResponse
    {
        $this->requireCompanyAdmin($request, $company);

        $company->vendors()->updateExistingPivot($vendor->id, ['status' => 'suspended']);

        $this->audit->log($request->user()->id, 'vendor_suspended', Vendor::class, $vendor->id, [
            'company_id' => $company->id,
        ]);

        return response()->json(['message' => 'Vendor access suspended.']);
    }

    private function requireSuperAdmin(Request $request): void
    {
        if (! $request->user()->isSuperAdmin()) {
            abort(403, 'Only Super Admin can manage companies.');
        }
    }

    private function requireCompanyAdmin(Request $request, Company $company): void
    {
        $user = $request->user();
        if ($user->isSuperAdmin()) return;

        if (! $user->isCompanyUser() || $user->company_id !== $company->id) {
            abort(403, 'Access denied.');
        }
    }

    /** Company notification/approval settings (company admin own org). */
    public function saveSettings(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin()
            || ($user->role === 'company_admin' && $user->company_id === $company->id), 403);
        $data = $request->validate([
            'require_deployment_approval' => 'required|boolean',
        ]);
        $company->forceFill([
            'settings' => array_merge((array) ($company->settings ?? []), $data),
        ])->save();
        $this->audit->log($user->id, 'company_settings_saved', Company::class, $company->id, $data);

        return response()->json(['message' => 'Settings saved.', 'settings' => $company->settings]);
    }
}
