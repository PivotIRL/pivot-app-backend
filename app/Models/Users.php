<?php

namespace App\Models;

/**
 * Compatibility name for existing Sanctum tokenable_type values and queued jobs.
 *
 * Keep this class loadable: historical tokens reference App\Models\Users.
 * New authentication uses User; both classes share the same table and behavior.
 */
class Users extends User
{
}
