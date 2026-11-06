<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(): View|RedirectResponse
    {
        return Auth::check() ? redirect()->route('dashboard') : view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $admin = config('job_intel.admin_email');
        $allowed = filled($admin) && hash_equals(strtolower($admin), strtolower($credentials['email']));

        // Same message and similar timing whether the address is wrong or the password is.
        if (! $allowed) {
            Hash::make($credentials['password']); // costs about the same as checking a real password

            return $this->failed();
        }

        if (! Auth::attempt($credentials)) {
            return $this->failed();
        }

        $request->session()->regenerate();
        $request->session()->forget('two_factor_passed'); // the code is asked for on every new sign-in

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function failed(): RedirectResponse
    {
        return back()->withInput(['email' => request('email')])->withErrors(['email' => 'These details do not match.']);
    }
}
