<?php
namespace App\Policies;
use App\Models\{IncidentReport,User};
use App\Services\AccessService;
class IncidentReportPolicy
{
    public function __construct(private AccessService $access) {}
    public function view(User $user,IncidentReport $incident): bool { return $user->active && $this->access->branches($user)->whereKey($incident->branch_id)->exists(); }
    public function create(User $user): bool { return $this->access->write($user); }
    public function update(User $user,IncidentReport $incident): bool { return $this->access->write($user) && $this->view($user,$incident); }
}
