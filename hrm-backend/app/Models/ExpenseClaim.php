<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseClaim extends Model
{
    protected $table = 'expense_claims';
    protected $guarded = [];
    public function user() { return $this->belongsTo(User::class); }

    protected $casts = ['claim_date' => 'date'];
    public function expenses() { return $this->hasMany(Expense::class); }
    public function payments() { return $this->hasMany(ExpensePayment::class); }
}
