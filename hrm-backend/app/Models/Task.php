<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $table = 'tasks';
    protected $guarded = [];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];
    public function members() { return $this->belongsToMany(User::class, 'task_members'); }
    public function supervisor() { return $this->belongsTo(User::class, 'supervisor_id'); }
}
