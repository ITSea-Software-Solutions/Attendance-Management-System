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

    /**
     * Compare two ISO 19794-2 FMD templates.
     * Returns match result with score.
     *
     * In production: use SecuGen server SDK or NIST NBIS for matching.
     * This implementation provides the interface contract.
     */
    public function matchTemplates(string $probeBase64, string $storedBase64): array
    {
        // In a real deployment, this would call SecuGen's SGFPLIB or
        // a fingerprint matching binary via subprocess / PHP FFI.
        // The implementation below shows the expected interface.

        try {
            $probeBytes  = base64_decode($probeBase64);
            $storedBytes = base64_decode($storedBase64);

            // Validate template format (SecuGen FMD starts with specific header)
            if (strlen($probeBytes) < 30 || strlen($storedBytes) < 30) {
                return ['matched' => false, 'score' => 0, 'error' => 'Invalid template format'];
            }

            // === Subprocess-based matching ===
            // In production, call the matching binary:
            // $result = $this->callMatchingBinary($probeBase64, $storedBase64);

            // === Placeholder for development ===
            // Replace this block with real SDK integration
            $score = $this->developmentMatcher($probeBytes, $storedBytes);

            return [
                'matched' => $score >= self::MATCH_THRESHOLD,
                'score'   => $score,
            ];

        } catch (\Exception $e) {
            \Log::error('Fingerprint matching error', ['error' => $e->getMessage()]);
            return ['matched' => false, 'score' => 0, 'error' => 'Matching failed'];
        }
    }

    /**
     * Development placeholder for fingerprint matching.
     * Replace with real SecuGen SDK call in production.
     */
    private function developmentMatcher(string $probe, string $stored): int
    {
        // This is NOT a real fingerprint comparison.
        // It's a placeholder that compares byte similarity.
        // MUST be replaced with SecuGen FDx SDK or NIST NBIS.
        if ($probe === $stored) return 100;

        $minLen    = min(strlen($probe), strlen($stored));
        $sameBytes = 0;
        for ($i = 0; $i < min($minLen, 100); $i++) {
            if ($probe[$i] === $stored[$i]) $sameBytes++;
        }

        return (int)round(($sameBytes / 100) * 100);
    }

    /**
     * Call external matching binary via subprocess.
     * Uncomment and configure for production SecuGen server SDK.
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
