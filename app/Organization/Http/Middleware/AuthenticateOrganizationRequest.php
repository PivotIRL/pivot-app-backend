<?php

namespace App\Organization\Http\Middleware;

use App\Http\Middleware\Authenticate;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;

/**
 * The application's Authenticate middleware, used only by Organization routes (alias "organization.auth").
 *
 * Implementing AuthenticatesRequests puts it in Laravel's middleware priority ahead of SubstituteBindings,
 * so a missing or invalid token gets 401 before any {organization} or child model is looked up
 * (instead of 404 for a non-existent ID). The shared "auth" middleware used by other APIs is unchanged.
 */
class AuthenticateOrganizationRequest extends Authenticate implements AuthenticatesRequests
{
}
