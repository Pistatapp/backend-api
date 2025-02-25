<?php

namespace App\Traits;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ThrottlesLogins
{
    /**
     * Check the login attempts for the user.
     *
     * @param \Illuminate\Http\Request $request
     * @return void
     */
    protected function checkLoginAttempts($request) {

        if ($this->hasTooManyLoginAttempts($request)) {
            throw ValidationException::withMessages([
                'token' => __('Too many login attempts. Please try again in :seconds seconds.', [
                    'seconds' => $this->secondsRemainingOnLockout($request),
                ]),
            ]);
        }
    }

    /**
     * Determine if the user has too many failed login attempts.
     *
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    protected function hasTooManyLoginAttempts(Request $request): bool
    {
        return $this->limiter()->tooManyAttempts(
            $this->throttleKey($request),
            $this->maxAttempts()
        );
    }

    /**
     * Determine how many retries are left for the user.
     *
     * @param \Illuminate\Http\Request $request
     * @return int
     */
    protected function retriesLeft(Request $request): int
    {
        return $this->limiter()->retriesLeft(
            $this->throttleKey($request),
            $this->maxAttempts()
        );
    }

    /**
     * Determine how many seconds remain until the user can retry their login.
     *
     * @param \Illuminate\Http\Request $request
     * @return int
     */
    protected function secondsRemainingOnLockout(Request $request): int
    {
        return $this->limiter()->availableIn(
            $this->throttleKey($request)
        );
    }

    /**
     * Increment the login attempts for the user.
     *
     * @param \Illuminate\Http\Request $request
     * @return void
     */
    protected function incrementLoginAttempts(Request $request): void
    {
        $this->limiter()->hit(
            $this->throttleKey($request),
            $this->decayMinutes() * 60
        );
    }

    /**
     * Clear the login locks for the given user credentials.
     *
     * @param \Illuminate\Http\Request $request
     * @return void
     */
    protected function clearLoginAttempts(Request $request): void
    {
        $this->limiter()->clear($this->throttleKey($request));
    }

    /**
     * Get the throttle key for the given request.
     *
     * @param \Illuminate\Http\Request $request
     * @return string
     */
    protected function throttleKey(Request $request): string
    {
        return mb_strtolower($request->input($this->username()));
    }

    /**
     * Get the rate limiter instance.
     *
     * @return \Illuminate\Cache\RateLimiter
     */
    protected function limiter(): \Illuminate\Cache\RateLimiter
    {
        return app(\Illuminate\Cache\RateLimiter::class);
    }

    /**
     * Get the maximum number of attempts to allow.
     *
     * @return int
     */
    protected function maxAttempts(): int
    {
        return property_exists($this, 'maxAttempts')
            ? $this->maxAttempts
            : 5;
    }

    /**
     * Get the number of minutes to throttle for.
     *
     * @return int
     */
    protected function decayMinutes(): int
    {
        return property_exists($this, 'decayMinutes')
            ? $this->decayMinutes
            : 1;
    }

    /**
     * Get the login username to be used by the controller.
     *
     * @return string
     */
    abstract protected function username();
}
