<?php
namespace App\Policies;
use App\Models\{Attachment,User};
use App\Services\AccessService;
class AttachmentPolicy
{
    public function __construct(private AccessService $access) {}
    public function view(User $user,Attachment $attachment): bool { return $user->active && $this->access->branches($user)->whereKey($attachment->branch_id)->exists(); }
}
