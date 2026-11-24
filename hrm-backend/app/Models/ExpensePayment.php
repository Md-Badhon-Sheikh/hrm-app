<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpensePayment extends Model
{
    protected $table = 'expense_payments';
    protected $guarded = [];
    protected $casts = ['payment_date' => 'date'];
    public function claim() { return $this->belongsTo(ExpenseClaim::class, 'expense_claim_id'); }
}
