<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notice extends Model
{
    protected $table = 'notices';
    protected $guarded = [];
    public function user() { return $this->belongsTo(User::class); }

    protected $casts = ['date' => 'date'];
}
