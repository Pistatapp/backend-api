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
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ThrottlesLogins;

    protected $maxAttempts = 3;

    protected $decayMinutes = 1;

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

        $token = random_int(100000, 999999);

        VerifyMobileToken::updateOrCreate([
            'mobile' => $user->mobile,
        ], [
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        $user->notify(new VerifyMobile($token));

        return response()->json([
            'message' => __('Verification token sent successfully.'),
        ]);
    }

    /**
     * Verify user mobile.
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

        if (!$this->hasTooManyLoginAttempts($request)) {
            $token = VerifyMobileToken::where('mobile', $request->mobile)->first();

            if (
                !$token
                || !Hash::check($request->token, $token->token)
                || $token->created_at->diffInMinutes(now()) > 2
            ) {

                $this->incrementLoginAttempts($request);

                throw ValidationException::withMessages([
                    'token' => __('The provided token is incorrect.'),
                    'retries_left' => $this->retriesLeft($request),
                ]);
            }

            $this->clearLoginAttempts($request);

            $token->delete();

            $user = User::where('mobile', $request->mobile)->first();

            if (is_null($user->mobile_verified_at)) {
                $user->mobile_verified_at = now();
                $user->save();

                $user->profile()->create();
            }

            $this->guard()->login($user);

            $request->session()->regenerate();

            return new AuthenticatedUserResource($user);
        }

        throw ValidationException::withMessages([
            'token' => __('Too many login attempts. Please try again in :seconds seconds.', [
                'seconds' => $this->secondsRemainingOnLockout($request),
            ]),
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
