<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthenticatedUserResource;
use App\Models\User;
use App\Notifications\VerifyMobile;
use App\Traits\ThrottlesLogins;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ThrottlesLogins;

    /**
     * The maximum number of attempts to allow.
     *
     * @var int
     */
    protected $maxAttempts = 3;

    /**
     * The number of minutes to throttle for.
     *
     * @var int
     */
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

        $user = User::firstOrCreate([
            'mobile' => $request->mobile,
        ]);

        $user->notify(new VerifyMobile);

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
            'fcm_token' => 'nullable|string',
        ]);

        $this->checkLoginAttempts($request);

        $credentials = [
            'mobile' => $request->mobile,
            'password' => $request->token,
        ];

        $authenticated = Auth::attemptWhen($credentials, function (User $user) {
            return $user->passwordNotExpired();
        });

        if ($authenticated) {
            $this->clearLoginAttempts($request);
            return $this->login($request);
        }

        // $this->incrementLoginAttempts($request);

        throw ValidationException::withMessages([
            'token' => __('The provided token is incorrect.'),
            'retries_left' => $this->retriesLeft($request),
        ]);
    }

    /**
     * Login user.
     *
     * @param \Illuminate\Http\Request $request
     * @return \App\Http\Resources\AuthenticatedUserResource
     */
    private function login(Request $request)
    {
        $user = User::where('mobile', $request->mobile)->first();

        tap($user, function ($user) {

            $user->update([
                'fcm_token' => request('fcm_token'),
            ]);

            if (! $user->hasVerifiedMobile()) {
                $user->markMobileAsVerified();

                $user->profile()->create();

                $user->assignRole('admin');
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
        $request->validate([
            'fcm_token' => 'nullable|string',
        ]);

        $user = $request->user();

        $user->update([
            'fcm_token' => $request->fcm_token,
        ]);

        $user->tokens()->delete();

        return response()->json([
            'token' => $user->createToken('mobile', expiresAt: now()->addDay())->plainTextToken,
            'permissions' => $user->getAllPermissions()->pluck('name'),
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
