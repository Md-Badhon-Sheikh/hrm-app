<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $table = 'attendances';
    protected $guarded = [];

    protected $casts = ['date' => 'date', 'check_in' => 'datetime', 'check_out' => 'datetime'];
    public function user() { return $this->belongsTo(User::class); }
}
