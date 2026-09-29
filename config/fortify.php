<?php

// Fortify supplies the login pipeline only: credential check, login
// throttling, session regeneration and logout. Its package defaults enable
// registration, password reset, two-factor and passkeys, so every feature is
// switched off here, and routes/web.php registers only login and logout
// (Fortify::ignoreRoutes() in AppServiceProvider). Accounts are provisioned
// with `php artisan users:create` (D16).

return [

    'guard' => 'web',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    // Emails are stored lowercase; "Admin@Fleetfuel.test" signs in as the same user.
    'lowercase_usernames' => true,

    // Used by redirect()->intended() fallbacks; the login response sends each
    // role to its own landing page (App\Http\Responses\LoginResponse).
    'home' => '/dashboard',

    'redirects' => [
        'logout' => '/login',
    ],

    'limiters' => [
        // null makes Fortify's login pipeline apply its own limiter: 5 failed
        // attempts per email + IP, then a 60-second lockout shown on the form.
        // Successful sign-ins clear the counter.
        'login' => null,
    ],

    'views' => true,

    'features' => [],

];
