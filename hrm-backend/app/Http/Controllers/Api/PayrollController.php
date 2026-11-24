<?php

namespace App\Http\Controllers\Api;

use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\Payslip;
use App\Models\Setting;
use App\Support\SimplePdf;
use App\Support\Status;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class PayrollController extends ApiController
{
    /** POST /report/payslip/list {year} */
    public function list(Request $request)
    {
        $year = (int) ($request->input('year') ?: now()->year);
        $user = $this->targetUser($request);
        $rows = Payslip::where('user_id', $user->id)->where('month', 'like', "$year-%")->orderByDesc('month')->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'month' => Carbon::createFromFormat('Y-m', $p->month)->format('F Y'),
                'is_calculated' => (bool) $p->is_calculated,
                'salary' => (int) round((float) $p->salary),
                'payslip_link' => URL::temporarySignedRoute('payslip.pdf', now()->addDays(7), ['payslip' => $p->id]),
            ]);

        return $this->ok($rows, 'Payslip list');
    }

    /** GET /payslip/{payslip}/pdf (signed URL, no token needed — the app downloads it with plain Dio) */
    public function pdf(Payslip $payslip)
    {
        $u = $payslip->user()->first() ?? abort(404);
        $u->load('department', 'designation');
        $m = Carbon::createFromFormat('Y-m', $payslip->month)->startOfMonth();
        $present = Attendance::where('user_id', $u->id)->whereBetween('date', [$m, $m->copy()->endOfMonth()])->distinct('date')->count('date');
        $late = Attendance::where('user_id', $u->id)->whereBetween('date', [$m, $m->copy()->endOfMonth()])->where('in_status', 'L')->count();
        $leave = LeaveRequest::where('user_id', $u->id)->where('status_id', Status::APPROVED)->whereBetween('leave_from', [$m, $m->copy()->endOfMonth()])->sum('days');
        $cur = Setting::get('currency_code', 'BDT');
        $fmt = fn ($v) => $cur.' '.number_format((float) $v, 2);

        $pdf = (new SimplePdf)
            ->rect(0, 0, 595, 90, 0.12)
            ->text(Setting::get('company_name', config('app.name')), 40, 45, 20, true)
            ->text('Payslip - '.$m->format('F Y'), 40, 70, 12)
            ->rect(40, 120, 515, 110)
            ->text('Employee', 55, 145, 10, true)->text($u->name, 180, 145, 10)
            ->text('Employee ID', 55, 165, 10, true)->text((string) $u->employee_id, 180, 165, 10)
            ->text('Designation', 55, 185, 10, true)->text((string) $u->designation?->title, 180, 185, 10)
            ->text('Department', 55, 205, 10, true)->text((string) $u->department?->title, 180, 205, 10)
            ->text('Present days', 330, 145, 10, true)->text((string) $present, 450, 145, 10)
            ->text('Late days', 330, 165, 10, true)->text((string) $late, 450, 165, 10)
            ->text('Leave days', 330, 185, 10, true)->text((string) (int) $leave, 450, 185, 10)
            ->text('Bank', 330, 205, 10, true)->text(trim($u->bank_name.' '.$u->bank_account), 450, 205, 10)
            ->text('Net salary', 55, 275, 14, true)->text($fmt($payslip->salary), 330, 275, 14, true)
            ->text('This is a system generated payslip and does not require a signature.', 40, 800, 8);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="payslip-'.$payslip->month.'.pdf"',
        ]);
    }
}
