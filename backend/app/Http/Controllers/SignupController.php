<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PlanUpgradeRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Public self-service signup (SaaS): anyone can register as a Company or a
 * Vendor. Minimal mandatory fields (org name, your name, email, password);
 * legal identifiers (GST/PAN) are optional for now. New orgs start on the
 * TRIAL plan; choosing a paid plan at signup files an upgrade request that
 * the super admin settles (offline payment).
 */
class SignupController extends Controller
{
    public function __construct(private AuditService $audit)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'org_type'   => 'required|in:company,vendor',
            'org_name'   => 'required|string|min:3|max:120',
            'name'       => 'required|string|max:100',
            'email'      => 'required|email|unique:users,email',
            'password'   => ['required', Password::min(8)->letters()->numbers()],
            'phone'      => 'nullable|string|max:20',
            'address'    => 'nullable|string|max:500',
            'city'       => 'nullable|string|max:60',
            'state'      => 'nullable|string|max:60',
            'pin'        => 'nullable|string|max:6',
            'gst_number' => 'nullable|string|max:15',
            'pan_number' => 'nullable|string|max:10',
            'plan'       => 'nullable|in:trial,professional,enterprise',
        ]);

        $isCompany = $data['org_type'] === 'company';

        // Org names must be unique within their type (friendly early check).
        $nameTaken = $isCompany
            ? Company::where('name', $data['org_name'])->exists()
            : Vendor::where('name', $data['org_name'])->exists();
        if ($nameTaken) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => ['org_name' => ['An organisation with this name already exists.']],
            ], 422);
        }

        // vendors.contact_email carries a DB UNIQUE index — pre-check so a
        // duplicate gives a clean 422 instead of a SQL 500.
        if (! $isCompany && Vendor::withTrashed()->where('contact_email', $data['email'])->exists()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => ['email' => ['A vendor with this contact email already exists.']],
            ], 422);
        }

        $orgFields = [
            'name'           => $data['org_name'],
            'code'           => $this->uniqueCode($isCompany ? 'CMP' : 'VND', $isCompany ? Company::class : Vendor::class),
            'address'        => $data['address'] ?? '',
            'city'           => $data['city'] ?? null,
            'state'          => $data['state'] ?? null,
            'pin'            => $data['pin'] ?? null,
            'contact_person' => $data['name'],
            'contact_email'  => $data['email'],
            'contact_phone'  => $data['phone'] ?? '',
            'gst_number'     => $data['gst_number'] ?? null,
            'status'         => 'active',
        ];
        if (! $isCompany) {
            $orgFields['pan_number'] = $data['pan_number'] ?? null;
        }

        $org = $isCompany ? Company::create($orgFields) : Vendor::create($orgFields);
        $org->forceFill(['plan' => 'trial', 'plan_started_at' => now()])->save();

        $user = User::create([
            'name'       => $data['name'],
            'email'      => $data['email'],
            'password'   => Hash::make($data['password']),
            'role'       => $isCompany ? 'company_admin' : 'vendor_admin',
            'company_id' => $isCompany ? $org->id : null,
            'vendor_id'  => $isCompany ? null : $org->id,
            'is_active'  => true,
        ]);

        // Paid plan chosen at signup → file the upgrade request (offline payment).
        $requestedPaid = in_array($data['plan'] ?? 'trial', ['professional', 'enterprise'], true);
        if ($requestedPaid) {
            PlanUpgradeRequest::create([
                'org_type'       => $data['org_type'],
                'org_id'         => $org->id,
                'current_plan'   => 'trial',
                'requested_plan' => $data['plan'],
                'requested_by'   => $user->id,
                'note'           => 'Requested at signup',
            ]);
        }

        $this->audit->log($user->id, 'org_signup', $isCompany ? Company::class : Vendor::class, $org->id, [
            'org_type' => $data['org_type'],
            'plan'     => $data['plan'] ?? 'trial',
        ]);

        $token = $user->createToken('signup', ['*'], now()->addDays(7))->plainTextToken;

        return response()->json([
            'message' => $requestedPaid
                ? 'Account created on Trial. Your upgrade request was sent — our team will contact you to complete payment.'
                : 'Account created — welcome to AMS!',
            'token'   => $token,
            'user'    => $user->fresh()->load($isCompany ? 'company' : 'vendor'),
        ], 201);
    }

    private function uniqueCode(string $prefix, string $model): string
    {
        do {
            $code = $prefix . '-' . strtoupper(Str::random(5));
        } while ($model::withTrashed()->where('code', $code)->exists());
        return $code;
    }
}
