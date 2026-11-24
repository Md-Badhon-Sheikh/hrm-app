<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $table = 'expenses';
    protected $guarded = [];
    public function user() { return $this->belongsTo(User::class); }

    protected $casts = ['date' => 'date'];
    public function category() { return $this->belongsTo(ExpenseCategory::class, 'expense_category_id'); }
    public function claim() { return $this->belongsTo(ExpenseClaim::class, 'expense_claim_id'); }
}
