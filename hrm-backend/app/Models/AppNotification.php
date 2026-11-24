<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    protected $table = 'app_notifications';
    protected $guarded = [];

    use \Illuminate\Database\Eloquent\Concerns\HasUuids;
    protected $casts = ['read_at' => 'datetime'];
    public function sender() { return $this->belongsTo(User::class, 'sender_id'); }
}
