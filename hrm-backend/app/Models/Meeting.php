<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
    protected $table = 'meetings';
    protected $guarded = [];
    public function attendees() { return $this->belongsToMany(User::class, 'meeting_participants')->withPivot('is_agree', 'is_present', 'present_at'); }

    protected $casts = ['date' => 'date'];
    public function user() { return $this->belongsTo(User::class); }
    public function participants() { return $this->hasMany(MeetingParticipant::class); }
}
