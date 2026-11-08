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
        // What happens to a stored ad. Phase 6 step 1: read it into a `jobs` record. Later steps (standardizing,
        // duplicate detection, status) extend this class. PendingProcessor (leave ads pending) remains for tests.
        // Nothing runs until PROCESSING_ENABLED=true.
        $this->app->bind(\App\Processing\RawPostProcessor::class, \App\Processing\ExtractionProcessor::class);

        // One dictionary per request or queued job, shared by every standardizer, so a changed mapping is seen by all.
        $this->app->scoped(\App\Standardization\TermDictionary::class);
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
