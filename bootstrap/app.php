<?php

use App\Exceptions\HoldExpiredException;
use App\Exceptions\InvalidHoldStateException;
use App\Exceptions\SlotUnavailableException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (SlotUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        });

        $exceptions->render(function (InvalidHoldStateException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        });

        $exceptions->render(function (HoldExpiredException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        });
    })->create();
