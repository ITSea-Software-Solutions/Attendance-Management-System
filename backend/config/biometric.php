<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Simulation mode
    |--------------------------------------------------------------------------
    | When true, the server accepts fingerprint marks without a real template
    | match and the identify() endpoint synthesises a match. This exists ONLY
    | so the fingerprint flow is demoable without a SecuGen device.
    |
    | MUST be false in production — when false, attendance with method=fingerprint
    | is verified server-side against the enrolled template.
    */
    'simulation' => env('BIOMETRIC_SIM', false),

    /*
    |--------------------------------------------------------------------------
    | Aadhaar duplicate check
    |--------------------------------------------------------------------------
    | When true (DEFAULT — keep in production), a given Aadhaar can register
    | exactly one worker across ALL vendors. Set AADHAAR_DEDUP=false only on
    | demo/test environments to allow the same Aadhaar on multiple test
    | workers.
    */
    'aadhaar_dedup' => env('AADHAAR_DEDUP', true),

    /*
    |--------------------------------------------------------------------------
    | Match threshold (SecuGen scale 0–200)
    |--------------------------------------------------------------------------
    | 40 is SecuGen's recommended default for the HU20-AP. Raise for stricter
    | matching, lower for more tolerance.
    */
    'threshold' => env('BIOMETRIC_THRESHOLD', 40),

    /*
    |--------------------------------------------------------------------------
    | Matching binary (production)
    |--------------------------------------------------------------------------
    | Path to a real fingerprint matching binary (SecuGen server SDK / NIST
    | NBIS) invoked by BiometricService when wired up. The bundled matcher is
    | a development placeholder and is NOT a real biometric comparison.
    */
    'matching_binary' => env('BIOMETRIC_MATCHING_BINARY', '/usr/local/bin/sgmatch'),

    /*
    |--------------------------------------------------------------------------
    | Face matching (camera-based, hardware-free)
    |--------------------------------------------------------------------------
    | Cosine-similarity threshold on ArcFace 512-D embeddings (pdf-service).
    | Typical same-person similarity is 0.5–0.8; different people < 0.3.
    | 0.45 balances false accepts/rejects for supervised gate use — raise it
    | for stricter matching. Face marks are re-verified server-side at mark
    | time from the submitted photo; the client can never assert a match.
    */
    'face_threshold' => (float) env('FACE_MATCH_THRESHOLD', 0.45),

];
