<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\PanController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\WorkerController;
use App\Http\Controllers\WorkerAssignmentController;
use App\Http\Controllers\WorkerIdDocumentController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AadhaarController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\UserController;

// ─── Public ───────────────────────────────────────────────────────────────────
// Named limiters (AppServiceProvider): login 5/min, signup 3/10min — separate
// buckets per IP so one can't starve the other.
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
// SaaS self-service signup (company or vendor; starts on Trial)
Route::post('/signup', [\App\Http\Controllers\SignupController::class, 'store'])->middleware('throttle:signup');
Route::get('/plans-public', fn () => response()->json([
    'plans'          => config('plans.plans'),
    'feature_labels' => config('plans.feature_labels'),
]));
// Self-service password reset (demo mode returns the link when mailer=log)
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:signup');
Route::post('/auth/reset-password',  [AuthController::class, 'resetPassword'])->middleware('throttle:login');

// Host approval by link — the token IS the credential, so these are throttled.
Route::middleware('throttle:30,1')->group(function () {
    Route::get('visitor-pass/{token}',        [\App\Http\Controllers\VisitorController::class, 'publicPass']);
    Route::get('visitor-pass/{token}/photo',  [\App\Http\Controllers\VisitorController::class, 'publicPassPhoto']);
    Route::post('visitor-pass/{token}/decide',[\App\Http\Controllers\VisitorController::class, 'publicDecide']);
});

// WhatsApp inbound webhook (Meta Cloud API) — host YES/NO gate-pass replies
Route::get('/whatsapp/webhook',  [\App\Http\Controllers\VisitorController::class, 'webhookVerify']);
Route::post('/whatsapp/webhook', [\App\Http\Controllers\VisitorController::class, 'webhookReceive']);

