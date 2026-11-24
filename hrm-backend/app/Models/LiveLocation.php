<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveLocation extends Model
{
    protected $table = 'live_locations';
    protected $guarded = [];
    public function user() { return $this->belongsTo(User::class); }
}
