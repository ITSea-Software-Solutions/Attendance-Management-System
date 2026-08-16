<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Vendor;
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
            ->when(! $user->isSuperAdmin(), fn($q) => $q->where('id', $user->company_id))
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->search, fn($q, $s) => $q->where('name', 'like', "%{$s}%"));

        // Vendor users see only approved companies
        if ($user->isVendorUser()) {
            $query->whereHas('vendors', fn($q) => $q
                ->where('vendor_id', $user->vendor_id)
                ->wherePivot('status', 'approved')
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
        $vendors = $company->vendors()->withPivot(['status', 'approved_at', 'rejection_reason'])->get();
        return response()->json($vendors);
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
}
