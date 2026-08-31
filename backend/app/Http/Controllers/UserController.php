<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $auth = $request->user();

        $users = User::select(['id','name','email','role','company_id','vendor_id','phone','is_active','location_type','location_name','created_at'])
            ->with(['company:id,name', 'vendor:id,name'])
            // company_admin sees only their own gate users
            ->when($auth->role === 'company_admin', fn($q) =>
                $q->where('company_id', $auth->company_id)->whereIn('role', ['company_gate', 'company_hr'])
            )
            // vendor_admin sees only their own operators
            ->when($auth->role === 'vendor_admin', fn($q) =>
                $q->where('vendor_id', $auth->vendor_id)->where('role', 'vendor_operator')
            )
            // super_admin can filter freely
            ->when($auth->isSuperAdmin() && $request->role,       fn($q, $r)  => $q->where('role', $r))
            ->when($auth->isSuperAdmin() && $request->company_id, fn($q, $id) => $q->where('company_id', $id))
            ->when($auth->isSuperAdmin() && $request->vendor_id,  fn($q, $id) => $q->where('vendor_id', $id))
            ->orderBy('name')
            ->paginate(30);

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $auth = $request->user();

        if ($auth->role === 'company_admin') {
            // Company admins create gate users for their company — and may also
            // create the ADMIN LOGIN for a vendor that is approved for (or was
            // created by) their company.
            if ($request->input('role') === 'vendor_admin') {
                $data = $request->validate([
                    'name'      => 'required|string|max:100',
                    'email'     => 'required|email|unique:users',
                    'password'  => ['required', Password::min(8)->letters()->numbers()],
                    'phone'     => 'nullable|string|max:15',
                    'vendor_id' => 'required|integer|exists:vendors,id',
                ]);
                $approved = \App\Models\Company::find($auth->company_id)
                    ?->vendors()->where('vendors.id', $data['vendor_id'])
                    ->wherePivot('status', 'approved')->exists();
                if (! $approved) {
                    return response()->json([
                        'message' => 'That vendor is not approved for your company.',
                    ], 403);
                }
                if ($deny = \App\Services\PlanService::deny(\App\Services\PlanService::ctx('vendor', $data['vendor_id']), 'users')) {
                    return response()->json($deny, 403);
                }
                $data['role']      = 'vendor_admin';
                $data['is_active'] = true;
                $user = User::create($data);
                $this->audit->log($auth->id, 'user_created', User::class, $user->id);
            $this->sendWelcome($user);
                return response()->json($user, 201);
            }

            $data = $request->validate([
                'name'          => 'required|string|max:100',
                'email'         => 'required|email|unique:users',
                'password'      => ['required', Password::min(8)->letters()->numbers()],
                'phone'         => 'nullable|string|max:15',
                'role'          => 'nullable|in:company_gate,company_hr',
                'location_type' => 'nullable|in:main_gate,department,checkpoint',
                'location_name' => 'nullable|string|max:100',
            ]);
            if ($deny = \App\Services\PlanService::deny(\App\Services\PlanService::ctx('company', $auth->company_id), 'users')) {
                return response()->json($deny, 403);
            }
            // Gate users mark attendance at their location; HR users review
            // vendor deployments (approve/reject + department permissions).
            $data['role']       = $request->input('role', 'company_gate');
            $data['company_id'] = $auth->company_id;
            $data['is_active']  = true;
            $user = User::create($data);
            $this->audit->log($auth->id, 'user_created', User::class, $user->id);
            $this->sendWelcome($user);
            return response()->json($user, 201);
        }

        if ($auth->role === 'vendor_admin') {
            $data = $request->validate([
                'name'     => 'required|string|max:100',
                'email'    => 'required|email|unique:users',
                'password' => ['required', Password::min(8)->letters()->numbers()],
                'phone'    => 'nullable|string|max:15',
            ]);
            if ($deny = \App\Services\PlanService::deny(\App\Services\PlanService::ctx('vendor', $auth->vendor_id), 'users')) {
                return response()->json($deny, 403);
            }
            $data['role']      = 'vendor_operator';
            $data['vendor_id'] = $auth->vendor_id;
            $data['is_active'] = true;
            $user = User::create($data);
            $this->audit->log($auth->id, 'user_created', User::class, $user->id);
            $this->sendWelcome($user);
            return response()->json($user, 201);
        }

        $data = $request->validate([
            'name'          => 'required|string|max:100',
            'email'         => 'required|email|unique:users',
            'password'      => ['required', Password::min(8)->letters()->numbers()],
            'role'          => 'required|in:' . implode(',', User::ROLES),
            'company_id'    => 'nullable|integer|exists:companies,id',
            'vendor_id'     => 'nullable|integer|exists:vendors,id',
            'phone'         => 'nullable|string|max:15',
            'location_type' => 'nullable|in:main_gate,department,checkpoint',
            'location_name' => 'nullable|string|max:100',
        ]);

        // Super admin path: still respect the target org's plan (super admin
        // can always bump the org's plan on the Subscriptions page first).
        if (! empty($data['company_id'])) {
            if ($deny = \App\Services\PlanService::deny(\App\Services\PlanService::ctx('company', $data['company_id']), 'users')) {
                return response()->json($deny, 403);
            }
        } elseif (! empty($data['vendor_id'])) {
            if ($deny = \App\Services\PlanService::deny(\App\Services\PlanService::ctx('vendor', $data['vendor_id']), 'users')) {
                return response()->json($deny, 403);
            }
        }

        $data['is_active'] = true;
        $user = User::create($data);

        $this->audit->log($auth->id, 'user_created', User::class, $user->id);
            $this->sendWelcome($user);

        return response()->json($user, 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        if (! $actor->isSuperAdmin()) {
            if ($actor->isCompanyUser()) {
                abort_unless($user->company_id === $actor->company_id, 403, 'Access denied.');
            } elseif ($actor->isVendorUser()) {
                abort_unless($user->vendor_id === $actor->vendor_id, 403, 'Access denied.');
            } else {
                abort(403, 'Access denied.');
            }
        }
        return response()->json($user->load(['company:id,name', 'vendor:id,name']));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $auth = $request->user();

        if ($auth->role === 'company_admin') {
            if ($user->company_id !== $auth->company_id || ! in_array($user->role, ['company_gate', 'company_hr'], true)) {
                abort(403, 'You can only edit gate users for your own company.');
            }

            $data = $request->validate([
                'name'          => 'sometimes|string|max:100',
                'email'         => "sometimes|email|unique:users,email,{$user->id}",
                'phone'         => 'nullable|string|max:15',
                'is_active'     => 'sometimes|boolean',
                'location_type' => 'nullable|in:main_gate,department,checkpoint',
                'location_name' => 'nullable|string|max:100',
            ]);
            $user->update($data);
            $this->audit->log($auth->id, 'user_updated', User::class, $user->id);
            return response()->json($user->fresh());
        }

        if ($auth->role === 'vendor_admin') {
            if ($user->vendor_id !== $auth->vendor_id || $user->role !== 'vendor_operator') {
                abort(403, 'You can only edit operators for your own vendor.');
            }

            $data = $request->validate([
                'name'      => 'sometimes|string|max:100',
                'email'     => "sometimes|email|unique:users,email,{$user->id}",
                'phone'     => 'nullable|string|max:15',
                'is_active' => 'sometimes|boolean',
            ]);
            $user->update($data);
            $this->audit->log($auth->id, 'user_updated', User::class, $user->id);
            return response()->json($user->fresh());
        }

        $data = $request->validate([
            'name'          => 'sometimes|string|max:100',
            'email'         => "sometimes|email|unique:users,email,{$user->id}",
            'role'          => 'sometimes|in:' . implode(',', User::ROLES),
            'company_id'    => 'nullable|integer|exists:companies,id',
            'vendor_id'     => 'nullable|integer|exists:vendors,id',
            'phone'         => 'nullable|string|max:15',
            'is_active'     => 'sometimes|boolean',
            'location_type' => 'nullable|in:main_gate,department,checkpoint',
            'location_name' => 'nullable|string|max:100',
        ]);

        $user->update($data);
        $this->audit->log($auth->id, 'user_updated', User::class, $user->id);

        return response()->json($user->fresh());
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $auth = $request->user();

        if ($auth->id === $user->id) {
            return response()->json(['message' => 'Cannot delete your own account.'], 422);
        }

        if ($auth->role === 'company_admin' && ($user->company_id !== $auth->company_id || ! in_array($user->role, ['company_gate', 'company_hr'], true))) {
            abort(403, 'You can only delete gate users for your own company.');
        }

        if ($auth->role === 'vendor_admin' && ($user->vendor_id !== $auth->vendor_id || $user->role !== 'vendor_operator')) {
            abort(403, 'You can only delete operators for your own vendor.');
        }

        $user->delete();
        $this->audit->log($auth->id, 'user_deleted', User::class, $user->id);

        return response()->json(['message' => 'User deleted.']);
    }

    /** Templated welcome mail + in-app hello for a freshly created login. */
    private function sendWelcome(User $user): void
    {
        $notify = app(\App\Services\NotifyService::class);
        $notify->inApp([$user], 'welcome', 'Welcome to TrueCrew!',
            'Your login is ready — explore your dashboard to get started.');
        $ctx = \App\Services\PlanService::orgFor($user);
        $notify->email($user->email, 'welcome_user', [
            'name'      => $user->name,
            'email'     => $user->email,
            'role'      => $user->role,
            'login_url' => rtrim(config('app.url'), '/'),
            'org_name'  => $ctx ? $ctx['org']->name : 'TrueCrew',
        ], $ctx['type'] ?? null, $ctx ? $ctx['org']->id : null, $ctx ? ($ctx['org']->plan ?? 'trial') : null);
    }
}
