<?php

namespace App\Http\Controllers;

use App\Http\Requests\BranchRequest;
use App\Http\Requests\UserRequest;
use App\Models\Branch;
use App\Models\User;
use App\Services\AdministrationService;

class AdministrationController extends Controller
{
    public function storeBranch(BranchRequest $r, AdministrationService $s)
    {
        $s->branch($r->validated());

        return back()->with('success', 'Branch created.');
    }

    public function updateBranch(BranchRequest $r, Branch $branch, AdministrationService $s)
    {
        $s->branch($r->validated(), $branch);

        return back()->with('success', 'Branch updated.');
    }

    public function storeUser(UserRequest $r, AdministrationService $s)
    {
        $s->user($r->user(), $r->validated());

        return back()->with('success', 'Account created. Share the initial password through your approved secure channel.');
    }

    public function updateUser(UserRequest $r, User $account, AdministrationService $s)
    {
        $s->user($r->user(), $r->validated(), $account);

        return back()->with('success','Account updated.');
    }
}
