<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManualAttendanceRequest extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'worker_id', 'company_id', 'vendor_id',
        'work_date', 'in_at', 'out_at', 'location_name', 'reason',
        'status', 'decision_note', 'requested_by', 'decided_by', 'decided_at',
        'in_log_id', 'out_log_id',
    ];

    /**
     * Formatted casts, not bare date/datetime, on purpose. The default
     * serializer emits UTC, so a shift entered as 25 Aug 08:30 IST leaves the
     * API as "2026-08-24T03:00:00Z" — the day before, at the wrong hour. On a
     * wage-bearing attendance record that is not a formatting nit: it is the
     * API reporting a different day from the one that was worked. These emit
     * the local date and time the entry was actually made for.
     */
    protected $casts = [
        'work_date'  => 'date:Y-m-d',
        'in_at'      => 'datetime:Y-m-d H:i',
        'out_at'     => 'datetime:Y-m-d H:i',
        'decided_at' => 'datetime:Y-m-d H:i',
    ];

    public function worker()      { return $this->belongsTo(Worker::class); }
    public function company()     { return $this->belongsTo(Company::class); }
    public function vendor()      { return $this->belongsTo(Vendor::class); }
    public function requestedBy() { return $this->belongsTo(User::class, 'requested_by'); }
    public function decidedBy()   { return $this->belongsTo(User::class, 'decided_by'); }

    /** Hours the entry accounts for — null while the worker is still inside. */
    public function getHoursAttribute(): ?float
    {
        if (! $this->in_at || ! $this->out_at) {
            return null;
        }
        return round($this->in_at->diffInMinutes($this->out_at) / 60, 2);
    }

    protected $appends = ['hours'];
}
