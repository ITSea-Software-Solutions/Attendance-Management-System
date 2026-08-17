<?php

namespace App\Jobs;

use App\Models\AttendanceLog;
use App\Services\FaceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Cross-check a mark's gate proof photo against the worker's ENROLLED face.
 *
 * Runs async after a proof photo lands (web mark or app sync). Purely
 * advisory — it never blocks or invalidates a mark; it stores a similarity
 * score + verdict that surface in the day-detail view so supervisors can
 * spot buddy-punching (someone else's finger + someone else's face won't
 * both pass). Skipped when the worker has no enrolled face or the photo
 * has no usable face (angle/lighting) — verdict stays null, not false.
 */
class VerifyProofPhoto implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public int $logId)
    {
    }

    public function handle(FaceService $face): void
    {
        $log = AttendanceLog::with('worker')->find($this->logId);
        if (! $log || ! $log->auth_proof_path || ! $log->worker?->face_descriptor) {
            return;
        }
        if (! Storage::disk('private')->exists($log->auth_proof_path)) {
            return;
        }

        try {
            $probe = $face->embed(Storage::disk('private')->get($log->auth_proof_path), 'proof.jpg');
        } catch (\Throwable $e) {
            report($e);

            return; // pdf-service down — leave unchecked rather than mark false
        }
        if (! $probe) {
            return; // no usable face in the capture — stays "not checked"
        }

        $score = FaceService::cosine($probe, $log->worker->face_descriptor);
        $log->forceFill([
            'proof_face_score' => round($score, 3),
            'proof_face_match' => $score >= $face->threshold(),
        ])->save();
    }
}
