<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthenticatedUserResource;
use App\Models\Role;
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
    protected $maxAttempts = 5;

    /**
     * The number of minutes to throttle for.
     *
     * @var int
     */
    protected $decayMinutes = 15;

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
            return $user->passwordNotExpired() && $user->is_active;
        });

        if ($authenticated) {
            $this->clearLoginAttempts($request);
            return $this->login($request);
        }

        $this->incrementLoginAttempts($request);

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

        // logout other devices
        Auth::logoutOtherDevices($user);

        tap($user, function ($user) {

            $user->update([
                'fcm_token' => request('fcm_token'),
            ]);

            if (! $user->hasVerifiedMobile()) {
                $user->markMobileAsVerified();

                $user->profile()->create();
            }
        });

        $this->guard()->login($user);

        $request->session()->regenerate();

        $user->load('profile');

        $token = $user->createToken('mobile')->plainTextToken;

        $user->token = $token;

        return new AuthenticatedUserResource($user);
    }

    /**
     * Get permissions of the authenticated user.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function permissions(Request $request)
    {
        $user = $request->user();

        [$roleName, $permissions] = $this->getUserRoleAndPermissions($user);

        return response()->json([
            'role' => $roleName,
            'permissions' => $permissions,
        ]);
    }

    /**
     * Get the role name and permissions for a user based on working environment.
     *
     * @param \App\Models\User $user
     * @return array
     */
    protected function getUserRoleAndPermissions(User $user)
    {
        if ($user->hasRole('root')) {
            return [
                'root',
                $user->getAllPermissions()->pluck('name'),
            ];
        }

        $workingEnvironment = $user->workingEnvironment();

        if ($workingEnvironment && $workingEnvironment->pivot && $workingEnvironment->pivot->role) {
            $role = Role::where('name', $workingEnvironment->pivot->role)
                ->with('permissions')
                ->first();

            return [
                $role?->name,
                $role?->permissions?->pluck('name') ?? collect(),
            ];
        }

        return [
            $user->getRoleNames()->first(),
            $user->getAllPermissions()->pluck('name'),
        ];
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
