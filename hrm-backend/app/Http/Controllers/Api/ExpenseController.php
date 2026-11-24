<?php

namespace App\Http\Controllers\Api;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Support\Files;
use App\Support\Notifier;
use App\Support\Status;
use Illuminate\Http\Request;

/**
 * Expenses. The app has two flows and both are supported:
 *  - /accounts/expense/*  : simple expense requests with approval & payment status
 *  - /expense/*           : add expenses → send them together as a claim → payments
 */
class ExpenseController extends ApiController
{
    private function money($v): string
    {
        return number_format((float) $v, 2, '.', '');
    }

    private function mine(Request $request)
    {
        return Expense::with('category')->where('user_id', $request->user()->id);
    }

    /** GET /accounts/expense/category-list */
    public function categories()
    {
        return $this->ok(['categories' => ExpenseCategory::orderBy('name')->get(['id', 'name'])], 'Expense categories');
    }

    /** POST /accounts/expense/add (multipart) {category, date, amount, description, reference, attachment} */
    public function store(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'attachment' => 'nullable|file|max:5120',
            'attachment_file' => 'nullable|file|max:5120',
        ]);
        $u = $request->user();
        $e = Expense::create([
            'user_id' => $u->id,
            'expense_category_id' => $request->input('category') ?: $request->input('expense_category_id'),
            'date' => $this->date($request->input('date'))->toDateString(),
            'amount' => $request->amount,
            'remarks' => $request->input('description') ?? $request->input('remarks'),
            'reference' => $request->input('reference'),
            'attachment' => Files::store($request->file('attachment') ?? $request->file('attachment_file'), 'expense'),
        ]);

        return $this->ok(['id' => $e->id], 'Expense added successfully');
    }

    /** POST /accounts/expense/list {date (month), payment, status} */
    public function legacyList(Request $request)
    {
        $q = $this->mine($request)->latest('date');
        if ($request->filled('date')) {
            $m = $this->month($request->date);
            $q->whereBetween('date', [$m, $m->copy()->endOfMonth()]);
        }
        $paymentTypes = [['id' => 1, 'name' => 'Paid'], ['id' => 2, 'name' => 'Unpaid']];
        $statuses = [['id' => 1, 'name' => 'Approved'], ['id' => 2, 'name' => 'Pending'], ['id' => 3, 'name' => 'Rejected']];
        if ($p = $request->input('payment')) {
            $q->where('payment', is_numeric($p) ? ((int) $p === 1 ? 'paid' : 'unpaid') : strtolower($p));
        }
        if ($s = $request->input('status')) {
            $q->where('status', is_numeric($s) ? ['1' => 'approved', '2' => 'pending', '3' => 'rejected'][(string) $s] ?? 'pending' : strtolower($s));
        }

        return response()->json([
            'result' => true,
            'message' => 'Expense list',
            'status' => 200,
            'payment_type' => $paymentTypes,
            'expanse_status' => $statuses,
            'data' => $q->get()->map(fn ($e) => [
                'id' => $e->id,
                'category' => $e->category?->name ?? 'General',
                'requested_amount' => $this->money($e->amount),
                'approved_amount' => $this->money($e->approved_amount ?? 0),
                'date_show' => $e->date->format('d M Y'),
                'date_db' => $e->date->toDateString(),
                'payment' => ucfirst($e->payment),
                'payment_color' => Status::color(ucfirst($e->payment)),
                'status' => ucfirst($e->status),
                'status_color' => Status::color(ucfirst($e->status)),
                'reason' => (string) $e->remarks,
            ]),
        ]);
    }

    /** GET /accounts/expense/view/{id} */
    public function legacyShow(Request $request, int $id)
    {
        $e = $this->mine($request)->findOrFail($id);

        return $this->ok([
            'id' => $e->id,
            'category' => $e->category?->name ?? 'General',
            'requested_amount' => $this->money($e->amount),
            'approved_amount' => $this->money($e->approved_amount ?? 0),
            'date_show' => $e->date->format('d M Y'),
            'date_db' => $e->date->toDateString(),
            'payment' => ucfirst($e->payment),
            'status' => ucfirst($e->status),
            'reason' => (string) $e->remarks,
        ], 'Expense details');
    }

    /** POST /expense/list {month} — expenses not yet claimed */
    public function list(Request $request)
    {
        $m = $this->month($request->input('month'));
        $q = $this->mine($request)->whereNull('expense_claim_id')->whereBetween('date', [$m, $m->copy()->endOfMonth()])->latest('date');
        $total = (clone $q)->sum('amount');
        $p = $q->paginate(50);

        return $this->ok([
            'total_amount' => $this->money($total),
            'expense_list' => [
                'data' => collect($p->items())->map(fn ($e) => [
                    'id' => $e->id,
                    'category' => $e->category?->name ?? 'General',
                    'remarks' => (string) $e->remarks,
                    'amount' => $this->money($e->amount),
                ]),
                'pagination' => $this->pagination($p),
            ],
        ], 'Expense list');
    }

    /** GET /expense/single-expense/{id} */
    public function show(Request $request, int $id)
    {
        $e = $this->mine($request)->findOrFail($id);

        return $this->ok([
            'month' => $e->date->format('F Y'),
            'date' => $e->date->format('d M Y'),
            'category' => $e->category?->name ?? 'General',
            'amount' => $this->money($e->amount),
            'remarks' => (string) $e->remarks,
            'attachment_file' => Files::url($e->attachment),
        ], 'Expense details');
    }

    /** POST /expense/update/{id} (multipart) */
    public function update(Request $request, int $id)
    {
        $e = $this->mine($request)->findOrFail($id);
        if ($e->expense_claim_id) {
            return $this->fail('This expense is already claimed and cannot be changed.', 400);
        }
        $data = array_filter([
            'remarks' => $request->input('remarks'),
            'amount' => $request->input('amount'),
            'expense_category_id' => $request->input('expense_category_id') ?? $request->input('category'),
        ], fn ($v) => $v !== null && $v !== '');
        if ($f = Files::store($request->file('attachment_file'), 'expense')) {
            $data['attachment'] = $f;
        }
        $e->update($data);

        return $this->ok(null, 'Expense updated');
    }

    /** DELETE /expense/delete/{id} */
    public function destroy(Request $request, int $id)
    {
        $e = $this->mine($request)->findOrFail($id);
        if ($e->expense_claim_id) {
            return $this->fail('A claimed expense cannot be deleted.', 400);
        }
        $e->delete();

        return $this->ok(null, 'Expense deleted');
    }

    /** POST /expense/send-claim {remarks} — bundles all unclaimed expenses into one claim */
    public function sendClaim(Request $request)
    {
        $u = $request->user();
        $items = Expense::where('user_id', $u->id)->whereNull('expense_claim_id')->get();
        if ($items->isEmpty()) {
            return $this->fail('You have no expenses to claim.', 400);
        }
        $total = $items->sum('amount');
        $claim = ExpenseClaim::create([
            'user_id' => $u->id,
            'invoice_number' => 'EXP-'.now()->format('ymd').'-'.str_pad((string) (ExpenseClaim::max('id') + 1), 4, '0', STR_PAD_LEFT),
            'claim_date' => today()->toDateString(),
            'remarks' => $request->input('remarks'),
            'payable_amount' => $total,
            'due_amount' => $total,
        ]);
        Expense::whereIn('id', $items->pluck('id'))->update(['expense_claim_id' => $claim->id]);
        Notifier::hr('New expense claim', "{$u->name} claimed ".$this->money($total), 'expense', $u->id, $claim->id);

        return $this->ok(null, 'Claim sent successfully');
    }

    /** POST /expense/claim-history {month} */
    public function claims(Request $request)
    {
        $q = ExpenseClaim::where('user_id', $request->user()->id)->latest('claim_date');
        if ($request->filled('month')) {
            $m = $this->month($request->month);
            $q->whereBetween('claim_date', [$m, $m->copy()->endOfMonth()]);
        }
        $p = $q->paginate(20);

        return $this->ok([
            'data' => collect($p->items())->map(fn ($c) => [
                'id' => $c->id,
                'invoice_number' => $c->invoice_number,
                'claim_date' => $c->claim_date->format('d M Y'),
                'remarks' => (string) $c->remarks,
                'payable_amount' => $this->money($c->payable_amount),
                'due_amount' => $this->money($c->due_amount),
                'attachment_file_id' => Files::url($c->attachment),
                'status_id' => Status::name((int) $c->status_id),
            ]),
            'links' => $this->links($p),
            'pagination' => $this->pagination($p),
        ], 'Claim history');
    }

    /** POST /expense/claim-details/{id} */
    public function claim(Request $request, int $id)
    {
        $c = ExpenseClaim::with('expenses.category')->where('user_id', $request->user()->id)->findOrFail($id);

        return $this->ok([
            'month' => $c->claim_date->format('F Y'),
            'total_amount' => $this->money($c->payable_amount),
            'claim_data' => ['data' => $c->expenses->map(fn ($e) => [
                'id' => $e->id,
                'date' => $e->date->format('d M Y'),
                'category' => $e->category?->name ?? 'General',
                'amount' => $this->money($e->amount),
            ])],
        ], 'Claim details');
    }

    /** POST /expense/payment-history {month} */
    public function payments(Request $request)
    {
        $q = ExpenseClaim::with('payments')->where('user_id', $request->user()->id)->where('paid_amount', '>', 0)->latest('updated_at');
        if ($request->filled('month')) {
            $m = $this->month($request->month);
            $q->whereBetween('updated_at', [$m, $m->copy()->endOfMonth()->endOfDay()]);
        }

        return $this->ok(['data' => $q->get()->map(fn ($c) => [
            'id' => $c->id,
            'invoice_number' => $c->invoice_number,
            'payment_date' => optional($c->payments->sortByDesc('payment_date')->first())->payment_date ?? $c->updated_at->toDateString(),
            'payable_amount' => $this->money($c->payable_amount),
            'paid_amount' => $this->money($c->paid_amount),
            'due_amount' => $this->money($c->due_amount),
            'status' => (float) $c->due_amount <= 0 ? 'Paid' : 'Partially Paid',
            'color_code' => (float) $c->due_amount <= 0 ? '#16A34A' : '#F59E0B',
        ])], 'Payment history');
    }
}
