<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\VerifyMobile;
use App\Traits\ThrottlesLogins;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TelescopeLoginController extends Controller
{
    use ThrottlesLogins;

    protected int $maxAttempts = 5;

    protected int $decayMinutes = 15;

    /**
     * Show the telescope login form (mobile input).
     */
    public function showLoginForm(Request $request)
    {
        if (Auth::guard('web')->check() && $request->user()->can('viewTelescope')) {
            return redirect($this->telescopePath());
        }

        return view('telescope.login');
    }

    /**
     * Send verification code to the user's mobile (existing users only).
     */
    public function sendCode(Request $request)
    {
        $request->validate([
            'mobile' => 'required|ir_mobile:zero',
        ]);

        $user = User::where('mobile', $request->mobile)->first();

        if (! $user) {
            return back()->withErrors([
                'mobile' => __('No user found for this mobile number.'),
            ])->withInput();
        }

        if (! $user->is_active) {
            return back()->withErrors([
                'mobile' => __('This account is not active.'),
            ])->withInput();
        }

        $user->notify(new VerifyMobile);

        $request->session()->put('telescope_login_mobile', $request->mobile);

        return redirect()->route('telescope.verify.form')->with('message', __('Verification code sent to your mobile.'));
    }

    /**
     * Show the verify password form.
     */
    public function showVerifyForm(Request $request)
    {
        if (! $request->session()->has('telescope_login_mobile')) {
            return redirect()->route('telescope.login')->withErrors([
                'password' => __('Please enter your mobile and request a code first.'),
            ]);
        }

        return view('telescope.verify');
    }

    /**
     * Verify the temporary password and log in; redirect to Telescope if authorized.
     */
    public function verify(Request $request)
    {
        $request->validate([
            'password' => 'required|numeric|digits:6',
        ]);

        $mobile = $request->session()->get('telescope_login_mobile');

        if (! $mobile) {
            return redirect()->route('telescope.login')->withErrors([
                'password' => __('Session expired. Please enter your mobile and request a code again.'),
            ]);
        }

        $request->merge(['mobile' => $mobile]);
        $this->checkLoginAttempts($request);

        $credentials = [
            'mobile' => $mobile,
            'password' => $request->password,
        ];

        $authenticated = Auth::guard('web')->attemptWhen($credentials, function (User $user) {
            return $user->passwordNotExpired() && $user->is_active;
        });

        if (! $authenticated) {
            $this->incrementLoginAttempts($request);

            throw ValidationException::withMessages([
                'password' => __('The provided code is incorrect or has expired.'),
                'retries_left' => $this->retriesLeft($request),
            ]);
        }

        $this->clearLoginAttempts($request);

        $user = User::where('mobile', $mobile)->first();
        $request->session()->regenerate();
        $request->session()->forget('telescope_login_mobile');

        if (! $user->can('viewTelescope')) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('telescope.login')->withErrors([
                'password' => __('Access denied. You do not have permission to access Telescope.'),
            ]);
        }

        return redirect()->intended($this->telescopePath());
    }

    protected function username(): string
    {
        return 'mobile';
    }

    protected function telescopePath(): string
    {
        return '/'.ltrim(config('telescope.path', 'telescope'), '/');
    }
}
