<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\NoIndex::class);
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'two-factor' => \App\Http\Middleware\RequireTwoFactor::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        // The title separator is " at ": its spaces matter, so do not trim them.
        $middleware->trimStrings(except: ['extraction.title_separator']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
