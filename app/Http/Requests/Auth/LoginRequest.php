<?php

namespace App\Http\Requests\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mirrors the login mechanism used by ThirdLine (InternalAudit): credentials
 * are checked via the auth guard and protected by an email+IP RateLimiter that
 * counts only failed attempts and is cleared on success — instead of a blunt
 * route-level throttle that also penalises successful logins.
 *
 * Unlike ThirdLine (a session/Inertia app), LicensingServer is a token API, so
 * we validate the credentials with Auth::validate() and let the controller mint
 * the Sanctum token, rather than starting a session with Auth::attempt().
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Validate credentials and return the authenticated, active user.
     *
     * @throws ValidationException
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::validate($this->only('email', 'password'))) {
            RateLimiter::hit($this->throttleKey());

            AuditLog::record('user.login_failed', 'user', null, [
                'email' => $this->input('email'),
            ], 'system');

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        $user = User::where('email', $this->input('email'))->first();

        if (! $user->is_active) {
            RateLimiter::hit($this->throttleKey());

            AuditLog::record('user.login_failed', 'user', $user->id, [
                'reason' => 'account_disabled',
            ], 'system');

            throw ValidationException::withMessages([
                'email' => trans('auth.disabled'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Throttle key scoped to the submitted email + source IP, so one client's
     * failed attempts never lock out another, and successful logins are free.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower((string) $this->input('email')).'|'.$this->ip());
    }
}
