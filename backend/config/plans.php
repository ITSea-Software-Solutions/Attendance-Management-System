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
            'features'     => ['notification_center'],
        ],
        'professional' => [
            'label'        => 'Professional',
            'price'        => '₹4,999/mo (billed offline)',
            'users'        => 25,
            'workers'      => 500,
            'links'        => 25,
            'history_days' => 365,
            'support'      => 'Email support',
            'features'     => [
                'notification_center',
                'email_notifications',
                'template_overrides',   // customise notification texts per org
                'bulk_import_export',   // workers/vendors CSV in + out
                'missing_out_alerts',   // daily "IN but never OUT" digest
            ],
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
            'features'     => [
                'notification_center',
                'email_notifications',
                'template_overrides',
                'bulk_import_export',
                'missing_out_alerts',
                'whatsapp_notifications', // IN/OUT to vendor + worker WhatsApp (needs WA Business API creds)
                'otp_verification',       // email/phone OTP steps (needs SMS/WA provider)
            ],
        ],
    ],

    // Human labels for the feature chips shown on plan cards / billing pages.
    'feature_labels' => [
        'notification_center'    => 'In-app notification center',
        'email_notifications'    => 'Email notifications',
        'template_overrides'     => 'Custom notification templates',
        'bulk_import_export'     => 'Bulk import / export (CSV)',
        'missing_out_alerts'     => 'Missing-OUT daily alerts',
        'whatsapp_notifications' => 'WhatsApp notifications (IN/OUT)',
        'otp_verification'       => 'OTP verification (email / phone)',
    ],
];
