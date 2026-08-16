<?php

namespace App\Http\Controllers;

use App\Models\InAppNotification;
use App\Models\NotificationTemplate;
use App\Services\AuditService;
use App\Services\PlanService;
use App\Services\TemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notification center (in-app rows) + editable templates.
 *
 * Templates scope rules:
 *  - super_admin edits the GLOBAL defaults (org_type/org_id NULL)
 *  - company_admin / vendor_admin edit THEIR ORG's overrides — plan-gated
 *    by the `template_overrides` feature (Professional+).
 */
class NotificationController extends Controller
{
    public function __construct(private AuditService $audit)
    {
    }

    // ── Notification center ────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $rows = InAppNotification::where('user_id', $request->user()->id)
            ->orderByDesc('id')->limit(60)->get();
        $unread = InAppNotification::where('user_id', $request->user()->id)
            ->whereNull('read_at')->count();

        return response()->json(['notifications' => $rows, 'unread' => $unread]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => 'nullable|array']);
        $q = InAppNotification::where('user_id', $request->user()->id)->whereNull('read_at');
        if (! empty($data['ids'])) {
            $q->whereIn('id', $data['ids']);
        }
        $q->update(['read_at' => now()]);

        return response()->json(['message' => 'Marked read.']);
    }

    // ── Templates ──────────────────────────────────────────────────────────

    /** Catalogue + effective values for the caller's scope. */
    public function templates(Request $request): JsonResponse
    {
        $user = $request->user();
        [$orgType, $orgId] = $this->scopeFor($user);
        $svc = app(TemplateService::class);

        $out = [];
        foreach (TemplateService::DEFAULTS as $key => $def) {
            $eff = $svc->resolve($key, $orgType, $orgId);
            $out[] = [
                'key'     => $key,
                'label'   => $def['label'],
                'vars'    => $def['vars'],
                'subject' => $eff['subject'],
                'body'    => $eff['body'],
                'source'  => $eff['source'], // builtin | global | org
            ];
        }

        return response()->json([
            'templates'    => $out,
            'scope'        => $orgType ? 'org' : 'global',
            'can_override' => $orgType === null
                || PlanService::userHasFeature($user, 'template_overrides'),
        ]);
    }

    /** Save a template in the caller's scope. */
    public function saveTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'     => 'required|string|max:60',
            'subject' => 'nullable|string|max:200',
            'body'    => 'required|string|max:5000',
        ]);
        abort_unless(array_key_exists($data['key'], TemplateService::DEFAULTS), 422, 'Unknown template.');
        $user = $request->user();
        [$orgType, $orgId] = $this->scopeFor($user);
        if ($orgType !== null) {
            abort_unless(in_array($user->role, ['company_admin', 'vendor_admin'], true), 403);
            abort_unless(PlanService::userHasFeature($user, 'template_overrides'), 403,
                'Custom templates are a Professional/Enterprise feature.');
        }

        NotificationTemplate::updateOrCreate(
            ['key' => $data['key'], 'channel' => 'email', 'org_type' => $orgType, 'org_id' => $orgId],
            ['subject' => $data['subject'] ?? null, 'body' => $data['body'], 'updated_by' => $user->id],
        );
        $this->audit->log($user->id, 'template_saved', NotificationTemplate::class, null, [
            'key' => $data['key'], 'scope' => $orgType ?? 'global',
        ]);

        return response()->json(['message' => 'Template saved.']);
    }

    /** Remove the caller-scope row → falls back to global/built-in. */
    public function resetTemplate(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => 'required|string|max:60']);
        $user = $request->user();
        [$orgType, $orgId] = $this->scopeFor($user);
        NotificationTemplate::where('key', $data['key'])->where('channel', 'email')
            ->where('org_type', $orgType)->where('org_id', $orgId)->delete();

        return response()->json(['message' => 'Reset to default.']);
    }

    /** super admin → global (null scope); org admins → their org. */
    private function scopeFor($user): array
    {
        if ($user->isSuperAdmin()) {
            return [null, null];
        }
        if ($user->company_id) {
            return ['company', $user->company_id];
        }

        return ['vendor', $user->vendor_id];
    }
}
