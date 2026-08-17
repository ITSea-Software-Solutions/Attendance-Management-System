<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use HasFactory, SoftDeletes;

    protected $casts = ['settings' => 'array'];

    protected $fillable = [
        'name',
        'code',
        'address',
        'city',
        'state',
        'pin',
        'contact_person',
        'contact_email',
        'contact_phone',
        'gst_number',
        'pan_number',
        'license_number',
        'status',
    ];

    // ─── Relationships ─────────────────────────────────────────────────────────

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function companies()
    {
        return $this->belongsToMany(Company::class, 'company_vendors')
            ->withPivot(['status', 'approved_at', 'approved_by', 'rejection_reason', 'details_consent_at'])
            ->withTimestamps();
    }

    public function approvedCompanies()
    {
        return $this->companies()->wherePivot('status', 'approved');
    }

    public function workers()
    {
        return $this->hasMany(Worker::class);
    }

    public function activeWorkers()
    {
        return $this->workers()->where('status', Worker::STATUS_ACTIVE);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
