<?php

namespace App\Policies;

use App\Models\TableOfSpecification;
use App\Models\User;

/**
 * Governs who may view or act on a given TableOfSpecification.
 *
 * TOS records created before auth was wired in (or created by a request
 * with no logged-in user) have a null user_id — those stay open, matching
 * the existing "show everything if nobody's logged in" fallback elsewhere
 * in the app. Once a TOS has an owner, only that owner may view it or
 * trigger actions (generate exam, retry a lesson) on it. Anonymous
 * requests are always denied access to an owned TOS.
 */
class TosPolicy
{
    public function access(?User $user, TableOfSpecification $tos): bool
    {
        if ($tos->user_id === null) {
            return true;
        }

        return $user !== null && $user->id === $tos->user_id;
    }
}
