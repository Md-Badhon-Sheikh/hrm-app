<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $table = 'appointments';
    protected $guarded = [];

    protected $casts = ['date' => 'date', 'present_at' => 'datetime', 'started_at' => 'datetime', 'ended_at' => 'datetime'];
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function withUser() { return $this->belongsTo(User::class, 'appoinment_with'); }
}
