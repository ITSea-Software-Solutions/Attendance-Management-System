<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\VendorController;
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
// Self-service password reset (demo mode returns the link when mailer=log)
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:signup');
Route::post('/auth/reset-password',  [AuthController::class, 'resetPassword'])->middleware('throttle:login');

// ─── Authenticated ────────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // ── Offline-first client app sync (Flutter Windows/Android) ─────────────
    Route::get('sync/pull',  [SyncController::class, 'pull']);
    Route::post('sync/push', [SyncController::class, 'push']);

    // ── SaaS plans & subscriptions ───────────────────────────────────────────
    Route::get('plan',                  [\App\Http\Controllers\PlanController::class, 'show']);
    Route::post('plan/upgrade-request', [\App\Http\Controllers\PlanController::class, 'requestUpgrade']);
    Route::get('admin/subscriptions',   [\App\Http\Controllers\PlanController::class, 'index']);
    Route::post('admin/subscriptions/set-plan', [\App\Http\Controllers\PlanController::class, 'setPlan']);
    Route::post('admin/plan-requests/{planRequest}/decide', [\App\Http\Controllers\PlanController::class, 'decide']);

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/password', [AuthController::class, 'changePassword']);

    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
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
        Route::post('vendors/{vendor}/approve',  [CompanyController::class, 'approveVendor']);
        Route::post('vendors/{vendor}/reject',   [CompanyController::class, 'rejectVendor']);
        Route::post('vendors/{vendor}/suspend',  [CompanyController::class, 'suspendVendor']);
    });

    // ── Vendors ───────────────────────────────────────────────────────────────
    Route::apiResource('vendors', VendorController::class);
    Route::post('vendors/{vendor}/request-company/{company}', [VendorController::class, 'requestCompany']);
    Route::get('vendors/{vendor}/companies', [VendorController::class, 'myCompanies']);
    Route::get('vendors/{vendor}/available-companies', [VendorController::class, 'availableCompanies']);

    // ── Workers ───────────────────────────────────────────────────────────────
    Route::apiResource('workers', WorkerController::class);
    Route::prefix('workers/{worker}')->group(function () {
        Route::get('stats',          [WorkerController::class, 'stats']);
        Route::get('photo',          [WorkerController::class, 'servePhoto'])->name('worker.photo');
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
    });

    // ── Worker Deployments (Assignments) ──────────────────────────────────────
    Route::apiResource('assignments', WorkerAssignmentController::class);
    Route::get('assignments/company/{company}/today', [WorkerAssignmentController::class, 'todayForCompany']);
    Route::get('assignments/worker/{worker}',         [WorkerAssignmentController::class, 'forWorker']);

    // ── Attendance ────────────────────────────────────────────────────────────
    Route::prefix('attendance')->group(function () {
        Route::get('/',               [AttendanceController::class, 'index']);
        Route::get('daily-summary',   [AttendanceController::class, 'dailySummary']);
        Route::get('worker-templates', [AttendanceController::class, 'workerTemplates']); // deployed workers (no templates)
        Route::get('assigned-workers', [AttendanceController::class, 'assignedWorkers']); // photo/manual
        Route::post('identify',       [AttendanceController::class, 'identify']);          // server-side 1:N fingerprint match
        Route::post('identify-face',  [AttendanceController::class, 'identifyFace']);      // server-side 1:N camera face match
        Route::post('mark',           [AttendanceController::class, 'mark']);
        Route::get('proof/{log}',     [AttendanceController::class, 'proofPhoto']);       // serve proof image
        Route::get('today',           [AttendanceController::class, 'today']);
        Route::get('worker/{worker}', [AttendanceController::class, 'workerHistory']);
        Route::get('report',          [AttendanceController::class, 'report']);
        Route::get('exceptions',      [AttendanceController::class, 'exceptions']);
        Route::get('export',          [AttendanceController::class, 'export']);      // CSV: ?month=YYYY-MM&type=daily|monthly
        Route::get('printable',       [AttendanceController::class, 'printable']);   // print-friendly month report
    });
});
