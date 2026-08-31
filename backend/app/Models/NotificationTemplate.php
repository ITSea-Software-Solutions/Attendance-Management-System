<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $fillable = [
        'key', 'channel', 'org_type', 'org_id', 'subject', 'body', 'updated_by',
    ];
}
