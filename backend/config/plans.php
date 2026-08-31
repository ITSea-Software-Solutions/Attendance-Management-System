<?php

/**
 * SaaS plan definitions — single source of truth for limits shown in the UI
 * and enforced server-side by PlanService. null = unlimited.
 *
 * Payment is OFFLINE for now: choosing a paid plan files an upgrade request
 * that the super admin approves after payment is settled outside the app.
 */
return [

    // Numeric monthly prices (INR) — power Razorpay order amounts and the
    // Billing page. Set the real numbers in .env; null hides online payment
    // amounts until priced.
    'prices_inr' => [
        'professional' => env('PRICE_PROFESSIONAL_INR') !== null ? (int) env('PRICE_PROFESSIONAL_INR') : null,
        'enterprise'   => env('PRICE_ENTERPRISE_INR') !== null ? (int) env('PRICE_ENTERPRISE_INR') : null,
    ],

    // Shown to org admins on the Billing page — how to pay offline.
    // Fill the real details in .env at launch; placeholders keep the demo honest.
    'payment' => [
        'upi'   => env('PAY_UPI', '[your-upi-id@bank]'),
        'bank'  => env('PAY_BANK', '[Account name · A/c no · IFSC]'),
        'note'  => env('PAY_NOTE', 'After paying, record the payment below with the reference number — the platform team verifies and activates your plan.'),
    ],

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
            'features'     => ['face_attendance', 'offline_apps', 'attendance_exports', 'notification_center'],
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
                'face_attendance', 'offline_apps', 'attendance_exports',
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
                'face_attendance', 'offline_apps', 'attendance_exports',
                'notification_center',
                'email_notifications',
                'template_overrides',
                'bulk_import_export',
                'missing_out_alerts',
                'weekly_reports',         // Monday attendance summary email
                'whatsapp_notifications', // IN/OUT to vendor + worker WhatsApp (needs WA Business API creds)
                'otp_verification',       // email/phone OTP steps (needs SMS/WA provider)
            ],
        ],
    ],

    // Human labels for the feature chips shown on plan cards / billing pages.
    'feature_labels' => [
        'face_attendance'        => 'Camera face attendance',
        'offline_apps'           => 'Offline-capable Android & Windows apps',
        'attendance_exports'     => 'Attendance CSV + printable reports',
        'notification_center'    => 'In-app notification center',
        'email_notifications'    => 'Email notifications',
        'template_overrides'     => 'Custom notification templates',
        'bulk_import_export'     => 'Bulk import / export (CSV)',
        'missing_out_alerts'     => 'Missing-OUT daily alerts',
        'weekly_reports'         => 'Weekly attendance summary email',
        'whatsapp_notifications' => 'WhatsApp notifications (IN/OUT)',
        'otp_verification'       => 'OTP verification (email / phone)',
    ],
];
