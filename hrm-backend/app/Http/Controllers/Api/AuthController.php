<?php

namespace App\Http\Controllers\Api;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends ApiController
{
    /** POST /login  {email, password, device_id, device_info} */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string',
            'password' => 'required|string',
        ]);

        // Allow login with email or phone
        $user = User::where('email', $request->email)->orWhere('phone', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return $this->fail('Invalid email or password', 401, ['email' => ['Invalid email or password']], self::NULL);
        }
        if (! $user->is_active) {
            return $this->fail('Your account is inactive. Please contact HR.', 403, null, self::NULL);
        }

        // "One user one device": when enabled, a user can only log in from the first device used.
        if (Setting::get('single_device_login', '0') === '1' && $request->filled('device_id')) {
            if ($user->device_id && $user->device_id !== $request->device_id) {
                return $this->fail('This account is already registered on another device. Please contact HR to reset it.', 403, ['email' => ['This account is already registered on another device.']], self::NULL);
            }
        }
        $user->forceFill([
            'device_id' => $request->device_id ?? $user->device_id,
            'device_info' => is_array($request->device_info) ? json_encode($request->device_info) : $request->device_info,
        ])->save();

        $token = $user->createToken('mobile-app')->plainTextToken;

        return $this->ok([
            'id' => $user->id,
            'company_id' => (int) $user->company_id,
            'is_admin' => (bool) $user->is_admin,
            'is_hr' => (bool) $user->is_hr,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => (string) $user->phone,
            'avatar' => $user->avatarUrl(),
            'token' => $token,
        ], 'Login successfully');
    }

    /** POST /check-token {token} — used by splash screen to decide auto-login. */
    public function checkToken(Request $request)
    {
        $token = $request->input('token') ?: $request->bearerToken();
        $valid = $token && PersonalAccessToken::findToken($token);

        return $valid
            ? $this->ok(null, 'Token is valid')
            : $this->fail('Invalid token', 401);
    }

    /** POST /reset-password {email} — emails a 6 digit code. */
    public function sendResetCode(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $user = User::where('email', $request->email)->first();
        if (! $user) {
            return $this->fail('No account found with this email', 404, ['email' => ['No account found with this email']]);
        }

        $code = (string) random_int(100000, 999999);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($code), 'created_at' => now()]
        );

        try {
            Mail::raw("Your password reset code is: $code\nThis code expires in 30 minutes.", function ($m) use ($user) {
                $m->to($user->email)->subject(config('app.name').' password reset code');
            });
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->ok(null, 'A verification code has been sent to your email');
    }

    /** POST /change-password {email, code, password, password_confirmation} */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required',
            'password' => 'required|min:6|confirmed',
        ]);

        $row = DB::table('password_reset_tokens')->where('email', $request->email)->first();
        if (! $row || ! Hash::check((string) $request->code, $row->token) || now()->diffInMinutes($row->created_at, true) > 30) {
            return $this->fail('Invalid or expired verification code', 422, ['code' => ['Invalid or expired verification code']]);
        }

        User::where('email', $request->email)->update(['password' => Hash::make($request->password)]);
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return $this->ok(null, 'Password changed successfully');
    }

    /** POST /user/password-update {current_password, password, password_confirmation} */
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password' => 'required|min:6|confirmed',
        ]);
        $user = $request->user();
        if (! Hash::check($request->current_password, $user->password)) {
            return $this->fail('Current password is incorrect', 422, ['current_password' => ['Current password is incorrect']]);
        }
        $user->update(['password' => $request->password]);

        return $this->ok(null, 'Password updated successfully');
    }

    /** POST /logout */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->ok(null, 'Logged out');
    }
}
