<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdministrationService
{
    public function __construct(private AuditService $audit) {}

    public function branch(array $data, ?Branch $branch = null): void
    {
        DB::transaction(function () use ($data, $branch) {
            $branch ??= new Branch;
            $branch->fill($data)->save();
            $this->audit->record($branch, 'Branch saved', 'Branch details updated.', $branch->id);
        });
    }

    public function user(User $actor, array $data, ?User $user = null): void
    {
        DB::transaction(function () use ($actor, $data, $user) {
            $admins = User::where('role', 'admin')->lockForUpdate()->get();
            if ($user?->id === $actor->id && (! $data['active'] || $data['role'] !== 'admin')) {
                $this->fail('Your current account must remain an active administrator.');
            }
            if ($user?->role === 'admin' && $user->active && (! $data['active'] || $data['role'] !== 'admin') && $admins->where('active', true)->count() <= 1) {
                $this->fail('Keep at least one active administrator.');
            }
            if ($data['role'] === 'admin') {
                $data['all_branches'] = true;
            }
            if (! $data['all_branches'] && empty($data['branch_ids'])) {
                $this->fail('Choose at least one branch.');
            }
            $branches = $data['all_branches'] ? [] : $data['branch_ids'];
            $reason = $data['reason'];
            unset($data['branch_ids'],$data['reason']);
            if (empty($data['password'])) {
                unset($data['password']);
            }
            $user ??= new User;
            $before = $user->only(['name', 'email', 'idcode', 'role', 'active', 'all_branches', 'team']);
            $user->fill($data)->save();
            $user->branches()->sync($branches);
            if (! $user->active || isset($data['password'])) {
                $user->forceFill(['remember_token' => null])->save();
            }
            $this->audit->record($user, 'Account saved', $reason, null, ['before' => $before, 'after' => $user->only(['name', 'email', 'idcode', 'role', 'active', 'all_branches', 'team'])]);
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['account' => $message]);
    }
}
