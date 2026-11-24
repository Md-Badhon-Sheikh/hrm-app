<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyLeave extends Model
{
    protected $table = 'daily_leaves';
    protected $guarded = [];

    protected $casts = ['date' => 'date'];
    public function user() { return $this->belongsTo(User::class); }
}
