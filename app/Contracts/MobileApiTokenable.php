<?php

namespace App\Contracts;

/**
 * Marks the models whose Sanctum tokens may authenticate on the Mobile App API.
 *
 * The `sanctum` guard rejects any other token owner, so a new kind of token (such as an
 * Organization Dashboard token) can never reach the mobile routes by default.
 * Mobile tokens are owned by App\Models\Users (email login) and App\Models\User (Google, Apple).
 */
interface MobileApiTokenable
{
}
