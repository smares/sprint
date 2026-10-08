<?php

namespace App\Policies;

use App\Models\SavedFilter;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class SavedFilterPolicy
{
    /**
     * Personal views belong to their owner; shared views are removed by the project's managers.
     */
    public function delete(User $user, SavedFilter $savedFilter): bool
    {
        return $savedFilter->isShared()
            ? Gate::forUser($user)->allows('manage', $savedFilter->project)
            : $savedFilter->user_id === $user->id;
    }
}
