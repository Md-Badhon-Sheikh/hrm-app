<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conference extends Model
{
    protected $table = 'conferences';
    protected $guarded = [];

    protected $casts = ['start' => 'datetime', 'end' => 'datetime'];
    public function members() { return $this->belongsToMany(User::class, 'conference_members'); }
}
