<?php

namespace App\Http\Controllers;

use App\Models\Worker;
use App\Services\AuditService;
use App\Services\PayrollService;
use App\Support\Csv;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Wage register, muster sheet and the money adjustments around them.
 * Company admins/HR see their own company; super admin may pass company_id.
 * Vendors see only their own workers' lines (they bill against them).
 */
class PayrollController extends Controller
{
    public function __construct(
        private PayrollService $payroll,
        private AuditService $audit,
    ) {}

    /** Resolve the company + period + role scoping for every endpoint here. */
    private function scope(Request $request): array
    {
        $user = $request->user();
        abort_if($user->isGateUser(), 403, 'Gate logins do not have payroll access.');

        $companyId = $user->isCompanyUser()
            ? $user->company_id
            : (int) $request->input('company_id');
        abort_unless($companyId, 422, 'company_id is required.');

        if ($request->filled('from') && $request->filled('to')) {
            $from = Carbon::parse($request->input('from'))->startOfDay();
            $to   = Carbon::parse($request->input('to'))->endOfDay();
        } else {
            $anchor = $request->filled('month')
                ? Carbon::parse($request->input('month').'-15')
                : now();
            [$from, $to] = PayrollService::period($anchor, $request->integer('cycle_start_day') ?: null);
        }

        $opts = [];
        if ($user->isVendorUser()) {
            $opts['vendor_id'] = $user->vendor_id;      // vendors see only their people
        } elseif ($request->filled('vendor_id')) {
            $opts['vendor_id'] = (int) $request->input('vendor_id');
        }
        if ($ids = $request->input('worker_ids')) {
            $opts['worker_ids'] = array_map('intval', is_array($ids) ? $ids : explode(',', $ids));
        }

        return [$companyId, $from, $to, $opts];
    }

    /**
     * GET /payroll/components — the wage-head catalogue and statutory rates,
     * so the registration form and the register render from one definition.
     * ?monthly_rate= also returns a suggested split for that rate.
     */
    public function componentsCatalogue(Request $request): JsonResponse
    {
        $rate = (float) $request->input('monthly_rate', 0);

        return response()->json([
            'components' => PayrollService::components(),
            'statutory'  => config('payroll.statutory'),
            'defaults'   => [
                'wage_divisor' => (int) config('payroll.wage_divisor', 26),
                'ot_divisor'   => (int) config('payroll.ot_divisor', 8),
            ],
            'suggested'  => $rate > 0 ? PayrollService::suggestStructure($rate) : null,
        ]);
    }

    /** GET /payroll/register — the wage register as JSON. */
    public function register(Request $request): JsonResponse
    {
        [$companyId, $from, $to, $opts] = $this->scope($request);
        $data = $this->payroll->register($companyId, $from, $to, $opts);

        // The day-by-day grid is large; the register view does not need it.
        if (! $request->boolean('with_days')) {
            $data['rows'] = array_map(function ($r) {
                unset($r['days']);
                return $r;
            }, $data['rows']);
        }

        return response()->json($data);
    }

