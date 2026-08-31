<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GatePass extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DENIED   = 'denied';
    public const STATUS_EXPIRED  = 'expired';

    protected $fillable = [
        'code', 'company_id', 'host_id', 'guest_name', 'guest_phone',
        'purpose', 'vehicle_number', 'status', 'decided_via', 'decision_note', 'decided_at',
        'entry_at', 'exit_at', 'location_name', 'created_by',
    ];

    protected $hidden = ['photo_path', 'vehicle_photo_path'];

    protected $appends = ['has_photo', 'has_vehicle_photo'];

    protected $casts = [
        'decided_at' => 'datetime',
        'entry_at'   => 'datetime',
        'exit_at'    => 'datetime',
    ];

    public function getHasPhotoAttribute(): bool
    {
        return ! empty($this->photo_path);
    }

    public function getHasVehiclePhotoAttribute(): bool
    {
        return ! empty($this->vehicle_photo_path);
    }

    public function host()
    {
        return $this->belongsTo(CompanyHost::class, 'host_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
