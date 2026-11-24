<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Visit extends Model
{
    protected $table = 'visits';
    protected $guarded = [];

    protected $casts = ['date' => 'date', 'started_at' => 'datetime', 'reached_at' => 'datetime'];
    public function user() { return $this->belongsTo(User::class); }
    public function images() { return $this->hasMany(VisitImage::class); }
    public function notes() { return $this->hasMany(VisitNote::class)->latest(); }
    public function schedules() { return $this->hasMany(VisitSchedule::class)->latest(); }
}
