<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    public function __construct(private TwoFactor $twoFactor) {}

    /** Ask for the code (or a recovery code) after the password. */
    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $request->user()->hasTwoFactor()) {
            return redirect()->route('dashboard'); // two-factor is off: nothing to ask
        }

        return $request->session()->get('two_factor_passed')
            ? redirect()->route('dashboard')
            : view('auth.two-factor-challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $user = $request->user();
        $code = trim($data['code']);

        $ok = preg_match('/^\d[\d\s]*$/', $code)
            ? $this->twoFactor->verify($user, $user->app_authentication_secret, $code)
            : $this->twoFactor->useRecoveryCode($user, $code);

        if (! $ok) {
            return back()->withErrors(['code' => 'That code is not valid.']);
        }

        $request->session()->regenerate();
        $request->session()->put('two_factor_passed', true);

        return redirect()->intended(route('dashboard'));
    }

    /** First sign-in: scan the QR code and prove it works before 2FA is switched on. */
    public function setup(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactor()) {
            return redirect()->route('two-factor.challenge');
        }

        $secret = $request->session()->get('two_factor_pending_secret') ?? $this->twoFactor->newSecret();
        $request->session()->put('two_factor_pending_secret', $secret);

        return view('auth.two-factor-setup', [
            'secret' => $secret,
            'qr' => $this->twoFactor->qrSvg($this->twoFactor->uri($user, $secret)),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'],
            'current_password' => ['required', 'string', 'max:255'],
        ]);
        $user = $request->user();
        $secret = $request->session()->get('two_factor_pending_secret');

        // Turning two-factor on can lock the owner out, so someone who only holds a session cannot do it.
        if (! \Illuminate\Support\Facades\Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'That password is not right.']);
        }

        if (! $secret || $user->hasTwoFactor()) {
            return redirect()->route('two-factor.setup');
        }

        if (! $this->twoFactor->check($secret, $data['code'])) {
            return back()->withErrors(['code' => 'That code is not valid. Check the time on your phone and try again.']);
        }

        $user->forceFill(['app_authentication_secret' => $secret, 'app_authentication_last_step' => null])->save();
        $codes = $this->twoFactor->issueRecoveryCodes($user);

        $request->session()->forget('two_factor_pending_secret');
        $request->session()->regenerate();
        $request->session()->put('two_factor_passed', true);

        return redirect()->route('two-factor.recovery')->with('recovery_codes', $codes);
    }

    /** Shown once, straight after setup. */
    public function recovery(Request $request): View|RedirectResponse
    {
        $codes = $request->session()->get('recovery_codes');

        return $codes ? view('auth.recovery-codes', ['codes' => $codes]) : redirect()->route('dashboard');
    }
}
