<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\VerifyMobile;
use App\Traits\ThrottlesLogins;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TelescopeController extends Controller
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
     * Show the mobile number entry form.
     *
     * @return \Illuminate\View\View
     */
    public function showLoginForm()
    {
        return view('telescope.login');
    }

    /**
     * Send token to user mobile.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function sendToken(Request $request)
    {
        $request->validate([
            'mobile' => 'required|ir_mobile:zero',
        ]);

        $user = User::where('mobile', $request->mobile)->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'mobile' => __('No user found with this mobile number.'),
            ]);
        }

        $user->notify(new VerifyMobile);

        return redirect()->route('telescope.verify')
            ->with('mobile', $request->mobile)
            ->with('success', __('Verification token sent successfully.'));
    }

    /**
     * Show the token verification form.
     *
     * @return \Illuminate\View\View
     */
    public function showVerifyForm()
    {
        $mobile = session('mobile');

        if (!$mobile) {
            return redirect()->route('telescope.login')
                ->with('error', __('Please enter your mobile number first.'));
        }

        return view('telescope.verify', compact('mobile'));
    }

    /**
     * Verify user token and login.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function verifyToken(Request $request)
    {
        $request->validate([
            'mobile' => 'required|ir_mobile:zero',
            'token' => 'required|numeric|digits:6',
        ]);

        $user = User::where('mobile', $request->mobile)->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'mobile' => __('No user found with this mobile number.'),
            ]);
        }

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

            $user = User::where('mobile', $request->mobile)->first();

            if (!$user->hasVerifiedMobile()) {
                $user->markMobileAsVerified();

                if (!$user->profile) {
                    $user->profile()->create();
                }

                if (!$user->roles()->exists()) {
                    $user->assignRole('admin');
                }
            }

            // Check if user has root role
            if (!$user->hasRole('root')) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('telescope.login')
                    ->with('error', __('You are not authorized to access Telescope. Only root users can access this dashboard.'));
            }

            $request->session()->regenerate();

            return redirect()->to('/telescope');
        }

        $this->incrementLoginAttempts($request);

        throw ValidationException::withMessages([
            'token' => __('The provided token is incorrect.'),
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

