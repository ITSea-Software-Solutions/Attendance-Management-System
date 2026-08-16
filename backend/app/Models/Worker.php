<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Worker extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING  = 'pending';  // registered but fingerprint not yet enrolled
    public const STATUS_ACTIVE   = 'active';   // fingerprint enrolled — ready for attendance
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_BLOCKED  = 'blocked';

    /**
     * Mass-assignable = user-supplied profile fields ONLY.
     * Sensitive/system fields (status, registered_by, aadhaar_hash,
     * aadhaar_pdf_path, photo_path, fingerprint_*, face_*) are deliberately
     * NOT fillable — they are written via forceFill() at explicit,
     * authorized call sites so request payloads can never mass-assign them.
     */
    protected $fillable = [
        'vendor_id',
        'name',
        'dob',
        'gender',
        'address',
        'city',
        'state',
        'pin',
        'phone',
        'mobile',
        'email',
        'aadhaar_number_masked',
        'aadhaar_data_extracted',
        'notes',
    ];

    protected $hidden = [
        'aadhaar_pdf_path',
        'aadhaar_hash',
        'fingerprint_template',
    ];

    protected $appends = ['photo_url', 'has_aadhaar_pdf'];

    protected $casts = [
        'dob'                    => 'date',
        'aadhaar_data_extracted'  => 'array',
        'fingerprint_enrolled_at' => 'datetime',
        'face_descriptor'         => 'array',
        'face_enrolled_at'        => 'datetime',
        'email_verified_at'       => 'datetime',
        'phone_verified_at'       => 'datetime',
    ];

    // ─── Relationships ─────────────────────────────────────────────────────────

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function assignments()
    {
        return $this->hasMany(WorkerAssignment::class);
    }

    public function activeAssignments()
    {
        return $this->assignments()
            ->where('status', WorkerAssignment::STATUS_ACTIVE)
            ->where('start_date', '<=', today())
            ->where('end_date', '>=', today());
    }

    public function idDocuments()
    {
        return $this->hasMany(WorkerIdDocument::class);
    }

    public function primaryIdDocument()
    {
        return $this->hasOne(WorkerIdDocument::class)->where('is_primary', true);
    }

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function registeredBy()
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    // ─── Computed ─────────────────────────────────────────────────────────────

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path
            ? route('worker.photo', ['worker' => $this->id])
            : null;
    }

    public function getHasAadhaarPdfAttribute(): bool
    {
        return !empty($this->attributes['aadhaar_pdf_path'] ?? null);
    }

    public function hasFingerprint(): bool
    {
        return !empty($this->fingerprint_template);
    }

    // Active = fingerprint enrolled (any ID document is acceptable)
    public function isEnrollmentComplete(): bool
    {
        return $this->hasFingerprint();
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForVendor($query, int $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }
}
