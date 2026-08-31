<?php

namespace App\Services;

use App\Models\InAppNotification;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * One entry point for every notification. Renders the (possibly org-
 * customised) template, then fans out to the channels the recipient's org
 * plan includes:
 *   - in-app rows (notification center)      — all plans
 *   - email via the configured mailer        — Professional+
 *   - WhatsApp via Meta Cloud API            — Enterprise, AND only once
 *     WHATSAPP_TOKEN / WHATSAPP_PHONE_ID are set in .env (provider-gated;
 *     silently skipped until then).
 *
 * Every channel is best-effort: a notification failure must never break the
 * business action that triggered it.
 */
class NotifyService
{
    public function __construct(private TemplateService $templates)
    {
    }

    /** In-app rows for a set of users. */
    public function inApp(iterable $users, string $type, string $title, ?string $body = null, array $data = []): void
    {
        foreach ($users as $u) {
            try {
                InAppNotification::create([
                    'user_id' => $u instanceof User ? $u->id : (int) $u,
                    'type'    => $type,
                    'title'   => mb_substr($title, 0, 200),
                    'body'    => $body,
                    'data'    => $data ?: null,
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** Render template + send email (feature-gated by the RECIPIENT ORG's plan). */
    public function email(string $to, string $templateKey, array $vars, ?string $orgType = null, ?int $orgId = null, ?string $orgPlan = null): void
    {
        if ($orgPlan !== null && ! PlanService::hasFeature($orgPlan, 'email_notifications')) {
            return;
        }
        try {
            $r = $this->templates->render($templateKey, $vars, $orgType, $orgId);
            Mail::raw($r['body'], fn ($m) => $m->to($to)->subject($r['subject'] ?? 'TrueCrew'));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * WhatsApp text via Meta Cloud API. No-op until env creds exist AND the
     * org's plan has the feature AND the org toggled it on in settings.
     */
    public function whatsapp(?string $phone, string $templateKey, array $vars, ?string $orgType = null, ?int $orgId = null, ?string $orgPlan = null, array $orgSettings = []): void
    {
        $token   = config('services.whatsapp.token', env('WHATSAPP_TOKEN'));
        $phoneId = config('services.whatsapp.phone_id', env('WHATSAPP_PHONE_ID'));
        if (! $phone || ! $token || ! $phoneId) {
            // Dev visibility: the message that WOULD go out lands in the log,
            // so WhatsApp-dependent flows are testable before credentials.
            if ($phone && config('app.debug')) {
                try {
                    $r = $this->templates->render($templateKey, $vars, $orgType, $orgId, 'whatsapp');
                    \Log::info("WHATSAPP (dev, NOT sent) to {$phone}: ".$r['body']);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return; // provider not configured yet
        }
        if ($orgPlan !== null && ! PlanService::hasFeature($orgPlan, 'whatsapp_notifications')) {
            return;
        }
        if (($orgSettings['whatsapp_enabled'] ?? false) !== true) {
            return; // org has not opted in
        }
        try {
            $r = $this->templates->render($templateKey, $vars, $orgType, $orgId, 'whatsapp');
            $msisdn = preg_replace('/\D/', '', $phone);
            if (strlen($msisdn) === 10) {
                $msisdn = '91'.$msisdn; // default country: India
            }
            Http::withToken($token)->timeout(8)->post(
                "https://graph.facebook.com/v20.0/{$phoneId}/messages",
                [
                    'messaging_product' => 'whatsapp',
                    'to'                => $msisdn,
                    'type'              => 'text',
                    'text'              => ['body' => $r['body']],
                ]
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
