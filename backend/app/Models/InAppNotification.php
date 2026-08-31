<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InAppNotification extends Model
{
    protected $table = 'notifications_inapp';

    protected $fillable = ['user_id', 'type', 'title', 'body', 'data', 'read_at'];

    protected $casts = ['data' => 'array', 'read_at' => 'datetime'];
}
