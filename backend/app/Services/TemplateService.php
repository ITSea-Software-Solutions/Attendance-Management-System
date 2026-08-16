<?php

namespace App\Services;

use App\Models\NotificationTemplate;

/**
 * Editable notification templates with a 2-level scope:
 *   1. org override  (company/vendor customised it — plan-gated feature)
 *   2. global default (super admin editable; seeded below)
 *
 * Placeholders use {{name}} syntax and are replaced verbatim — anything
 * unknown is left visible so a bad edit is obvious, never silent.
 */
class TemplateService
{
    /** Seeded defaults — also the catalogue of every known template key. */
    public const DEFAULTS = [
        'welcome_user' => [
            'label'   => 'Welcome — new login created',
            'subject' => 'Welcome to TrueCrew, {{name}}',
            'body'    => "Hi {{name}},\n\nYour TrueCrew login is ready.\n\nSign in: {{login_url}}\nEmail: {{email}}\nRole: {{role}}\n\nIf you did not expect this, contact your administrator.",
            'vars'    => ['name', 'email', 'role', 'login_url', 'org_name'],
        ],
        'forgot_password' => [
            'label'   => 'Password reset link',
            'subject' => 'Reset your TrueCrew password',
            'body'    => "Hi,\n\nA password reset was requested for {{email}}.\nReset link (valid 60 minutes): {{reset_url}}\n\nIf this wasn't you, ignore this email.",
            'vars'    => ['email', 'reset_url'],
        ],
        'vendor_approved' => [
            'label'   => 'Vendor approved by company',
            'subject' => '{{company_name}} approved your access — TrueCrew',
            'body'    => "Good news!\n\n{{company_name}} has APPROVED {{vendor_name}}.\nYou can now deploy workers to their sites and mark attendance at their gates.",
            'vars'    => ['vendor_name', 'company_name'],
        ],
        'vendor_rejected' => [
            'label'   => 'Vendor rejected by company',
            'subject' => '{{company_name}} declined your access request — TrueCrew',
            'body'    => "{{company_name}} has declined the access request from {{vendor_name}}.\nReason: {{reason}}\n\nYou can contact the company directly or submit a new request later.",
            'vars'    => ['vendor_name', 'company_name', 'reason'],
        ],
        'worker_registered' => [
            'label'   => 'Worker registered',
            'subject' => 'Worker registered: {{worker_name}}',
            'body'    => "{{worker_name}} has been registered by {{vendor_name}}.\nAadhaar: {{aadhaar_masked}} · Status: {{status}}",
            'vars'    => ['worker_name', 'vendor_name', 'aadhaar_masked', 'status'],
        ],
        'plan_approved' => [
            'label'   => 'Plan upgrade approved',
            'subject' => 'TrueCrew — plan upgrade activated',
            'body'    => "Your TrueCrew plan upgrade to {{plan}} is ACTIVE. Thank you!",
            'vars'    => ['plan', 'org_name'],
        ],
        'plan_declined' => [
            'label'   => 'Plan upgrade declined',
            'subject' => 'TrueCrew — plan upgrade declined',
            'body'    => "Your TrueCrew plan upgrade request to {{plan}} was declined. Reply to this email or contact support for details.",
            'vars'    => ['plan', 'org_name'],
        ],
        'missing_out_alert' => [
            'label'   => 'Missing-OUT daily alert',
            'subject' => '{{count}} worker(s) IN but never OUT — {{date}}',
            'body'    => "These workers marked IN on {{date}} but never marked OUT:\n\n{{worker_lines}}\n\nCheck the Exceptions page for live status.",
            'vars'    => ['date', 'count', 'worker_lines'],
        ],
        'weekly_report' => [
            'label'   => 'Weekly attendance summary (Enterprise)',
            'subject' => 'TrueCrew weekly report — {{week}}',
            'body'    => "Attendance summary for {{company_name}}, week {{week}}:\n\n{{summary_lines}}\n\nTotal worker-days: {{total_days}} · Total hours: {{total_hours}}\n\nFull details are on your Attendance Log page (exports available).",
            'vars'    => ['week', 'company_name', 'summary_lines', 'total_days', 'total_hours'],
        ],
        'attendance_inout' => [
            'label'   => 'IN/OUT notification (WhatsApp)',
            'subject' => null,
            'body'    => "TrueCrew: {{worker_name}} marked {{type}} at {{time}} ({{company_name}}, {{gate}}).",
            'vars'    => ['worker_name', 'type', 'time', 'company_name', 'gate'],
        ],
    ];

    /** Resolve the effective template: org override → global row → built-in. */
    public function resolve(string $key, ?string $orgType = null, ?int $orgId = null, string $channel = 'email'): array
    {
        if ($orgType && $orgId) {
            $own = NotificationTemplate::where(compact('key', 'channel'))
                ->where('org_type', $orgType)->where('org_id', $orgId)->first();
            if ($own) {
                return ['subject' => $own->subject, 'body' => $own->body, 'source' => 'org'];
            }
        }
        $global = NotificationTemplate::where(compact('key', 'channel'))
            ->whereNull('org_type')->whereNull('org_id')->first();
        if ($global) {
            return ['subject' => $global->subject, 'body' => $global->body, 'source' => 'global'];
        }
        $d = self::DEFAULTS[$key] ?? ['subject' => $key, 'body' => $key];

        return ['subject' => $d['subject'], 'body' => $d['body'], 'source' => 'builtin'];
    }

    /** Render {{placeholders}}. Unknown ones stay visible on purpose. */
    public function render(string $key, array $vars, ?string $orgType = null, ?int $orgId = null, string $channel = 'email'): array
    {
        $t = $this->resolve($key, $orgType, $orgId, $channel);
        $replace = [];
        foreach ($vars as $k => $v) {
            $replace['{{'.$k.'}}'] = (string) $v;
        }

        return [
            'subject' => $t['subject'] ? strtr($t['subject'], $replace) : null,
            'body'    => strtr($t['body'], $replace),
        ];
    }
}