// ─── Authenticated ────────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // ── Offline-first client app sync (Flutter Windows/Android) ─────────────
    Route::get('sync/pull',  [SyncController::class, 'pull']);
    Route::post('sync/push', [SyncController::class, 'push']);

    // ── SaaS plans & subscriptions ───────────────────────────────────────────
    Route::get('plan',                  [\App\Http\Controllers\PlanController::class, 'show']);
    Route::post('plan/upgrade-request', [\App\Http\Controllers\PlanController::class, 'requestUpgrade']);
    Route::post('plan/requests/{planRequest}/payment', [\App\Http\Controllers\PlanController::class, 'recordPayment']);
    Route::post('plan/requests/{planRequest}/razorpay-order',  [\App\Http\Controllers\PlanController::class, 'razorpayOrder']);
    Route::post('plan/requests/{planRequest}/razorpay-verify', [\App\Http\Controllers\PlanController::class, 'razorpayVerify']);
    Route::get('plan/requests/{planRequest}/proof',    [\App\Http\Controllers\PlanController::class, 'paymentProof']);
    Route::get('admin/subscriptions',   [\App\Http\Controllers\PlanController::class, 'index']);
    Route::post('admin/subscriptions/set-plan', [\App\Http\Controllers\PlanController::class, 'setPlan']);
    Route::post('admin/plan-requests/{planRequest}/decide', [\App\Http\Controllers\PlanController::class, 'decide']);

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/password', [AuthController::class, 'changePassword']);

    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/overview', [DashboardController::class, 'overview']);
    Route::get('/dashboard/today-attendance', [DashboardController::class, 'todayAttendance']);
    Route::get('/dashboard/recent-activity', [DashboardController::class, 'recentActivity']);

    // ── Users ─────────────────────────────────────────────────────────────────
    Route::middleware('role:super_admin,company_admin,vendor_admin')->group(function () {
        Route::apiResource('users', UserController::class);
    });

    // ── Companies ─────────────────────────────────────────────────────────────
    Route::apiResource('companies', CompanyController::class);
    Route::prefix('companies/{company}')->group(function () {
        Route::get('vendors', [CompanyController::class, 'vendors']);
        Route::get('vendors/{vendor}/detail', [CompanyController::class, 'vendorDetail']);
        Route::post('vendors/{vendor}/approve',  [CompanyController::class, 'approveVendor']);
        Route::post('vendors/{vendor}/reject',   [CompanyController::class, 'rejectVendor']);
        Route::post('vendors/{vendor}/suspend',  [CompanyController::class, 'suspendVendor']);
    });

    // ── Vendors ───────────────────────────────────────────────────────────────
    Route::apiResource('vendors', VendorController::class);
    Route::post('vendors/{vendor}/request-company/{company}', [VendorController::class, 'requestCompany']);
    Route::get('vendors/{vendor}/companies', [VendorController::class, 'myCompanies']);
    Route::get('vendors/{vendor}/available-companies', [VendorController::class, 'availableCompanies']);

    // ── Bulk import/export + org settings (plan-gated: bulk_import_export) ───
    Route::get('workers-export',  [WorkerController::class, 'export']);
    Route::post('workers-import', [WorkerController::class, 'import']);
    Route::get('vendors-export',  [VendorController::class, 'export']);
    Route::put('vendors/{vendor}/settings', [VendorController::class, 'saveSettings']);

    // ── Notification center + editable templates ─────────────────────────────
    Route::get('notifications',       [NotificationController::class, 'index']);
    Route::post('notifications/read', [NotificationController::class, 'markRead']);
    Route::get('templates',           [NotificationController::class, 'templates']);
    Route::post('templates',          [NotificationController::class, 'saveTemplate']);
    Route::post('templates/reset',    [NotificationController::class, 'resetTemplate']);

    // ── Workers ───────────────────────────────────────────────────────────────
    // Compact picker list — MUST precede the resource so /workers/options
    // is not swallowed by /workers/{worker}.
    Route::get('workers-options', [WorkerController::class, 'options']);
    Route::apiResource('workers', WorkerController::class);
    Route::prefix('workers/{worker}')->group(function () {
        Route::get('stats',          [WorkerController::class, 'stats']);
        Route::get('photo',          [WorkerController::class, 'servePhoto'])->name('worker.photo');
        Route::post('verify-step',   [WorkerController::class, 'verifyStep']);
        Route::post('send-otp',      [WorkerController::class, 'sendPhoneOtp']);
        Route::post('verify-otp',    [WorkerController::class, 'verifyPhoneOtp']);
        Route::post('aadhaar-photo', [WorkerController::class, 'uploadAadhaarPhoto']);
        Route::get('aadhaar-photo',  [WorkerController::class, 'serveAadhaarPhoto']);
        Route::post('fingerprint',   [WorkerController::class, 'storeFingerprint']);
        Route::delete('fingerprint', [WorkerController::class, 'deleteFingerprint']);
        Route::post('photo',         [WorkerController::class, 'uploadPhoto']);
        Route::post('activate',      [WorkerController::class, 'activate']);
        Route::post('deactivate',    [WorkerController::class, 'deactivate']);

        // ID documents (PAN, Aadhaar, Driving Licence, etc.)
        Route::get('id-documents',                    [WorkerIdDocumentController::class, 'index']);
        Route::post('id-documents',                   [WorkerIdDocumentController::class, 'store']);
        Route::get('id-documents/{document}/download',[WorkerIdDocumentController::class, 'download']);
        Route::delete('id-documents/{document}',      [WorkerIdDocumentController::class, 'destroy']);
    });

    // ── Aadhaar ───────────────────────────────────────────────────────────────
    Route::prefix('aadhaar')->group(function () {
        Route::post('extract',          [AadhaarController::class, 'extract']);
        Route::post('upload/{worker}',  [AadhaarController::class, 'upload']);
        Route::get('download/{worker}', [AadhaarController::class, 'download']);
        Route::post('face-verify',      [AadhaarController::class, 'verifyFace']);
    });

    // ── Worker Deployments (Assignments) ──────────────────────────────────────
    Route::get('assignments-pending',  [WorkerAssignmentController::class, 'pending']);
    Route::post('assignments-approve', [WorkerAssignmentController::class, 'approve']);
    Route::post('assignments/{assignment}/reject', [WorkerAssignmentController::class, 'reject']);
    Route::get('companies/{company}/locations', [WorkerAssignmentController::class, 'companyLocations']);
    Route::put('companies/{company}/settings',  [CompanyController::class, 'saveSettings']);
    Route::apiResource('assignments', WorkerAssignmentController::class);
    Route::get('assignments/company/{company}/today', [WorkerAssignmentController::class, 'todayForCompany']);
    Route::get('assignments/worker/{worker}',         [WorkerAssignmentController::class, 'forWorker']);

    // ── Visitors / Gate passes ────────────────────────────────────────────────
    Route::get('visitor-hosts',            [\App\Http\Controllers\VisitorController::class, 'hosts']);
    Route::post('visitor-hosts',           [\App\Http\Controllers\VisitorController::class, 'storeHost']);
    Route::put('visitor-hosts/{host}',     [\App\Http\Controllers\VisitorController::class, 'updateHost']);
    Route::delete('visitor-hosts/{host}',  [\App\Http\Controllers\VisitorController::class, 'destroyHost']);
    Route::get('gate-passes',              [\App\Http\Controllers\VisitorController::class, 'passes']);
    Route::post('gate-passes',             [\App\Http\Controllers\VisitorController::class, 'storePass']);
    Route::post('gate-passes/{pass}/decide', [\App\Http\Controllers\VisitorController::class, 'decidePass']);
    Route::post('gate-passes/{pass}/move',   [\App\Http\Controllers\VisitorController::class, 'movePass']);
    Route::get('gate-passes/{pass}/photo',   [\App\Http\Controllers\VisitorController::class, 'passPhoto']);

    // ── Attendance ────────────────────────────────────────────────────────────
    Route::prefix('attendance')->group(function () {
        Route::get('/',               [AttendanceController::class, 'index']);
        Route::get('daily-summary',   [AttendanceController::class, 'dailySummary']);
        Route::get('worker-templates', [AttendanceController::class, 'workerTemplates']); // deployed workers (no templates)
        Route::get('assigned-workers', [AttendanceController::class, 'assignedWorkers']); // photo/manual
        Route::post('identify',       [AttendanceController::class, 'identify']);          // server-side 1:N fingerprint match
        Route::post('identify-face',  [AttendanceController::class, 'identifyFace']);      // server-side 1:N camera face match
        Route::post('mark',           [AttendanceController::class, 'mark']);
        Route::post('{log}/proof',    [AttendanceController::class, 'uploadProof']);
        Route::post('manual-out',     [AttendanceController::class, 'manualOut']);
        Route::get('proof/{log}',     [AttendanceController::class, 'proofPhoto']);       // serve proof image
        Route::get('today',           [AttendanceController::class, 'today']);
        Route::get('worker/{worker}', [AttendanceController::class, 'workerHistory']);
        Route::get('report',          [AttendanceController::class, 'report']);
        Route::get('exceptions',      [AttendanceController::class, 'exceptions']);
        Route::get('live-board',      [AttendanceController::class, 'liveBoard']);   // glanceable who-is-where
        Route::get('export',          [AttendanceController::class, 'export']);      // CSV: ?month= | ?from=&to= &type=daily|monthly
        Route::get('printable',       [AttendanceController::class, 'printable']);   // print-friendly period report
        // Hours / wage-day report: ?from=&to= (or ?month=) &group=daily|weekly|monthly|summary
        Route::get('hours-report',    [AttendanceController::class, 'hoursReport']);
    });

    // ── PAN card identity (alternative to Aadhaar at registration) ───────────
    Route::post('pan/extract',            [PanController::class, 'extract']);
    Route::post('pan/upload/{worker}',    [PanController::class, 'upload']);
    Route::get('pan/download/{worker}',   [PanController::class, 'download']);

    // ── Payroll / wage register ───────────────────────────────────────────────
    Route::prefix('payroll')->group(function () {
        Route::get('components',          [PayrollController::class, 'componentsCatalogue']);
        Route::get('register',            [PayrollController::class, 'register']);
        Route::get('register-export',     [PayrollController::class, 'registerExport']);
        Route::get('muster',              [PayrollController::class, 'muster']);
        Route::get('contractor-summary',  [PayrollController::class, 'contractorSummary']);
        Route::post('rates',              [PayrollController::class, 'saveRates']);
        Route::post('adjustments',        [PayrollController::class, 'storeAdjustment']);
        Route::delete('adjustments/{id}', [PayrollController::class, 'deleteAdjustment']);
        Route::post('overrides',          [PayrollController::class, 'storeOverride']);
        Route::get('holidays',            [PayrollController::class, 'holidays']);
        Route::post('holidays',           [PayrollController::class, 'storeHoliday']);
        Route::delete('holidays/{id}',    [PayrollController::class, 'deleteHoliday']);
    });
});
