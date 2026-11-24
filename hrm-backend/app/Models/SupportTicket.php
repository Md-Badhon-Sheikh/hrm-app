<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    protected $table = 'support_tickets';
    protected $guarded = [];
    public function user() { return $this->belongsTo(User::class); }
}
