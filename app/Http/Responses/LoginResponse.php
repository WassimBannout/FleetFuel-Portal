<?php

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * After signing in, admins and managers land on the dashboard and station
 * operators on their station page.
 */
class LoginResponse implements LoginResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        return redirect()->intended(route($user->role->homeRoute()));
    }
}
