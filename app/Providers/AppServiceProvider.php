<?php

namespace App\Providers;

use App\Contracts\ExchangeRateProvider;
use App\Enums\RateMode;
use App\Http\Responses\LoginResponse;
use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\ExchangeRate;
use App\Models\FuelCard;
use App\Models\FuelTransaction;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Station;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\CredentialVerifier;
use App\Services\ExchangeRates\FixtureExchangeRateProvider;
use App\Services\ExchangeRates\HttpExchangeRateProvider;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Fortify's route file always adds more than login and logout
        // (password confirmation, and registration if a feature is ever
        // switched on). routes/web.php registers only login and logout (D16).
        Fortify::ignoreRoutes();

        // After sign-in each role lands on its own page.
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);

        // rates:sync talks to the provider for EXCHANGE_RATE_MODE; live mode
        // never falls back to the fixture.
        $this->app->bind(ExchangeRateProvider::class, fn ($app) => match (RateMode::current()) {
            RateMode::Live => $app->make(HttpExchangeRateProvider::class),
            RateMode::Fixture => $app->make(FixtureExchangeRateProvider::class),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Dates never change in place: $date->addDay() returns a new object.
        Date::use(CarbonImmutable::class);

        // Outside production, fail loudly on lazy loading (N+1 queries),
        // silently discarded attributes and reads of missing attributes.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Polymorphic columns (audit_logs.auditable_type, Sanctum's
        // tokenable_type) store these stable aliases instead of class names.
        Relation::enforceMorphMap([
            'user' => User::class,
            'company' => Company::class,
            'station' => Station::class,
            'product' => Product::class,
            'product_price' => ProductPrice::class,
            'exchange_rate' => ExchangeRate::class,
            'vehicle' => Vehicle::class,
            'driver' => Driver::class,
            'fuel_card' => FuelCard::class,
            'fuel_transaction' => FuelTransaction::class,
            'delivery_order' => DeliveryOrder::class,
        ]);

        // Blade lists render Bootstrap 5 pagination links.
        Paginator::useBootstrapFive();

        $this->configureAuthentication();
        $this->configureRateLimiting();
        $this->bindTenantScopedModels();
    }

    /**
     * Route parameters such as {vehicle} or {card} are looked up through the
     * signed-in user's tenant scope, never by ID alone. A record of another
     * company is therefore "not found" (404) on every route that names it,
     * including routes added later, before any controller code runs.
     * (bootstrap/app.php runs the `active` and `role` checks before this
     * lookup, so a wrong role still gets 403.)
     */
    private function bindTenantScopedModels(): void
    {
        $this->bindScoped('company', fn (User $user) => Company::query()->visibleTo($user));
        $this->bindScoped('station', fn (User $user) => Station::query()->visibleTo($user));
        $this->bindScoped('product', fn (User $user) => Product::query()->visibleTo($user));
        $this->bindScoped('vehicle', fn (User $user) => Vehicle::query()->visibleTo($user));
        $this->bindScoped('driver', fn (User $user) => Driver::query()->visibleTo($user));
        $this->bindScoped('card', fn (User $user) => FuelCard::query()->visibleTo($user));
    }

    /**
     * @param  Closure(User): Builder<covariant Model>  $scopedQuery
     */
    private function bindScoped(string $parameter, Closure $scopedQuery): void
    {
        Route::bind($parameter, function (string $value) use ($scopedQuery): Model {
            $user = request()->user();

            if (! $user instanceof User || ! ctype_digit($value)) {
                abort(404);
            }

            return $scopedQuery($user)->findOrFail((int) $value);
        });
    }

    private function configureAuthentication(): void
    {
        Fortify::loginView(fn () => view('auth.login'));

        // Web sign-in uses the same check as API token issuance: unknown
        // email, wrong password and disabled account fail identically.
        Fortify::authenticateUsing(fn (Request $request): ?User => app(CredentialVerifier::class)->verify(
            $request->string(Fortify::username())->toString(),
            $request->string('password')->toString(),
        ));

        // An unexpired, unrevoked token still authenticates nobody once its
        // account is disabled (the API answers 401).
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid): bool => $isValid
                && $token->tokenable instanceof User
                && $token->tokenable->is_active,
        );
    }

    /**
     * Web sign-in has Fortify's own limiter (config/fortify.php). The API
     * limits follow docs/05-API-CONTRACT.md; POS writes get theirs in M05.
     */
    private function configureRateLimiting(): void
    {
        // POST /api/v1/auth/token: 5 requests per minute per email + IP.
        RateLimiter::for('token-issue', fn (Request $request): Limit => Limit::perMinute(5)->by(
            Str::lower(trim($request->string('email')->toString())).'|'.$request->ip(),
        ));

        // Authenticated API requests: 120 per minute per user.
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)->by(
            (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));

        // POS purchase submissions: 60 per minute per station, however many
        // operator accounts or tokens that station uses.
        RateLimiter::for('pos-writes', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(60)->by('station:'.($user instanceof User ? $user->station_id : $request->ip()));
        });
    }
}
