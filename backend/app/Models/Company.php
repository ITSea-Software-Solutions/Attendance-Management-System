<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_INACTIVE = 'inactive';

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
        'logo_path',
        'status',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    // ─── Relationships ─────────────────────────────────────────────────────────

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function vendors()
    {
        return $this->belongsToMany(Vendor::class, 'company_vendors')
            ->withPivot(['status', 'approved_at', 'approved_by', 'rejection_reason', 'details_consent_at'])
            ->withTimestamps();
    }

    public function approvedVendors()
    {
        return $this->vendors()->wherePivot('status', 'approved');
    }

    public function workerAssignments()
    {
        return $this->hasMany(WorkerAssignment::class);
    }

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
