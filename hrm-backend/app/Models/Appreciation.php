<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appreciation extends Model
{
    protected $table = 'appreciations';
    protected $guarded = [];

    public function user() { return $this->belongsTo(User::class); }
    public function by() { return $this->belongsTo(User::class, 'appreciate_by'); }
}
