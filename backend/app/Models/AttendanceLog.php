<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    use HasFactory;

    public const TYPE_IN  = 'IN';
    public const TYPE_OUT = 'OUT';

    public const METHOD_FINGERPRINT = 'fingerprint';
    public const METHOD_PHOTO       = 'photo';
    public const METHOD_MANUAL      = 'manual';
    public const METHOD_ID_CARD     = 'id_card';

    public const LOCATION_MAIN_GATE  = 'main_gate';
    public const LOCATION_DEPARTMENT = 'department';
    public const LOCATION_CHECKPOINT = 'checkpoint';

    public const DEFAULT_LOCATION_NAME = 'Main Gate';

    protected $fillable = [
        'parent_id',
        'worker_id',
        'company_id',
        'assignment_id',
        'type',
        'marked_at',
        'marked_by',
        'method',
        'fingerprint_score',
        'face_score',
        'auth_proof_path',
        'override_reason',
        'device_id',
        'gate',
        'location_type',
        'location_name',
        'ip_address',
        'is_valid',
        'invalidation_reason',
    ];

    protected $hidden  = ['auth_proof_path'];
    protected $appends = ['has_proof_photo'];

    protected $casts = [
        'marked_at'        => 'datetime',
        'is_valid'         => 'boolean',
        'proof_face_match' => 'boolean',
        'proof_face_score' => 'float',
    ];

    // ─── Relationships ─────────────────────────────────────────────────────────

    public function worker()
    {
        return $this->belongsTo(Worker::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function assignment()
    {
        return $this->belongsTo(WorkerAssignment::class);
    }

    public function markedBy()
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function parent()
    {
        return $this->belongsTo(AttendanceLog::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(AttendanceLog::class, 'parent_id');
    }

    // ─── Computed ─────────────────────────────────────────────────────────────

    public function getHasProofPhotoAttribute(): bool
    {
        return !empty($this->auth_proof_path);
    }

    public function getMethodLabelAttribute(): string
    {
        return match ($this->method) {
            self::METHOD_FINGERPRINT => 'Fingerprint',
            self::METHOD_PHOTO       => 'Photo',
            self::METHOD_ID_CARD     => 'ID Card',
            default                  => 'Manual',
        };
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeToday($query)
    {
        return $query->whereDate('marked_at', today());
    }

    public function scopeValid($query)
    {
        return $query->where('is_valid', true);
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeAtLocation($query, string $locationName)
    {
        return $query->where('location_name', $locationName);
    }
}
