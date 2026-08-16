<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $this->audit->log(null, 'login_failed', null, null, [
                'email'      => $data['email'],
                'ip_address' => $request->ip(),
            ]);
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Account is disabled. Contact administrator.'], 403);
        }

        $token = $user->createToken('auth_token', ['*'], now()->addDays(7))->plainTextToken;

        $this->audit->log($user->id, 'login', null, null, ['ip' => $request->ip()]);

        return response()->json([
            'token' => $token,
            'user'  => $this->userResponse($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit->log($request->user()->id, 'logout');
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['company', 'vendor']);
        return response()->json($this->userResponse($user));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password'         => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->update(['password' => $data['password']]);
        $this->audit->log($user->id, 'password_changed');

        return response()->json(['message' => 'Password changed successfully.']);
    }

    private function userResponse(User $user): array
    {
        return [
            'id'            => $user->id,
            'name'          => $user->name,
            'email'         => $user->email,
            'role'          => $user->role,
            'company_id'    => $user->company_id,
            'vendor_id'     => $user->vendor_id,
            'company'       => $user->company ? ['id' => $user->company->id, 'name' => $user->company->name] : null,
            'vendor'        => $user->vendor ? ['id' => $user->vendor->id, 'name' => $user->vendor->name] : null,
            'is_active'     => $user->is_active,
            'location_type' => $user->location_type,
            'location_name' => $user->location_name,
        ];
    }

    /**
     * Self-service password reset — step 1: send the reset link.
     * Works with any SMTP configured via MAIL_* env. On the demo (mailer=log)
     * the link is ALSO returned in the response so the flow is fully usable
     * without an email provider — strictly gated to the log mailer.
     */
    public function forgotPassword(\Illuminate\Http\Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $user = \App\Models\User::where('email', $request->email)->first();

        // Same response whether or not the account exists (no user enumeration).
        $generic = ['message' => 'If that email is registered, a reset link has been sent.'];
        if (! $user) {
            return response()->json($generic);
        }

        $token = \Illuminate\Support\Facades\Password::broker()->createToken($user);
        $url   = rtrim(config('app.url'), '/')
               . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);

        try {
            \Illuminate\Support\Facades\Mail::raw(
                "Hello {$user->name},\n\nReset your TrueCrew password using this link (valid 60 minutes):\n{$url}\n\nIf you didn't request this, ignore this email.",
                fn ($m) => $m->to($user->email)->subject('TrueCrew — password reset')
            );
        } catch (\Throwable $e) {
            report($e);
        }

        // Dev/demo convenience ONLY: returning the link publicly would let
        // anyone take over any account, so require debug mode too.
        if (config('mail.default') === 'log' && config('app.debug')) {
            // Demo/dev convenience only — real deployments configure SMTP.
            $generic['dev_reset_url'] = $url;
            $generic['note'] = 'Demo mode (no SMTP configured): use this link directly.';
        }
        return response()->json($generic);
    }

    /** Self-service password reset — step 2: set the new password. */
    public function resetPassword(\Illuminate\Http\Request $request)
    {
        $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)->letters()->numbers()],
        ]);

        $status = \Illuminate\Support\Facades\Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill(['password' => \Illuminate\Support\Facades\Hash::make($password)])->save();
                $user->tokens()->delete(); // revoke all sessions
            }
        );

        if ($status !== \Illuminate\Support\Facades\Password::PASSWORD_RESET) {
            return response()->json(['message' => 'This reset link is invalid or has expired — request a new one.'], 422);
        }
        return response()->json(['message' => 'Password updated — sign in with your new password.']);
    }
}
