<?php

/**
 * SaaS plan definitions — single source of truth for limits shown in the UI
 * and enforced server-side by PlanService. null = unlimited.
 *
 * Payment is OFFLINE for now: choosing a paid plan files an upgrade request
 * that the super admin approves after payment is settled outside the app.
 */
return [
    'default' => 'trial',

    'plans' => [
        'trial' => [
            'label'        => 'Trial',
            'price'        => 'Free',
            'users'        => 3,     // logins in the org (admins, operators, gates)
            'workers'      => 10,    // registered workers (vendor orgs)
            'links'        => 3,     // approved company↔vendor associations
            'history_days' => 30,    // attendance history window (soft, UI-level)
            'support'      => 'Community',
        ],
        'professional' => [
            'label'        => 'Professional',
            'price'        => '₹4,999/mo (billed offline)',
            'users'        => 25,
            'workers'      => 500,
            'links'        => 25,
            'history_days' => 365,
            'support'      => 'Email support',
        ],
        // Deliberately CAPPED, not "unlimited" — honest about infra headroom and
        // abuse-proof; genuinely bigger customers are a custom conversation, and
        // per-org exceptions are a one-line config/plan change anyway.
        'enterprise' => [
            'label'        => 'Enterprise',
            'price'        => 'Custom (contact us)',
            'users'        => 100,
            'workers'      => 5000,
            'links'        => 100,
            'history_days' => null,
            'support'      => 'Priority support + onboarding',
        ],
    ],
];
