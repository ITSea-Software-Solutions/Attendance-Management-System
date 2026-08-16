<?php

namespace App\Http\Controllers;

use App\Models\Worker;
use App\Services\AadhaarService;
use App\Services\AuditService;
use App\Services\FaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AadhaarController extends Controller
{
    public function __construct(
        private AadhaarService $aadhaar,
        private AuditService $audit,
    ) {}

    /**
     * Upload Aadhaar PDF, extract data, return structured fields.
     * PDF is NOT stored at this stage; it's only processed.
     */
    public function extract(Request $request): JsonResponse
    {
        $request->validate([
            'pdf'      => 'required|file|mimes:pdf|max:10240',
            'password' => 'nullable|string|max:60',
        ]);

        $file     = $request->file('pdf');
        $password = $request->input('password');

        $result = $this->aadhaar->extractFromPdf($file->getRealPath(), $password);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'code'    => $result['code'] ?? 'EXTRACT_FAILED',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data'    => $result['data'],
        ]);
    }

    /**
     * Securely store the uploaded Aadhaar PDF linked to a worker.
     */
    public function upload(Request $request, Worker $worker): JsonResponse
    {
        $this->authorizeVendorAccess($request->user(), $worker);

        $request->validate([
            'pdf'                  => 'required|file|mimes:pdf|max:10240',
            'aadhaar_number_masked' => 'required|string|max:20',
        ]);

        // Delete old PDF if it exists
        if ($worker->aadhaar_pdf_path) {
            Storage::disk('private')->delete($worker->aadhaar_pdf_path);
        }

        $file = $request->file('pdf');

        // Store with a non-guessable filename in private disk
        $path = Storage::disk('private')->putFileAs(
            'aadhaar',
            $file,
            'aadhaar_' . $worker->id . '_' . now()->timestamp . '.pdf'
        );

        $worker->forceFill([
            'aadhaar_pdf_path'      => $path,
            'aadhaar_number_masked' => $request->input('aadhaar_number_masked'),
        ])->save();

        // Activate worker if fingerprint is also done
        if ($worker->hasFingerprint()) {
            $worker->forceFill(['status' => Worker::STATUS_ACTIVE])->save();
        }

        $this->audit->log($request->user()->id, 'aadhaar_uploaded', Worker::class, $worker->id, [
            'masked' => $request->input('aadhaar_number_masked'),
        ]);

        return response()->json([
            'message' => 'Aadhaar PDF stored securely.',
            'status'  => $worker->fresh()->status,
        ]);
    }

    /**
     * Authorized secure download of stored Aadhaar PDF.
     */
    public function download(Request $request, Worker $worker): \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
    {
        $user = $request->user();

        if ($user->isCompanyUser()) {
            $related = $worker->assignments()->where('company_id', $user->company_id)->exists()
                || \App\Models\AttendanceLog::where('worker_id', $worker->id)
                       ->where('company_id', $user->company_id)->exists();
            if (! $related) {
                return response()->json(['message' => 'Access denied.'], 403);
            }
        } elseif (! $user->isSuperAdmin() && ! ($user->isVendorUser() && $user->vendor_id === $worker->vendor_id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if (! $worker->aadhaar_pdf_path || ! Storage::disk('private')->exists($worker->aadhaar_pdf_path)) {
            return response()->json(['message' => 'Aadhaar PDF not found.'], 404);
        }

        $this->audit->log($user->id, 'aadhaar_downloaded', Worker::class, $worker->id);

        return Storage::disk('private')->download(
            $worker->aadhaar_pdf_path,
            "aadhaar_{$worker->id}.pdf"
        );
    }

    private function authorizeVendorAccess($user, Worker $worker): void
    {
        if ($user->isSuperAdmin()) return;

        if ($user->isVendorUser() && $user->vendor_id !== $worker->vendor_id) {
            abort(403, 'Access denied.');
        }

        if ($user->isCompanyUser()) {
            abort(403, 'Company users cannot upload Aadhaar documents.');
        }
    }

    /**
     * Enrollment-time identity check: compare the photo INSIDE the Aadhaar
     * PDF (from /aadhaar/extract) with the live camera photo. Advisory —
     * Aadhaar photos are often years old, so a low score warns, not blocks.
     */
    public function verifyFace(Request $request, FaceService $faces): JsonResponse
    {
        $request->validate([
            'aadhaar_photo_base64' => 'required|string',
            'live_photo'           => 'required|file|mimes:jpeg,png,jpg|max:8192',
        ]);
        $aadhaarBytes = base64_decode($request->input('aadhaar_photo_base64'), true);
        if ($aadhaarBytes === false || strlen($aadhaarBytes) < 100) {
            return response()->json(['message' => 'Invalid Aadhaar photo data.'], 422);
        }
        $ea = $faces->embed($aadhaarBytes, 'aadhaar.png');
        $el = $faces->embed(
            (string) file_get_contents($request->file('live_photo')->getRealPath()), 'live.jpg');
        if (! $ea || ! $el) {
            return response()->json([
                'similarity' => null,
                'match'      => null,
                'message'    => ! $ea
                    ? 'No face detected in the Aadhaar photo.'
                    : 'No face detected in the live photo.',
            ]);
        }
        $sim = FaceService::cosine($ea, $el);

        return response()->json([
            'similarity' => round($sim, 3),
            'match'      => $sim >= $faces->threshold(),
            'threshold'  => $faces->threshold(),
        ]);
    }
}
