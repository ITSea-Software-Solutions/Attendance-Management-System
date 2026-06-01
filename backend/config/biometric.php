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

];
