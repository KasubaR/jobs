<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor sign-in is optional and off by default. Once it has been turned on
 * (Security page), the authenticator code is required on every new sign-in.
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->hasTwoFactor() && ! $request->session()->get('two_factor_passed')) {
            return redirect()->route('two-factor.challenge');
        }

        return $next($request);
    }
}
