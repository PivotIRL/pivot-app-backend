<?php

namespace App\Contracts;

/**
 * Marks the models whose Sanctum tokens may authenticate on the Mobile App API.
 *
 * The `sanctum` guard rejects any other token owner, so a new kind of token (such as an
 * Organization Dashboard token) can never reach the mobile routes by default.
 * New mobile tokens belong to App\Models\User. Historical App\Models\Users tokens
 * remain valid through the compatibility subclass.
 */
interface MobileApiTokenable
{
}
