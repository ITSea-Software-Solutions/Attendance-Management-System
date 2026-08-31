<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * PAN card as an alternative identity at registration.
 *
 * Not every worker arrives with an Aadhaar to hand, and a registration that
 * cannot start is a worker who cannot be paid. A PAN identifies the person
 * well enough to register and begin attendance; the Aadhaar can follow and
 * is tracked separately as unverified until it does.
 *
 * The card itself is stored on the private disk, never web-reachable, and
 * the number is hashed for cross-contractor duplicate detection exactly the
 * way Aadhaar is.
 */
class PanController extends Controller
{
    public function __construct(private AuditService $audit) {}

    /** PAN is AAAAA9999A; the 4th character is the holder type, P = individual. */
    public const PAN_REGEX = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';

    public static function hashNumber(string $pan): string
    {
        return hash_hmac('sha256', strtoupper(trim($pan)), config('app.key'));
    }

    public static function mask(string $pan): string
    {
        $pan = strtoupper(trim($pan));

        return substr($pan, 0, 3).'XXXX'.substr($pan, -3);
    }

    /**
     * Read a PAN card — an e-PAN PDF or a photograph of the physical card.
     * Nothing is stored here; the caller reviews the fields first.
     */
    public function extract(Request $request): JsonResponse
    {
        $request->validate([
            'file'     => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,webp',
            'password' => 'nullable|string|max:64',
        ]);

        $file = $request->file('file');

        try {
            $base = config('services.pdf_service.url', env('PDF_SERVICE_URL', 'http://pdf-service:8001'));
            $response = Http::timeout((int) env('PDF_SERVICE_TIMEOUT', 60))
                ->attach('file', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
                ->post(rtrim($base, '/').'/extract-pan',
                    ['password' => (string) $request->input('password', '')]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not reach the document reader. Try again.'], 503);
        }

        if ($response->failed()) {
            $detail = $response->json('detail');

            return response()->json([
                'message' => is_array($detail) ? ($detail['message'] ?? 'Could not read the PAN card.')
                                               : ($detail ?: 'Could not read the PAN card.'),
                'code'    => is_array($detail) ? ($detail['code'] ?? 'PARSE_ERROR') : 'PARSE_ERROR',
            ], 422);
        }

        $data = $response->json();
        $pan  = strtoupper((string) ($data['pan_number'] ?? ''));

        if (! preg_match(self::PAN_REGEX, $pan)) {
            return response()->json([
                'message' => 'The number read from the card is not a valid PAN. Enter it manually.',
                'code'    => 'PAN_INVALID',
            ], 422);
        }

        // Warn early rather than after the whole form is filled in.
        $taken = Worker::withTrashed()->where('pan_hash', self::hashNumber($pan))->exists();

        return response()->json([
            'pan_number'   => $pan,
            'pan_masked'   => self::mask($pan),
            'holder_type'  => $data['holder_type'] ?? null,
            'name'         => $data['name'] ?? null,
            'father_name'  => $data['father_name'] ?? null,
            'dob'          => $data['dob'] ?? null,
            'photo_base64' => $data['photo_base64'] ?? null,
            'already_registered' => $taken,
        ]);
    }

    /** Attach the card file to a worker once they exist. */
    public function upload(Request $request, Worker $worker): JsonResponse
    {
        $this->authorizeVendor($request->user(), $worker);
        $request->validate(['file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,webp']);

        if ($worker->pan_card_path) {
            Storage::disk('private')->delete($worker->pan_card_path);
        }

        $file = $request->file('file');
        $path = Storage::disk('private')->putFileAs(
            'pan',
            $file,
            'pan_'.$worker->id.'_'.now()->timestamp.'.'.$file->getClientOriginalExtension()
        );

        $worker->forceFill(['pan_card_path' => $path])->save();
        $this->audit->log($request->user()->id, 'pan_card_uploaded', Worker::class, $worker->id);

        return response()->json(['message' => 'PAN card saved.', 'has_pan_card' => true]);
    }

    /** Serve the stored card. Companies may see it for their own workers. */
    public function download(Request $request, Worker $worker)
    {
        $user = $request->user();
        abort_unless($worker->pan_card_path, 404, 'No PAN card on file.');

        if ($user->isVendorUser()) {
            abort_unless($worker->vendor_id === $user->vendor_id, 403);
        } elseif ($user->isCompanyUser()) {
            $related = AttendanceLog::where('worker_id', $worker->id)->where('company_id', $user->company_id)->exists()
                || WorkerAssignment::where('worker_id', $worker->id)->where('company_id', $user->company_id)->exists();
            abort_unless($related, 403, 'Worker not associated with your company.');
        } elseif (! $user->isSuperAdmin()) {
            abort(403);
        }

        $this->audit->log($user->id, 'pan_card_downloaded', Worker::class, $worker->id);

        return Storage::disk('private')->response($worker->pan_card_path);
    }

    private function authorizeVendor($user, Worker $worker): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }
        abort_unless($user->isVendorUser() && $worker->vendor_id === $user->vendor_id, 403,
            'Only the owning contractor can change this worker.');
    }
}
