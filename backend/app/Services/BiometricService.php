<?php

namespace App\Services;

/**
 * Biometric service — server-side FMD template matching.
 *
 * Device  : SecuGen HU20-AP (Hamster Pro 20 with Auto-Placement)
 * Format  : ISO 19794-2 FMD, 400 bytes, base64-encoded over the wire
 *
 * Architecture:
 *   HU20-AP → biometric-agent (Node.js, local Windows) → [WebSocket] →
 *   Browser → [HTTPS POST] → Laravel API → matchTemplates() here
 *
 * Score scale (SecuGen native):
 *   SGFPM_MatchTemplate returns 0–200.
 *   0        = definite non-match
 *   40       = recommended threshold for general applications (FAR ~0.001%)
 *   200      = perfect match (same template compared with itself)
 *
 * The fingerprint_score column in attendance_logs stores the raw SecuGen
 * score (0–200). The AttendanceController also receives it from the
 * biometric agent and stores it for audit purposes.
 */
class BiometricService
{
    // SecuGen recommended threshold for HU20-AP: 40 on their 0–200 scale.
    // Raise to 50–60 for higher security; lower to 30 for more tolerance.
    private const MATCH_THRESHOLD = 40; // SecuGen score 0–200

    /** Match threshold (0–200). Overridable via config('biometric.threshold'). */
    public function threshold(): int
    {
        return (int) config('biometric.threshold', self::MATCH_THRESHOLD);
    }

    /** Is a real server-side matcher configured? (binary present + executable) */
    public function matcherAvailable(): bool
    {
        $binary = config('biometric.matching_binary');

        return is_string($binary) && $binary !== '' && is_executable($binary);
    }

    /**
     * Compare two ISO 19794-2 FMD templates.
     *
     * SECURITY: there is NO fallback matcher. Minutiae comparison requires a
     * real algorithm (SecuGen server SDK / NIST Bozorth3 / SourceAFIS); a
     * byte-similarity stand-in scores DIFFERENT fingers near the threshold
     * (ISO headers are largely identical) and must never gate attendance.
     * When no binary is configured this returns unavailable=true and the
     * callers refuse with a clear message — real matching then happens
     * ON-DEVICE in the gate apps (SGFPM), which is the primary path.
     */
    public function matchTemplates(string $probeBase64, string $storedBase64): array
    {
        $probeBytes  = base64_decode($probeBase64, true);
        $storedBytes = base64_decode($storedBase64, true);
        if ($probeBytes === false || $storedBytes === false
            || strlen($probeBytes) < 30 || strlen($storedBytes) < 30) {
            return ['matched' => false, 'score' => 0, 'error' => 'Invalid template format'];
        }

        if (! $this->matcherAvailable()) {
            return ['matched' => false, 'score' => 0, 'unavailable' => true];
        }

        try {
            return $this->callMatchingBinary($probeBase64, $storedBase64);
        } catch (\Throwable $e) {
            \Log::error('Fingerprint matching error', ['error' => $e->getMessage()]);

            return ['matched' => false, 'score' => 0, 'error' => 'Matching failed'];
        }
    }

    /**
     * Call the configured matching binary (expects JSON {"score": 0-200}).
     */
    private function callMatchingBinary(string $probe, string $stored): array
    {
        $binaryPath = config('biometric.matching_binary', '/usr/local/bin/sgmatch');
        $output     = [];
        $exitCode   = 0;

        $cmd = escapeshellcmd($binaryPath)
            . ' ' . escapeshellarg($probe)
            . ' ' . escapeshellarg($stored);

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException('Fingerprint matching binary failed: ' . implode("\n", $output));
        }

        $result = json_decode(implode('', $output), true);
        return [
            'matched' => ($result['score'] ?? 0) >= self::MATCH_THRESHOLD,
            'score'   => $result['score'] ?? 0,
        ];
    }
}
