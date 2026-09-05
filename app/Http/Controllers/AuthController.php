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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    /**
     * Verifies a reCAPTCHA v3 token against Google's siteverify endpoint.
     * Unlike v2 (simple pass/fail), v3 returns a risk score from 0.0 (likely
     * bot) to 1.0 (likely human) plus the action name the token was issued
     * for. Both are checked here:
     *   - score >= threshold (0.5 is Google's own recommended default)
     *   - action matches what was expected for this specific endpoint,
     *     so a token generated for "register" can't be replayed on "login"
     *
     * Fails closed: any network error, missing config, low score, or
     * action mismatch results in verification failure.
     */
    private function verifyRecaptcha(?string $token, string $expectedAction, float $threshold = 0.5): bool
    {
        $secretKey = config('services.recaptcha.secret_key');

        if (!$secretKey) {
            Log::warning('AuthController: RECAPTCHA_SECRET_KEY not configured — rejecting request.');
            return false;
        }

        if (!$token) {
            return false;
        }

        try {
            $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret'   => $secretKey,
                'response' => $token,
            ]);

            if (!$response->successful() || $response->json('success') !== true) {
                return false;
            }

            $score  = (float) $response->json('score', 0);
            $action = $response->json('action');

            if ($score < $threshold) {
                Log::info("AuthController: reCAPTCHA score too low ({$score}) for action '{$expectedAction}'.");
                return false;
            }

            if ($action !== $expectedAction) {
                Log::warning("AuthController: reCAPTCHA action mismatch — expected '{$expectedAction}', got '{$action}'.");
                return false;
            }

            return true;

        } catch (\Exception $e) {
            Log::warning('AuthController: reCAPTCHA verification request failed — ' . $e->getMessage());
            return false;
        }
    }

    public function sendOtp(Request $request)
    {
        $request->validate([
            'email'          => 'required|email|unique:users,email',
            'first_name'     => 'required|string',
            'recaptcha_token' => 'required|string',
        ]);

        if (!$this->verifyRecaptcha($request->recaptcha_token, 'register')) {
            return response()->json([
                'success' => false,
                'message' => 'reCAPTCHA verification failed. Please try again.',
            ], 422);
        }

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
            'email'          => 'required|email',
            'password'       => 'required',
            'recaptcha_token' => 'required|string',
        ]);

        if (!$this->verifyRecaptcha($request->recaptcha_token, 'login')) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'reCAPTCHA verification failed. Please try again.',
            ], 422);
        }

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