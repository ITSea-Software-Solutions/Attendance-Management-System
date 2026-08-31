<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** HR-maintained list of people authorised to receive visitors. */
class CompanyHost extends Model
{
    protected $fillable = ['company_id', 'name', 'phone', 'position', 'department', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
