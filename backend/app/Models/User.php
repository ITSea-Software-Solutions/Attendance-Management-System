<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    public const ROLE_SUPER_ADMIN    = 'super_admin';
    public const ROLE_COMPANY_ADMIN  = 'company_admin';
    public const ROLE_COMPANY_HR     = 'company_hr';
    public const ROLE_COMPANY_GATE   = 'company_gate';
    public const ROLE_VENDOR_ADMIN   = 'vendor_admin';
    public const ROLE_VENDOR_OP      = 'vendor_operator';

    public const ROLES = [
        self::ROLE_SUPER_ADMIN,
        self::ROLE_COMPANY_ADMIN,
        self::ROLE_COMPANY_HR,
        self::ROLE_COMPANY_GATE,
        self::ROLE_VENDOR_ADMIN,
        self::ROLE_VENDOR_OP,
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'company_id',
        'vendor_id',
        'phone',
        'is_active',
        'location_type',
        'location_name',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isCompanyUser(): bool
    {
        return in_array($this->role, [self::ROLE_COMPANY_ADMIN, self::ROLE_COMPANY_HR, self::ROLE_COMPANY_GATE]);
    }

    public function isVendorUser(): bool
    {
        return in_array($this->role, [self::ROLE_VENDOR_ADMIN, self::ROLE_VENDOR_OP]);
    }

    public function isVendorAdmin(): bool
    {
        return $this->role === self::ROLE_VENDOR_ADMIN;
    }

    public function isHrUser(): bool
    {
        return $this->role === self::ROLE_COMPANY_HR;
    }

    public function isGateUser(): bool
    {
        return $this->role === self::ROLE_COMPANY_GATE;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
