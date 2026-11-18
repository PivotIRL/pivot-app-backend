<?php

namespace App\Organization\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Organization Dashboard authentication failures. Rendered as { "message": ... } by the API handler.
 */
class OrganizationDashboardAuthException extends HttpException
{
    // Same response for an unknown email, a wrong password and an account with no password yet.
    public static function invalidCredentials(): self
    {
        return new self(401, 'Invalid email or password.');
    }

    public static function accountDisabled(): self
    {
        return new self(403, 'This dashboard account has been disabled.');
    }

    public static function noDashboardAccess(): self
    {
        return new self(403, 'You do not have access to the Organization Dashboard.');
    }

    // Same response for an unknown email, a bad or expired token and a disabled account.
    public static function invalidPasswordLink(): self
    {
        return new self(422, 'This password link is invalid or has expired.');
    }
}
