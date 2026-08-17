<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Vendor;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = Vendor::query()
            ->when(! $user->isSuperAdmin() && $user->isVendorUser(), fn($q) => $q->where('id', $user->vendor_id))
            ->when($user->isCompanyUser(), fn($q) => $q->whereHas('companies', function ($cq) use ($user) {
                $cq->where('company_vendors.company_id', $user->company_id)
                   ->where('company_vendors.status', 'approved');
            }))
            ->when($request->search, fn($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->when($request->status, fn($q, $s) => $q->where('status', $s));

        return response()->json($query->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        // Super admin creates global vendors; a company admin can create a
        // vendor for their own company (auto-approved for that company).
        if (! $request->user()->isSuperAdmin() && $request->user()->role !== 'company_admin') {
            abort(403, 'Only Super Admin or a Company Admin can create vendors.');
        }
        // SaaS: creating an auto-approved vendor consumes one of the company's links.
        if ($request->user()->role === 'company_admin') {
            if ($deny = \App\Services\PlanService::deny(\App\Services\PlanService::ctx('company', $request->user()->company_id), 'links')) {
                return response()->json($deny, 403);
            }
        }

        $data = $request->validate([
            'name'           => 'required|string|max:120|unique:vendors',
            'code'           => 'required|string|max:20|unique:vendors',
            'address'        => 'required|string',
            'city'           => 'required|string',
            'state'          => 'required|string',
            'pin'            => 'required|string|size:6',
            'contact_person' => 'required|string',
            'contact_email'  => 'required|email|unique:vendors,contact_email',
            'contact_phone'  => 'required|string',
            'gst_number'     => 'nullable|string|max:15',
            'pan_number'     => 'nullable|string|max:10',
            'license_number' => 'nullable|string',
        ]);

        $vendor = Vendor::create(array_merge($data, ['status' => 'active']));

        // Company-created vendor: immediately approved for that company —
        // the company chose to onboard them, no separate approval round-trip.
        if ($request->user()->role === 'company_admin' && $request->user()->company_id) {
            $vendor->companies()->syncWithoutDetaching([
                $request->user()->company_id => [
                    'status'             => 'approved',
                    'approved_at'        => now(),
                    'approved_by'        => $request->user()->id,
                    // company onboarded them — details sharing is implicit
                    'details_consent_at' => now(),
                ],
            ]);
            // A vendor created BY a company works under that company's umbrella —
            // inherit the company's plan instead of starting on a fresh trial.
            $creatorPlan = \App\Models\Company::find($request->user()->company_id)?->plan ?? 'trial';
            $vendor->forceFill(['plan' => $creatorPlan, 'plan_started_at' => now()])->save();
        }

        $this->audit->log($request->user()->id, 'vendor_created', Vendor::class, $vendor->id);

        return response()->json($vendor, 201);
    }

    public function show(Vendor $vendor): JsonResponse
    {
        return response()->json($vendor->load('approvedCompanies'));
    }

    public function update(Request $request, Vendor $vendor): JsonResponse
    {
        $user = $request->user();
        if (! $user->isSuperAdmin() && ! ($user->isVendorAdmin() && $user->vendor_id === $vendor->id)) {
            abort(403, 'Only Vendor Admin can edit vendor details.');
        }

        $data = $request->validate([
            'name'           => "sometimes|string|max:120|unique:vendors,name,{$vendor->id}",
            'address'        => 'sometimes|string',
            'city'           => 'sometimes|string',
            'state'          => 'sometimes|string',
            'contact_person' => 'sometimes|string',
            'contact_email'  => 'sometimes|email',
            'contact_phone'  => 'sometimes|string',
            'gst_number'     => 'nullable|string',
        ]);

        $vendor->update($data);
        $this->audit->log($user->id, 'vendor_updated', Vendor::class, $vendor->id);

        return response()->json($vendor->fresh());
    }

    public function destroy(Request $request, Vendor $vendor): JsonResponse
    {
        if (! $request->user()->isSuperAdmin()) abort(403);

        $vendor->delete();
        $this->audit->log($request->user()->id, 'vendor_deleted', Vendor::class, $vendor->id);

        return response()->json(['message' => 'Vendor deleted.']);
    }

    // ── Vendor requests access to a company ───────────────────────────────────

    public function requestCompany(Request $request, Vendor $vendor, Company $company): JsonResponse
    {
        $user = $request->user();

        if (! $user->isSuperAdmin() && ! ($user->isVendorAdmin() && $user->vendor_id === $vendor->id)) {
            abort(403, 'Only Vendor Admin can send company access requests.');
        }

        $existing = $vendor->companies()->where('company_id', $company->id)->first();

        if ($existing) {
            return response()->json([
                'message' => 'Already ' . $existing->pivot->status . ' for this company.',
            ], 422);
        }

        // SaaS: block new requests once the vendor's approved-link quota is full.
        if ($deny = \App\Services\PlanService::deny(\App\Services\PlanService::ctx('vendor', $vendor->id), 'links')) {
            return response()->json($deny, 403);
        }

        // Consent is part of the request: the vendor agrees the company may
        // view their organisation profile and track the working history
        // (workers, deployments, attendance) while access is active.
        if (! $request->boolean('consent')) {
            return response()->json([
                'message' => 'Please accept the details-sharing consent — the company needs it to review and track your organisation.',
            ], 422);
        }

        $vendor->companies()->attach($company->id, [
            'status'             => 'pending',
            'details_consent_at' => now(),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $this->audit->log($user->id, 'vendor_company_request', Company::class, $company->id, [
            'vendor_id' => $vendor->id,
        ]);

        return response()->json([
            'message' => "Access request sent to {$company->name}. Waiting for approval.",
        ]);
    }

    public function myCompanies(Request $request, Vendor $vendor): JsonResponse
    {
        $user = $request->user();

        if (! $user->isSuperAdmin() && ! ($user->isVendorAdmin() && $user->vendor_id === $vendor->id)) {
            abort(403, 'Only Vendor Admin can view company relationships.');
        }

        $companies = $vendor->companies()
            ->withPivot(['status', 'approved_at', 'rejection_reason'])
            ->get();

        return response()->json($companies);
    }

    // ── All companies with this vendor's request status ────────────────────────

    public function availableCompanies(Request $request, Vendor $vendor): JsonResponse
    {
        $user = $request->user();

        if (! $user->isSuperAdmin() && ! ($user->isVendorAdmin() && $user->vendor_id === $vendor->id)) {
            abort(403, 'Only Vendor Admin can view available companies.');
        }

        $existing = $vendor->companies()
            ->withPivot(['status', 'approved_at', 'rejection_reason'])
            ->get()
            ->keyBy('id');

        $allCompanies = Company::where('status', 'active')->orderBy('name')->get();

        $data = $allCompanies->map(function ($company) use ($existing) {
            $rel = $existing->get($company->id);
            return [
                'id'               => $company->id,
                'name'             => $company->name,
                'code'             => $company->code,
                'city'             => $company->city,
                'state'            => $company->state,
                'contact_person'   => $company->contact_person,
                'request_status'   => $rel?->pivot->status,
                'approved_at'      => $rel?->pivot->approved_at,
                'rejection_reason' => $rel?->pivot->rejection_reason,
            ];
        });

        return response()->json($data);
    }

    /** CSV export of vendors visible to the caller (bulk_import_export). */
    public function export(\Illuminate\Http\Request $request)
    {
        $user = $request->user();
        abort_unless(\App\Services\PlanService::userHasFeature($user, 'bulk_import_export'), 403,
            'Bulk export is a Professional/Enterprise feature.');
        $q = \App\Models\Vendor::query();
        if ($user->isCompanyUser()) {
            $q->whereHas('companies', fn ($c) => $c->where('companies.id', $user->company_id));
        }
        $rows = $q->orderBy('name')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Name', 'Code', 'Contact person', 'Email', 'Phone', 'City', 'Status', 'Plan']);
            foreach ($rows as $v) {
                fputcsv($out, [$v->name, $v->code, $v->contact_person, $v->contact_email,
                    $v->contact_phone, $v->city, $v->status, $v->plan]);
            }
            fclose($out);
        }, 'truecrew-vendors-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Org notification settings (e.g. WhatsApp opt-in). Vendor admin only. */
    public function saveSettings(\Illuminate\Http\Request $request, \App\Models\Vendor $vendor): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || ($user->role === 'vendor_admin' && $user->vendor_id === $vendor->id), 403);
        $data = $request->validate(['whatsapp_enabled' => 'required|boolean']);
        if ($data['whatsapp_enabled'] && ! \App\Services\PlanService::hasFeature($vendor->plan ?? 'trial', 'whatsapp_notifications')) {
            return response()->json(['message' => 'WhatsApp notifications are an Enterprise feature.'], 403);
        }
        $vendor->forceFill(['settings' => array_merge((array) ($vendor->settings ?? []), $data)])->save();

        return response()->json(['message' => 'Settings saved.', 'settings' => $vendor->settings]);
    }
}
