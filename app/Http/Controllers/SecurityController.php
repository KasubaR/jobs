<?php

namespace App\Http\Controllers;

use App\Services\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/** Turn two-factor sign-in on or off, and renew recovery codes. */
class SecurityController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();

        return view('security', [
            'enabled' => $user->hasTwoFactor(),
            'recoveryLeft' => count($user->app_authentication_recovery_codes ?? []),
        ]);
    }

    public function disable(Request $request): RedirectResponse
    {
        $this->confirmPassword($request);

        $request->user()->forceFill([
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
            'app_authentication_last_step' => null,
        ])->save();

        $request->session()->forget('two_factor_passed');

        return redirect()->route('security')->with('status', 'Two-factor sign-in is off. Sign-in now needs only your password.');
    }

    public function recoveryCodes(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        abort_unless($request->user()->hasTwoFactor(), 404);
        $this->confirmPassword($request);

        return redirect()->route('two-factor.recovery')->with('recovery_codes', $twoFactor->issueRecoveryCodes($request->user()));
    }

    private function confirmPassword(Request $request): void
    {
        $request->validate(['current_password' => ['required', 'string', 'max:255']]);

        if (! Hash::check($request->input('current_password'), $request->user()->password)) {
            back()->withErrors(['current_password' => 'That password is not right.'])->throwResponse();
        }
    }
}
