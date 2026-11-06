<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The processing stage (cleaning, extraction, classification) replaces this binding.
        // Until then ads simply stay "pending".
        $this->app->bind(\App\Processing\RawPostProcessor::class, \App\Processing\PendingProcessor::class);
    }

    public function boot(): void
    {
        // 5 password tries a minute per address + IP, so the login cannot be guessed at.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        // 5 authenticator-code tries a minute per signed-in user.
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)
            ->by(($request->user()?->id ?? 'guest').'|'.$request->ip()));
    }
}
