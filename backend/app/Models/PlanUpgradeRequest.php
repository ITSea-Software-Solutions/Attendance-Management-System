<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanUpgradeRequest extends Model
{
    protected $hidden = ['payment_proof_path'];

    protected $appends = ['has_payment_proof'];

    protected $fillable = [
        'org_type',
        'org_id',
        'current_plan',
        'requested_plan',
        'months',
        'status',
        'requested_by',
        'payment_method',
        'payment_reference',
        'amount',
        'payment_proof_path',
        'paid_at',
        'note',
        'decided_by',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function getHasPaymentProofAttribute(): bool
    {
        return ! empty($this->payment_proof_path);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** The company or vendor this request belongs to. */
    public function org()
    {
        return $this->org_type === 'company'
            ? Company::find($this->org_id)
            : Vendor::find($this->org_id);
    }
}
