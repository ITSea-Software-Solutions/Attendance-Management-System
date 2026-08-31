<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WageChangeRequest extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'worker_id', 'company_id', 'vendor_id',
        'wage_type', 'daily_rate', 'monthly_rate', 'wage_components',
        'current_wage_type', 'current_daily_rate', 'current_monthly_rate',
        'status', 'note', 'decision_note', 'requested_by', 'decided_by', 'decided_at',
    ];

    protected $casts = [
        'wage_components' => 'array',
        'daily_rate'      => 'decimal:2',
        'monthly_rate'    => 'decimal:2',
        'decided_at'      => 'datetime',
    ];

    public function worker()  { return $this->belongsTo(Worker::class); }
    public function company() { return $this->belongsTo(Company::class); }
    public function vendor()  { return $this->belongsTo(Vendor::class); }
    public function requestedBy() { return $this->belongsTo(User::class, 'requested_by'); }
}
