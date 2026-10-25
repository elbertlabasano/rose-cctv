<?php
namespace App\Policies;
use App\Models\{Device,User};
use App\Services\AccessService;
class DevicePolicy
{
    public function __construct(private AccessService $access) {}
    public function view(User $user,Device $device): bool { return $user->active && $this->access->branches($user)->whereKey($device->branch_id)->exists(); }
    public function create(User $user): bool { return $this->access->manage($user); }
    public function update(User $user,Device $device): bool { return $this->access->manage($user) && $this->view($user,$device); }
}
