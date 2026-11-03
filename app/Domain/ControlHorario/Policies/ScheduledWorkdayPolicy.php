<?php

namespace App\Domain\ControlHorario\Policies;

use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Models\User;

class ScheduledWorkdayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('controlhorario.ver_todos');
    }

    public function view(User $user, ScheduledWorkday $record): bool
    {
        return $user->can('controlhorario.ver_todos');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('administrador') && $user->can('controlhorario.programar');
    }

    public function update(User $user, ScheduledWorkday $record): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, ScheduledWorkday $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
