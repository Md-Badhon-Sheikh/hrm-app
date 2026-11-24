<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    protected $table = 'leave_requests';
    protected $guarded = [];

    protected $casts = ['apply_date' => 'date', 'leave_from' => 'date', 'leave_to' => 'date'];
    public function user() { return $this->belongsTo(User::class); }
    public function leaveType() { return $this->belongsTo(LeaveType::class); }
    public function substitute() { return $this->belongsTo(User::class, 'substitute_id'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
    public function referrer() { return $this->belongsTo(User::class, 'referred_by'); }
}