    /** GET /payroll/register-export — wage register as CSV. */
    public function registerExport(Request $request)
    {
        [$companyId, $from, $to, $opts] = $this->scope($request);
        $data = $this->payroll->register($companyId, $from, $to, $opts);
        $r    = $data['rules'];

        $name = "truecrew-wage-register-{$data['period']['from']}_to_{$data['period']['to']}.csv";

        return response()->streamDownload(function () use ($data, $r) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            Csv::row($out, ["Wage register {$data['period']['from']} to {$data['period']['to']}. "
                ."Day rate = monthly rate / {$r['wage_divisor']}; OT rate = day rate / {$r['ot_divisor']}; "
                .'payable = day rate x present days + OT hours x OT rate + arrears/bonus - advances/deductions.']);
            Csv::row($out, []);
            Csv::row($out, ['Worker', 'Emp code', 'Contractor', 'Present days', 'Absent', 'Weekly off',
                'Holidays', 'Hours', 'OT hours', 'Monthly rate', 'Day rate', 'OT rate',
                'Basic amount', 'OT amount', 'Arrears', 'Bonus', 'Advance', 'Deduction',
                'Net payable', 'Flags']);
            foreach ($data['rows'] as $row) {
                Csv::row($out, [
                    $row['name'], $row['emp_code'], $row['vendor'], $row['present_days'],
                    $row['absent_days'], $row['off_days'], $row['holidays'],
                    $row['hours'], $row['ot_hours'], $row['monthly_rate'], $row['day_rate'],
                    $row['ot_rate'], $row['base_amount'], $row['ot_amount'],
                    $row['arrear'], $row['bonus'], $row['advance'], $row['deduction'],
                    $row['net_payable'], implode(' ', $row['flags']),
                ]);
            }
            $t = $data['totals'];
            Csv::row($out, []);
            Csv::row($out, ['TOTAL', '', '', $t['present_days'], $t['absent_days'], '', '', '',
                $t['ot_hours'], '', '', '', $t['base_amount'], $t['ot_amount'], '', '', '', '',
                $t['net_payable'], '']);
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * GET /payroll/muster — the classic muster grid: one row of day codes per
     * worker with the daily overtime row beneath, exactly as sites keep it.
     */
    public function muster(Request $request)
    {
        [$companyId, $from, $to, $opts] = $this->scope($request);
        $data = $this->payroll->register($companyId, $from, $to, $opts);
        $days = $data['days'];

        $name = "truecrew-muster-{$data['period']['from']}_to_{$data['period']['to']}.csv";

        return response()->streamDownload(function () use ($data, $days) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            Csv::row($out, ['Muster '.$data['period']['from'].' to '.$data['period']['to']
                .'. P = present, A = absent, WO = weekly off, H = holiday. '
                .'The row under each worker is overtime hours for that day.']);
            Csv::row($out, []);

            $head = ['Sr', 'Name of worker', 'Emp code', 'Contractor'];
            foreach ($days as $d) {
                $head[] = (int) substr($d, 8, 2);   // day-of-month, like the paper sheet
            }
            array_push($head, 'OT', 'OT Amt', 'Total Days', 'Monthly rate', 'Arrears', 'Payable Amount');
            Csv::row($out, $head);

            $sr = 0;
            foreach ($data['rows'] as $row) {
                $sr++;
                $line = [$sr, $row['name'], $row['emp_code'], $row['vendor']];
                $otLine = ['', '', '', ''];
                foreach ($days as $d) {
                    $c = $row['days'][$d] ?? null;
                    $line[]   = $c['status'] ?? '';
                    $otLine[] = ($c && $c['ot'] > 0) ? $c['ot'] : 0;
                }
                array_push($line, $row['ot_hours'], $row['ot_amount'], $row['present_days'],
                    $row['monthly_rate'], $row['arrear'], $row['net_payable']);
                Csv::row($out, $line);
                Csv::row($out, $otLine);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** POST /payroll/rates — set wage rates in bulk. */
    public function saveRates(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || $user->isVendorUser() || $user->role === 'company_admin',
            403, 'Not permitted to set wage rates.');

        $data = $request->validate([
            'rates'                 => 'required|array|min:1',
            'rates.*.worker_id'     => 'required|integer|exists:workers,id',
            // Daily is the norm for contract labour; monthly is for staff.
            'rates.*.wage_type'     => 'nullable|in:daily,monthly',
            'rates.*.daily_rate'    => 'nullable|numeric|min:0|max:99999',
            'rates.*.monthly_rate'  => 'nullable|numeric|min:0|max:9999999',
            'rates.*.wage_divisor'  => 'nullable|integer|min:1|max:31',
            'rates.*.ot_divisor'    => 'nullable|integer|min:1|max:24',
            'rates.*.ot_multiplier' => 'nullable|numeric|min:0|max:4',
            'rates.*.wage_components' => 'nullable|array',
            'note'                  => 'nullable|string|max:255',
        ]);

        $updated = 0;
        $proposed = 0;
        $notify = [];
        foreach ($data['rates'] as $r) {
            $worker = Worker::find($r['worker_id']);
            if (! $worker) {
                continue;
            }
            // A contractor may only price their own workers.
            if ($user->isVendorUser() && $worker->vendor_id !== $user->vendor_id) {
                continue;
            }

            // The company pays, so the company agrees the number. A change
            // proposed by the contractor waits for approval; the agreed rate
            // stays in force meanwhile. Nobody to approve (worker on the
            // bench) means it simply applies.
            if ($user->isVendorUser()) {
                $companyId = $this->payingCompanyFor($worker);
                if ($companyId) {
                    $this->proposeWageChange($worker, $companyId, $r, $user, $data['note'] ?? null);
                    $proposed++;
                    continue;
                }
            }
            // Only touch what was actually sent, so editing a day rate never
            // wipes a structure or an overtime override set elsewhere.
            $fields = array_filter([
                'wage_type'       => $r['wage_type'] ?? null,
                'daily_rate'      => $r['daily_rate'] ?? null,
                'monthly_rate'    => $r['monthly_rate'] ?? null,
                'wage_divisor'    => $r['wage_divisor'] ?? null,
                'ot_divisor'      => $r['ot_divisor'] ?? null,
                'ot_multiplier'   => $r['ot_multiplier'] ?? null,
                'wage_components' => $r['wage_components'] ?? null,
            ], fn ($v) => $v !== null);
            if ($fields === []) {
                continue;
            }
            $before = $worker->wage_type === 'monthly' ? $worker->monthly_rate : $worker->daily_rate;
            $worker->forceFill($fields)->save();
            $updated++;

            // The company set the number directly — the contractor is paid on
            // it, so tell them rather than letting them find out at payout.
            if (! $user->isVendorUser() && $worker->vendor_id) {
                $after = $worker->wage_type === 'monthly' ? $worker->monthly_rate : $worker->daily_rate;
                if ((string) $before !== (string) $after) {
                    $notify[] = [$worker, $before, $after];
                }
            }
        }

        foreach ($notify as [$w, $before, $after]) {
            $vendorUsers = \App\Models\User::where('vendor_id', $w->vendor_id)
                ->where('role', 'vendor_admin')->get();
            app(\App\Services\NotifyService::class)->inApp($vendorUsers, 'wage_rate_set',
                "Rate set by the company: {$w->name}",
                sprintf('%s %s (was %s).%s',
                    '₹'.number_format((float) $after),
                    $w->wage_type === 'monthly' ? 'per month' : 'per day',
                    $before ? '₹'.number_format((float) $before) : 'no rate',
                    ($data['note'] ?? null) ? ' Note: '.$data['note'] : ''),
                ['worker_id' => $w->id]);
        }

        $this->audit->log($user->id, 'wage_rates_updated', Worker::class, null,
            ['count' => $updated, 'proposed' => $proposed]);

        $parts = [];
        if ($updated)  { $parts[] = "{$updated} wage rate(s) saved."; }
        if ($proposed) { $parts[] = "{$proposed} change(s) sent to the company for approval."; }

        return response()->json([
            'message'  => $parts ? implode(' ', $parts) : 'Nothing to change.',
            'updated'  => $updated,
            'proposed' => $proposed,
        ]);
    }

    /** The company currently paying for this worker, if any. */
    private function payingCompanyFor(Worker $worker): ?int
    {
        return \App\Models\WorkerAssignment::where('worker_id', $worker->id)
            ->where('status', \App\Models\WorkerAssignment::STATUS_ACTIVE)
            ->where('approval_status', 'approved')
            ->whereDate('end_date', '>=', today())
            ->orderByDesc('start_date')
            ->value('company_id');
    }

    /** Record a contractor's proposed rate for the company to decide on. */
    private function proposeWageChange(Worker $worker, int $companyId, array $r, $user, ?string $note): void
    {
        // One open request per worker: a newer proposal replaces the old one
        // rather than leaving the company a queue of stale numbers.
        \App\Models\WageChangeRequest::where('worker_id', $worker->id)
            ->where('status', \App\Models\WageChangeRequest::STATUS_PENDING)
            ->update(['status' => \App\Models\WageChangeRequest::STATUS_REJECTED,
                      'decision_note' => 'Superseded by a newer proposal.',
                      'decided_at' => now()]);

        \App\Models\WageChangeRequest::create([
            'worker_id'    => $worker->id,
            'company_id'   => $companyId,
            'vendor_id'    => $worker->vendor_id,
            'wage_type'    => $r['wage_type'] ?? $worker->wage_type ?? 'daily',
            'daily_rate'   => $r['daily_rate'] ?? null,
            'monthly_rate' => $r['monthly_rate'] ?? null,
            'wage_components' => $r['wage_components'] ?? null,
            'current_wage_type'    => $worker->wage_type,
            'current_daily_rate'   => $worker->daily_rate,
            'current_monthly_rate' => $worker->monthly_rate,
            'status'       => \App\Models\WageChangeRequest::STATUS_PENDING,
            'note'         => $note,
            'requested_by' => $user->id,
        ]);

        $was  = $worker->wage_type === 'monthly' ? $worker->monthly_rate : $worker->daily_rate;
        $now  = ($r['wage_type'] ?? $worker->wage_type) === 'monthly'
            ? ($r['monthly_rate'] ?? null) : ($r['daily_rate'] ?? null);
        $per  = ($r['wage_type'] ?? $worker->wage_type) === 'monthly' ? 'per month' : 'per day';
        $body = trim(sprintf('%s proposes %s %s for %s (was %s).%s',
            $worker->vendor?->name ?? 'The contractor',
            '₹'.number_format((float) $now), $per, $worker->name,
            $was ? '₹'.number_format((float) $was) : 'no rate',
            $note ? ' Note: '.$note : ''));

        $admins = \App\Models\User::where('company_id', $companyId)
            ->whereIn('role', ['company_admin', 'company_hr'])->get();
        app(\App\Services\NotifyService::class)->inApp($admins, 'wage_change_requested',
            "Wage change proposed: {$worker->name}", $body,
            ['worker_id' => $worker->id]);
    }

    /** GET /payroll/wage-requests — what is waiting for a decision. */
    public function wageRequests(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->isGateUser(), 403, 'Gate logins do not have payroll access.');

        // Deliberately NOT scope(): a contractor works across several companies
        // and must see every request they are waiting on, even when the page
        // has no company picked. Only company users are pinned to one company.
        $rows = \App\Models\WageChangeRequest::with(['worker:id,name,emp_code', 'vendor:id,name', 'company:id,name', 'requestedBy:id,name'])
            ->when($user->isCompanyUser(), fn ($q) => $q->where('company_id', $user->company_id))
            ->when($user->isVendorUser(), fn ($q) => $q->where('vendor_id', $user->vendor_id))
            ->when(! $user->isCompanyUser() && $request->filled('company_id'),
                fn ($q) => $q->where('company_id', (int) $request->input('company_id')))
            ->when($request->input('status', 'pending') !== 'all',
                fn ($q) => $q->where('status', $request->input('status', 'pending')))
            ->orderByDesc('created_at')->limit(200)->get();

        return response()->json($rows);
    }

    public function decideWageRequest(Request $request, int $id): JsonResponse
    {
        // Check who is asking BEFORE resolving scope, so a contractor gets a
        // straight "not yours to approve" rather than a scope error.
        $user = $request->user();
        abort_if($user->isVendorUser(), 403, 'Only the company can approve a wage change.');
        abort_unless(in_array($user->role, ['company_admin', 'super_admin'], true), 403,
            'Only the company admin can approve a wage change.');

        $data = $request->validate([
            'decision' => 'required|in:approved,rejected',
            'note'     => 'nullable|string|max:255',
        ]);

        $req = \App\Models\WageChangeRequest::findOrFail($id);
        // A company admin may only decide their own company's requests; a super
        // admin decides for whichever company the request belongs to.
        abort_if($user->isCompanyUser() && $req->company_id !== $user->company_id, 403,
            'That request belongs to another company.');
        abort_unless($req->status === \App\Models\WageChangeRequest::STATUS_PENDING, 422,
            'This request has already been decided.');

        if ($data['decision'] === 'approved') {
            $worker = Worker::findOrFail($req->worker_id);
            $worker->forceFill(array_filter([
                'wage_type'       => $req->wage_type,
                'daily_rate'      => $req->daily_rate,
                'monthly_rate'    => $req->monthly_rate,
                'wage_components' => $req->wage_components,
            ], fn ($v) => $v !== null))->save();
        }

        $req->forceFill([
            'status'        => $data['decision'],
            'decision_note' => $data['note'] ?? null,
            'decided_by'    => $user->id,
            'decided_at'    => now(),
        ])->save();

        $this->audit->log($user->id, 'wage_change_'.$data['decision'],
            Worker::class, $req->worker_id, $data);

        // The contractor proposed it; they need to hear the answer.
        $vendorUsers = \App\Models\User::where('vendor_id', $req->vendor_id)
            ->whereIn('role', ['vendor_admin'])->get();
        $rate = $req->wage_type === 'monthly' ? $req->monthly_rate : $req->daily_rate;
        app(\App\Services\NotifyService::class)->inApp($vendorUsers, 'wage_change_decided',
            ($data['decision'] === 'approved' ? 'Wage change approved: ' : 'Wage change rejected: ')
                .($req->worker?->name ?? 'worker'),
            $data['decision'] === 'approved'
                ? '₹'.number_format((float) $rate).' is now the agreed rate.'
                : 'The previous rate stays in force.'.($data['note'] ? ' Reason: '.$data['note'] : ''),
            ['worker_id' => $req->worker_id]);

        return response()->json([
            'message' => $data['decision'] === 'approved'
                ? 'Approved — the new rate is now in force.'
                : 'Rejected — the previous rate stays in force.',
        ]);
    }

    /** POST /payroll/adjustments — arrears, advances, deductions, bonuses. */
    public function storeAdjustment(Request $request): JsonResponse
    {
        [$companyId, $from, $to] = $this->scope($request);
        $user = $request->user();
        abort_if($user->isVendorUser(), 403, 'Only the company can post adjustments.');

        $data = $request->validate([
            'worker_id' => 'required|integer|exists:workers,id',
            'type'      => 'required|in:arrear,advance,deduction,bonus',
            'amount'    => 'required|numeric|min:0.01|max:9999999',
            'note'      => 'nullable|string|max:255',
        ]);

        $id = \DB::table('payroll_adjustments')->insertGetId([
            'worker_id' => $data['worker_id'], 'company_id' => $companyId,
            'period_start' => $from->toDateString(), 'period_end' => $to->toDateString(),
            'type' => $data['type'], 'amount' => $data['amount'],
            'note' => $data['note'] ?? null, 'created_by' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->audit->log($user->id, 'payroll_adjustment_added', Worker::class, $data['worker_id'], $data);

        return response()->json(['message' => 'Adjustment saved.', 'id' => $id], 201);
    }

    public function deleteAdjustment(Request $request, int $id): JsonResponse
    {
        [$companyId] = $this->scope($request);
        abort_if($request->user()->isVendorUser(), 403, 'Only the company can remove adjustments.');

        $deleted = \DB::table('payroll_adjustments')->where('id', $id)->where('company_id', $companyId)->delete();
        abort_unless($deleted, 404, 'Adjustment not found.');
        $this->audit->log($request->user()->id, 'payroll_adjustment_deleted', Worker::class, null, ['id' => $id]);

        return response()->json(['message' => 'Adjustment removed.']);
    }

    /** Overtime / day-status override, with the approver recorded. */
    public function storeOverride(Request $request): JsonResponse
    {
        [$companyId] = $this->scope($request);
        $user = $request->user();
        abort_unless(in_array($user->role, ['company_admin', 'company_hr', 'super_admin'], true),
            403, 'Only company admin or HR can approve overtime.');

        $data = $request->validate([
            'worker_id' => 'required|integer|exists:workers,id',
            'work_date' => 'required|date',
            'ot_hours'  => 'nullable|numeric|min:0|max:24',
            'status'    => 'nullable|in:P,A,WO,H',
            'reason'    => 'nullable|string|max:255',
        ]);

        \DB::table('attendance_overrides')->updateOrInsert(
            ['worker_id' => $data['worker_id'], 'company_id' => $companyId, 'work_date' => $data['work_date']],
            [
                'ot_hours' => $data['ot_hours'] ?? null,
                'status'   => $data['status'] ?? null,
                'reason'   => $data['reason'] ?? null,
                'approved_by' => $user->id, 'approved_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]
        );

        $this->audit->log($user->id, 'attendance_override', Worker::class, $data['worker_id'], $data);

        return response()->json(['message' => 'Override saved and approved.']);
    }

    /** Company holiday calendar. */
    public function holidays(Request $request): JsonResponse
    {
        [$companyId] = $this->scope($request);

        return response()->json(\DB::table('company_holidays')
            ->where('company_id', $companyId)->orderBy('holiday_date')->get());
    }

    public function storeHoliday(Request $request): JsonResponse
    {
        [$companyId] = $this->scope($request);
        abort_unless(in_array($request->user()->role, ['company_admin', 'company_hr', 'super_admin'], true), 403);

        $data = $request->validate([
            'holiday_date' => 'required|date',
            'name'         => 'required|string|max:120',
            'paid'         => 'nullable|boolean',
        ]);

        \DB::table('company_holidays')->updateOrInsert(
            ['company_id' => $companyId, 'holiday_date' => Carbon::parse($data['holiday_date'])->toDateString()],
            ['name' => $data['name'], 'paid' => $data['paid'] ?? true,
             'created_at' => now(), 'updated_at' => now()]
        );

        return response()->json(['message' => 'Holiday saved.'], 201);
    }

    public function deleteHoliday(Request $request, int $id): JsonResponse
    {
        [$companyId] = $this->scope($request);
        abort_unless(in_array($request->user()->role, ['company_admin', 'company_hr', 'super_admin'], true), 403);
        \DB::table('company_holidays')->where('id', $id)->where('company_id', $companyId)->delete();

        return response()->json(['message' => 'Holiday removed.']);
    }

    /** GET /payroll/contractor-summary — what each contractor should bill. */
    public function contractorSummary(Request $request): JsonResponse
    {
        [$companyId, $from, $to, $opts] = $this->scope($request);
        $data = $this->payroll->register($companyId, $from, $to, $opts);

        $byVendor = [];
        foreach ($data['rows'] as $r) {
            $k = $r['vendor'] ?? '—';
            $byVendor[$k] ??= ['contractor' => $k, 'workers' => 0, 'present_days' => 0,
                               'ot_hours' => 0.0, 'base_amount' => 0.0, 'ot_amount' => 0.0, 'net_payable' => 0.0];
            $byVendor[$k]['workers']++;
            $byVendor[$k]['present_days'] += $r['present_days'];
            $byVendor[$k]['ot_hours']     += $r['ot_hours'];
            $byVendor[$k]['base_amount']  += $r['base_amount'];
            $byVendor[$k]['ot_amount']    += $r['ot_amount'];
            $byVendor[$k]['net_payable']  += $r['net_payable'];
        }
        $rows = array_map(fn ($v) => array_map(fn ($x) => is_float($x) ? round($x, 2) : $x, $v),
            array_values($byVendor));
        usort($rows, fn ($a, $b) => $b['net_payable'] <=> $a['net_payable']);

        return response()->json([
            'period' => $data['period'], 'rows' => $rows, 'totals' => $data['totals'],
        ]);
    }
}
