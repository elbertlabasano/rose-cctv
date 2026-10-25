<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AccessService
{
    public const ROLES = ['admin', 'supervisor', 'operator', 'technician', 'viewer'];

    public function branches(User $user): Builder
    {
        $query = Branch::query();
        if ($user->role !== 'admin' && ! $user->all_branches) {
            $query->whereHas('users', fn ($q) => $q->where('users.id', $user->id));
        }

        return $query;
    }

    public function branch(User $user, int $id): void
    {
        abort_unless($user->active && $this->branches($user)->whereKey($id)->exists(), 403);
    }

    public function manage(User $user): bool
    {
        return $user->active && in_array($user->role, ['admin', 'supervisor']);
    }

    public function write(User $user): bool
    {
        return $user->active && in_array($user->role, ['admin', 'supervisor', 'operator', 'technician']);
    }

    public function admin(User $user): void
    {
        abort_unless($user->active && $user->role === 'admin', 403);
    }

    public function verify(User $user): bool
    {
        return $this->manage($user);
    }
}
