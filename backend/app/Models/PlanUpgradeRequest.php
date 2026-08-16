<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanUpgradeRequest extends Model
{
    protected $fillable = [
        'org_type',
        'org_id',
        'current_plan',
        'requested_plan',
        'status',
        'requested_by',
        'note',
        'decided_by',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

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
