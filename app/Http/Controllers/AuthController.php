<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\OtpCode;
use App\Mail\OtpMail;
use App\Mail\PasswordResetMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function sendOtp(Request $request)
    {
        $request->validate([
            'email'      => 'required|email|unique:users,email',
            'first_name' => 'required|string',
        ]);

        OtpCode::where('email', $request->email)->delete();

        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        OtpCode::create([
            'email'      => $request->email,
            'code'       => $otp,
            'expires_at' => now()->addMinutes(10),
            'used'       => false,
        ]);

        Mail::to($request->email)->send(new OtpMail($otp, $request->first_name));

        return response()->json([
            'success' => true,
            'message' => 'OTP sent to your email.',
        ]);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code'  => 'required|string|size:6',
        ]);

        $otp = OtpCode::where('email', $request->email)
            ->where('code',  $request->code)
            ->where('used',  false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$otp) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP.',
            ], 422);
        }

        return response()->json(['success' => true, 'message' => 'OTP verified.']);
    }

    public function register(Request $request)
    {
        $request->validate([
            'last_name'      => 'required|string|max:255',
            'first_name'     => 'required|string|max:255',
            'middle_initial' => 'nullable|string|max:2',
            'phone_number'   => ['required', 'digits:11', 'regex:/^09[0-9]{9}$/'],
            'email'          => 'required|email|unique:users,email',
            'password'       => 'required|min:8',
            'role'           => 'required|in:tenant,landlord',
            'otp_code'       => 'required|string|size:6',
        ]);

        $otp = OtpCode::where('email', $request->email)
            ->where('code',  $request->otp_code)
            ->where('used',  false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$otp) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP.',
            ], 422);
        }

        $otp->update(['used' => true]);

        $fullName = $request->first_name . ' ' .
            ($request->middle_initial ? $request->middle_initial . '. ' : '') .
            $request->last_name;

        $user = User::create([
            'name'                => $fullName,
            'last_name'           => $request->last_name,
            'first_name'          => $request->first_name,
            'middle_initial'      => $request->middle_initial,
            'phone_number'        => $request->phone_number,
            'email'               => $request->email,
            'password'            => Hash::make($request->password),
            'role'                => $request->role,
            'verification_status' => 'unverified',
        ]);

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'success' => true,
            'data'    => ['token' => $token, 'user' => $user],
            'message' => 'Registration successful! Welcome to TierHome.',
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if (!$token = JWTAuth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Invalid email or password.',
            ], 401);
        }

        $user = auth()->user();

        if (!$user->is_active) {
            JWTAuth::setToken($token)->invalidate();
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'This account has been deactivated. Please contact support if you believe this is an error.',
                'deactivated' => true,
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => ['token' => $token, 'user' => $user],
            'message' => 'Login successful',
        ]);
    }

    public function logout()
    {
        JWTAuth::invalidate(JWTAuth::getToken());
        return response()->json(['success' => true, 'message' => 'Logged out']);
    }

    public function me()
    {
        return response()->json(['success' => true, 'data' => auth()->user()]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'success' => true,
                'message' => 'If that email exists, a reset link has been sent.',
            ]);
        }

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        $token    = Str::random(64);
        $resetUrl = config('app.frontend_url') . '/reset-password?token=' . $token . '&email=' . urlencode($request->email);

        DB::table('password_reset_tokens')->insert([
            'email'      => $request->email,
            'token'      => Hash::make($token),
            'created_at' => now(),
        ]);

        Mail::to($request->email)->send(new PasswordResetMail($resetUrl, $user->first_name));

        return response()->json([
            'success' => true,
            'message' => 'If that email exists, a reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email'                 => 'required|email',
            'token'                 => 'required|string',
            'password'              => 'required|min:8|confirmed',
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$record || !Hash::check($request->token, $record->token)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset link.',
            ], 422);
        }

        if (now()->diffInMinutes($record->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json([
                'success' => false,
                'message' => 'Reset link expired. Please request a new one.',
            ], 422);
        }

        User::where('email', $request->email)->update([
            'password' => Hash::make($request->password),
        ]);

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully!',
        ]);
    }
}