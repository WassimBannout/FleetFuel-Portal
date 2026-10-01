<?php

use App\Exceptions\ApiErrorRenderer;
use App\Exceptions\ApiException;
use App\Exceptions\BusinessRuleViolation;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RejectMalformedJson;
use App\Models\User;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // Liveness: the application boots (no database needed).
        health: '/up',
        // Readiness: registered outside the `web` middleware group so it needs
        // no session or cookies, and still answers 503 when MySQL is down.
        then: function () {
            Route::get('/health', HealthController::class)->name('health');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every response carries an X-Request-Id; logs and API errors include it.
        $middleware->prepend(AssignRequestId::class);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'role' => EnsureUserHasRole::class,
            // Sanctum token abilities: 'abilities' needs all, 'ability' any one.
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        $middleware->api(prepend: [RejectMalformedJson::class]);

        // Run the account, role and token-ability checks right after
        // authentication and before route-model binding: a station operator
        // asking for a card URL, or a token without the ability, gets 403
        // instead of a tenant-scoped 404.
        $middleware->appendToPriorityList(AuthenticatesRequests::class, EnsureUserIsActive::class);
        $middleware->appendToPriorityList(EnsureUserIsActive::class, EnsureUserHasRole::class);
        $middleware->appendToPriorityList(EnsureUserHasRole::class, CheckAbilities::class);
        $middleware->appendToPriorityList(CheckAbilities::class, CheckForAnyAbility::class);

        // A signed-in user who opens /login goes to their own landing page.
        $middleware->redirectUsersTo(function (Request $request): string {
            $user = $request->user();

            return $user instanceof User ? route($user->role->homeRoute()) : route('home');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every /api/* error uses the documented envelope, without traces.
        $exceptions->render(fn (Throwable $e, Request $request) => (new ApiErrorRenderer)($e, $request));

        // On web pages a refused business rule (inactive company, used card,
        // archived card, quota below usage, a stale delivery status) returns
        // to the form with the message, like a validation error. Page scripts
        // that ask for JSON (the delivery status buttons) get the code and
        // details instead, so they can react, e.g. reload on stale_state.
        $exceptions->render(function (BusinessRuleViolation $e, Request $request) {
            if ($request->is('api/*')) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => $e->errorCode,
                    'details' => (object) $e->details,
                ], $e->status);
            }

            return back()->withInput()->withErrors([$e->field => $e->getMessage()]);
        });

        // Expected API errors (invalid credentials, malformed JSON, later
        // business declines) are responses, not faults worth an error log.
        $exceptions->dontReport(ApiException::class);
    })->create();
