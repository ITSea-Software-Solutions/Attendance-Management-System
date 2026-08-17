<?php

/**
 * Preset gate/department names offered across the product (user creation,
 * deployment approvals). Companies can always type a custom one — these
 * keep naming consistent so per-gate scoping and permissions stay precise.
 * 'Main Gate' is the conventional default: every worker is normally allowed
 * there, and gate devices without a configured location stamp it.
 */
return [
    'presets' => [
        'Main Gate',
        'Back Gate',
        'Security',
        'HR',
        'Production',
        'Quality',
        'Stores & Warehouse',
        'Maintenance',
        'Dispatch',
        'Canteen',
        'Admin Office',
    ],
];
