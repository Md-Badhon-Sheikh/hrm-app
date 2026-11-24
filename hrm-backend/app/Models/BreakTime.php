<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BreakTime extends Model
{
    protected $table = 'breaks';
    protected $guarded = [];

    protected $casts = ['date' => 'date'];
    public function user() { return $this->belongsTo(User::class); }
}
