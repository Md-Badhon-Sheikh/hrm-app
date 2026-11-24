<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payslip extends Model
{
    protected $table = 'payslips';
    protected $guarded = [];
    public function user() { return $this->belongsTo(User::class); }
}
