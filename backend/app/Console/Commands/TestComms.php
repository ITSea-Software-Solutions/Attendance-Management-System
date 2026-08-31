<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * One-command launch verifier for every external service. Run it after
 * dropping credentials into .env — each channel reports configured/working
 * or exactly what is missing. Safe anytime: nothing is sent unless the
 * channel is configured (pass --to= for a live email/SMS/WhatsApp test).
 *
 *   php artisan truecrew:test-comms
 *   php artisan truecrew:test-comms --to=9198XXXXXXXX --email=you@x.com
 */
class TestComms extends Command
{
    protected $signature = 'truecrew:test-comms {--to=} {--email=}';

    protected $description = 'Verify email / SMS / WhatsApp / Razorpay / payment configuration for launch';

    public function handle(): int
    {
        $this->line('');
        $this->info('TrueCrew launch configuration check');
        $this->line(str_repeat('─', 56));

        // ── Email ────────────────────────────────────────────────────────────
        $mailer = config('mail.default');
        if ($mailer === 'log' || ! config('mail.mailers.smtp.host')) {
            $this->warn('EMAIL      dev mode (mailer=log) — mails land in laravel.log. Set MAIL_* for real sends.');
        } else {
            $this->info("EMAIL      configured: {$mailer} via ".config('mail.mailers.smtp.host'));
            if ($to = $this->option('email')) {
                try {
                    Mail::raw('TrueCrew test email — your SMTP works.', fn ($m) => $m->to($to)->subject('TrueCrew comms test'));
                    $this->info("           → test mail sent to {$to}");
                } catch (\Throwable $e) {
                    $this->error('           → send FAILED: '.$e->getMessage());
                }
            }
        }

        // ── SMS (OTP) ───────────────────────────────────────────────────────
        if (! env('MSG91_AUTHKEY')) {
            $this->warn('SMS/OTP    dev mode — OTPs are shown on-screen in debug. Set MSG91_AUTHKEY + MSG91_TEMPLATE_ID.');
        } else {
            $this->info('SMS/OTP    configured (MSG91'.(env('MSG91_TEMPLATE_ID') ? ', template set' : ' — TEMPLATE MISSING').')');
        }

        // ── WhatsApp ─────────────────────────────────────────────────────────
        $waToken = env('WHATSAPP_TOKEN');
        $waPhone = env('WHATSAPP_PHONE_ID');
        if (! $waToken || ! $waPhone) {
            $this->warn('WHATSAPP   dev mode — messages log to laravel.log; visitor passes use manual decisions. Set WHATSAPP_TOKEN + WHATSAPP_PHONE_ID; webhook: /api/whatsapp/webhook (verify token: '.env('WHATSAPP_WEBHOOK_VERIFY', 'truecrew').').');
        } else {
            try {
                $r = Http::withToken($waToken)->timeout(8)
                    ->get("https://graph.facebook.com/v20.0/{$waPhone}");
                $r->successful()
                    ? $this->info('WHATSAPP   configured — phone id verified with Meta.')
                    : $this->error('WHATSAPP   credentials set but Meta rejected them: HTTP '.$r->status());
                if (($to = $this->option('to')) && $r->successful()) {
                    $s = Http::withToken($waToken)->timeout(8)->post(
                        "https://graph.facebook.com/v20.0/{$waPhone}/messages",
                        ['messaging_product' => 'whatsapp', 'to' => preg_replace('/\D/', '', $to),
                         'type' => 'text', 'text' => ['body' => 'TrueCrew test — WhatsApp works.']]);
                    $s->successful()
                        ? $this->info("           → test message sent to {$to}")
                        : $this->error('           → send FAILED: '.$s->body());
                }
            } catch (\Throwable $e) {
                $this->error('WHATSAPP   check failed: '.$e->getMessage());
            }
        }

        // ── Razorpay (online payment) ────────────────────────────────────────
        $rzKey = env('RAZORPAY_KEY_ID');
        $rzSec = env('RAZORPAY_KEY_SECRET');
        if (! $rzKey || ! $rzSec) {
            $this->warn('RAZORPAY   not set — online payment hidden; offline payment (verified by super admin) fully works. Set RAZORPAY_KEY_ID + RAZORPAY_KEY_SECRET (test keys work).');
        } else {
            try {
                $r = Http::withBasicAuth($rzKey, $rzSec)->timeout(8)
                    ->post('https://api.razorpay.com/v1/orders', [
                        'amount' => 100, 'currency' => 'INR', 'receipt' => 'truecrew-test',
                    ]);
                $r->successful()
                    ? $this->info('RAZORPAY   configured — test order created ('.($rzKey[4] === 't' ? 'TEST mode' : 'LIVE mode').').')
                    : $this->error('RAZORPAY   keys rejected: HTTP '.$r->status().' '.$r->body());
            } catch (\Throwable $e) {
                $this->error('RAZORPAY   check failed: '.$e->getMessage());
            }
        }

        // ── Offline payment details + prices ─────────────────────────────────
        $upi = config('plans.payment.upi');
        str_contains((string) $upi, '[')
            ? $this->warn('PAYMENT    UPI/bank details are placeholders — set PAY_UPI + PAY_BANK for the Billing page.')
            : $this->info("PAYMENT    offline details set (UPI: {$upi}).");
        $pro = config('plans.prices_inr.professional');
        $ent = config('plans.prices_inr.enterprise');
        ($pro && $ent)
            ? $this->info("PRICES     Professional Rs.{$pro}/mo · Enterprise Rs.{$ent}/mo (env-driven).")
            : $this->warn('PRICES     not set — PRICE_PROFESSIONAL_INR / PRICE_ENTERPRISE_INR missing.');

        // ── Biometric posture ────────────────────────────────────────────────
        config('biometric.simulation')
            ? $this->warn('BIOMETRIC  SIMULATION ON — demo only; must be false in production.')
            : $this->info('BIOMETRIC  simulation off (production posture).');
        config('biometric.aadhaar_dedup')
            ? $this->info('AADHAAR    duplicate blocking ON (production posture).')
            : $this->warn('AADHAAR    dedup OFF — test mode; must be true in production.');
        config('app.debug')
            ? $this->warn('APP        debug ON — dev links/OTPs visible; must be false in production.')
            : $this->info('APP        debug off (production posture).');

        $this->line(str_repeat('─', 56));
        $this->line('Green = ready · Yellow = dev fallback active · Red = fix before launch.');

        return self::SUCCESS;
    }
}
