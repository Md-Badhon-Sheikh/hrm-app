<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetingParticipant extends Model
{
    protected $table = 'meeting_participants';
    protected $guarded = [];

    protected $casts = ['present_at' => 'datetime'];
    public function user() { return $this->belongsTo(User::class); }
}
