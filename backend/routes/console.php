<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Scheduled tasks ────────────────────────────────────────────────────────────

// Mark open assignments as completed at end of day
Schedule::command('attendance:close-day')->dailyAt('23:59');

// Daily missing-OUT digest to company admins (in-app; email on Professional+).
Schedule::command('truecrew:missing-out-alerts')->dailyAt('21:00')->timezone('Asia/Kolkata');

// Vendor digests: benched workers + deployments nearing expiry.
Schedule::command('truecrew:deployment-alerts')->dailyAt('08:30')->timezone('Asia/Kolkata');

// Prune audit logs older than 1 year
Schedule::command('audit:prune --days=365')->monthly();
// Monday-morning weekly attendance summary (Enterprise: weekly_reports).
Schedule::command('truecrew:weekly-report')->weeklyOn(1, '08:00')->timezone('Asia/Kolkata');
