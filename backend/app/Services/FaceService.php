<?php

namespace App\Services;

use GuzzleHttp\Client;

/**
 * Camera-based face identification — hardware-free biometric path.
 * Embeddings come from the pdf-service (InsightFace ArcFace, 512-D);
 * matching is plain cosine similarity computed here, server-side.
 */
class FaceService
{
    public function threshold(): float
    {
        return (float) config('biometric.face_threshold', 0.45);
    }

    /**
     * Get the face embedding for an image (bytes). Returns null when no face
     * is detected; throws on service failure.
     */
    public function embed(string $imageBytes, string $filename = 'probe.jpg'): ?array
    {
        $client = new Client([
            'base_uri' => rtrim(config('services.pdf.url', env('PDF_SERVICE_URL', 'http://pdf-service:8001')), '/'),
            'timeout'  => (float) env('FACE_EMBED_TIMEOUT', 90),
        ]);

        $response = $client->post('/face/embed', [
            'multipart' => [[
                'name'     => 'image',
                'contents' => $imageBytes,
                'filename' => $filename,
            ]],
        ]);

        $data = json_decode((string) $response->getBody(), true);
        $embedding = $data['embedding'] ?? null;
        $this->lastLiveness = isset($data['liveness']) ? (float) $data['liveness'] : null;

        return is_array($embedding) ? $embedding : null;
    }

    /** Liveness of the LAST embed (0..1), null when no PAD model is active. */
    public ?float $lastLiveness = null;

    /**
     * Anti-spoofing gate: true = REJECT (looks like a photo/screen replay).
     * Only bites when BOTH a PAD model is installed (pdf-service) and
     * FACE_PAD_THRESHOLD is set — staffed gates can run without it.
     */
    public function spoofSuspected(): bool
    {
        $threshold = config('biometric.face_pad_threshold');

        return $threshold !== null && $this->lastLiveness !== null
            && $this->lastLiveness < (float) $threshold;
    }

    /** Cosine similarity between two embeddings (−1..1; same person ≳ threshold). */
    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na  = 0.0;
        $nb  = 0.0;
        $n   = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na  += $a[$i] * $a[$i];
            $nb  += $b[$i] * $b[$i];
        }
        if ($na == 0.0 || $nb == 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($na) * sqrt($nb));
    }
}
