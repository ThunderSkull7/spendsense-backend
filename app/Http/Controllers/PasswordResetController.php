<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    /**
     * Request a password reset token.
     * Always returns a generic success message to prevent email enumeration.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $email = Str::lower(trim($validated['email']));
        $user = User::where('email', $email)->first();

        $token = Str::random(32);
        $mailFailed = false;

        if ($user !== null) {
            $hashedToken = Hash::make($token);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                [
                    'token'      => $hashedToken,
                    'created_at' => CarbonImmutable::now(),
                ]
            );

            // Construct the frontend password reset URL
            $frontendUrl = rtrim((string) (config('app.frontend_url') ?: config('app.url', 'http://localhost')), '/');
            if (! Str::endsWith($frontendUrl, 'forgot-password.html')) {
                $frontendUrl .= '/authentication/forgot-password.html';
            }
            $resetUrl = $frontendUrl . '?token=' . urlencode($token) . '&email=' . urlencode($email);

            try {
                Mail::to($user->email)->send(new PasswordResetMail($resetUrl, $token, $email, 60));
            } catch (\Throwable $e) {
                $mailFailed = true;

                // Prompt 9.2: Delete only the specific token record created by this request,
                // preventing cleanup from accidentally purging a newer concurrent token for the same email.
                DB::table('password_reset_tokens')
                    ->where('email', $email)
                    ->where('token', $hashedToken)
                    ->delete();

                // Sanitize diagnostic error logging to avoid leaking recipient email, token, or credentials
                $sanitizedMessage = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[REDACTED_EMAIL]', $e->getMessage());
                $sanitizedMessage = preg_replace('/password=[^&;\s]+/i', 'password=[REDACTED]', (string) $sanitizedMessage);

                Log::error('Password reset email delivery failed.', [
                    'exception_class' => get_class($e),
                    'error_message'   => $sanitizedMessage,
                ]);
            }
        }

        $payload = [
            'success' => true,
            'message' => 'If an account with that email exists, reset instructions have been generated.',
        ];

        // For local development and automated testing environments, provide debug_token only if mail did not fail
        if (app()->environment('local', 'testing') && ! $mailFailed) {
            $payload['debug_token'] = $token;
        }

        return response()->json($payload);
    }

    /**
     * Reset password using a valid token.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'    => 'required|email|max:255',
            'token'    => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $email = Str::lower(trim($validated['email']));
        $record = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        if ($record === null) {
            throw ValidationException::withMessages([
                'token' => 'This password reset token is invalid or has expired.',
            ]);
        }

        // Verify token age (max 60 minutes)
        $createdAt = CarbonImmutable::parse($record->created_at);
        if ($createdAt->addMinutes(60)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            throw ValidationException::withMessages([
                'token' => 'This password reset token has expired. Please request a new one.',
            ]);
        }

        // Verify token match (enforce hashed tokens exclusively)
        $tokenMatches = false;
        try {
            $tokenMatches = Hash::check($validated['token'], $record->token);
        } catch (\RuntimeException) {
            // Catches non-hash / unhashed legacy tokens gracefully without unhandled 500
            $tokenMatches = false;
        }

        if (! $tokenMatches) {
            throw ValidationException::withMessages([
                'token' => 'This password reset token is invalid or has expired.',
            ]);
        }

        $user = User::where('email', $email)->first();
        if ($user === null) {
            throw ValidationException::withMessages([
                'email' => 'Unable to find an account associated with this email address.',
            ]);
        }

        $user->password = $validated['password'];
        $user->save();

        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Your password has been successfully reset. You can now log in with your new password.',
        ]);
    }

    /**
     * Change password for an authenticated user.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The provided current password does not match our records.',
            ]);
        }

        $user->password = $validated['password'];
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully.',
        ]);
    }
}

