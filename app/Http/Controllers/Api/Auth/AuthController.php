<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthenticatedUserResource;
use App\Models\User;
use App\Models\VerifyMobileToken;
use App\Notifications\VerifyMobile;
use App\Traits\ThrottlesLogins;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ThrottlesLogins;

    protected $maxAttempts = 3;

    protected $decayMinutes = 2;

    /**
     * Send token to user mobile.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendToken(Request $request)
    {
        $request->validate([
            'mobile' => 'required|ir_mobile:zero',
        ]);

        $token = random_int(100000, 999999);

        VerifyMobileToken::updateOrCreate([
            'mobile' => $request->mobile,
        ], [
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        Notification::route('kavenegar', $request->mobile)
            ->notify(new VerifyMobile($token));

        return response()->json([
            'message' => __('Verification token sent successfully.'),
        ]);
    }

    /**
     * Verify user token.
     *
     * @param \Illuminate\Http\Request $request
     * @return \App\Http\Resources\AuthenticatedUserResource
     * @throws \Illuminate\Validation\ValidationException
     */
    public function verifyToken(Request $request)
    {
        $request->validate([
            'mobile' => 'required|ir_mobile:zero',
            'token' => 'required|numeric|digits:6',
        ]);

        if ($this->hasTooManyLoginAttempts($request)) {
            throw ValidationException::withMessages([
                'token' => __('Too many login attempts. Please try again in :seconds seconds.', [
                    'seconds' => $this->secondsRemainingOnLockout($request),
                ]),
            ]);
        }

        try {
            $token = VerifyMobileToken::where('mobile', $request->mobile)->firstOrFail();
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'token' => __('Token not found.'),
            ]);
        }

        if (!Hash::check($request->token, $token->token) || $token->expired()) {

            $this->incrementLoginAttempts($request);

            throw ValidationException::withMessages([
                'token' => __('The provided token is incorrect.'),
                'retries_left' => $this->retriesLeft($request),
            ]);
        }

        $this->clearLoginAttempts($request);

        $token->delete();

        return $this->login($request);
    }

    /**
     * Login user.
     *
     * @param \Illuminate\Http\Request $request
     * @return \App\Http\Resources\AuthenticatedUserResource
     */
    private function login(Request $request)
    {
        $user = User::firstOrCreate([
            'mobile' => $request->mobile,
        ]);

        tap($user, function ($user) {
            if (is_null($user->mobile_verified_at)) {
                $user->mobile_verified_at = now();
                $user->save();

                $user->profile()->create();
            }
        });

        $this->guard()->login($user);

        $request->session()->regenerate();

        return new AuthenticatedUserResource($user);
    }

    /**
     * Refresh user token.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function refreshToken(Request $request)
    {
        $user = $request->user();

        $user->tokens()->delete();

        return response()->json([
            'token' => $user->createToken('mobile', expiresAt: now()->addDay())->plainTextToken,
        ]);
    }

    /**
     * Logout user.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $this->guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $request->user()->tokens()->delete();

        return response()->json([
            'message' => __('Logged out successfully.'),
        ]);
    }

    /**
     * Get the guard to be used during authentication.
     *
     * @return \Illuminate\Contracts\Auth\StatefulGuard
     */
    protected function guard()
    {
        return Auth::guard('web');
    }

    /**
     * Get the login username to authenticate user.
     *
     * @return string
     */
    protected function username()
    {
        return 'mobile';
    }
}
