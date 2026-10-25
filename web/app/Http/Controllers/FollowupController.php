<?php

namespace App\Http\Controllers;

use App\Http\Requests\FollowupRequest;
use App\Models\Device;
use App\Models\Followup;
use App\Models\IncidentReport;
use App\Services\AccessService;

class FollowupController extends Controller
{
    public function store(FollowupRequest $r, AccessService $access)
    {
        $data = $r->validated();
        $subject = $data['subject_type'] === 'device' ? Device::findOrFail($data['subject_id']) : IncidentReport::findOrFail($data['subject_id']);
        $access->branch($r->user(), $subject->branch_id);
        Followup::updateOrCreate(['user_id' => $r->user()->id, 'subject_type' => $data['subject_type'], 'subject_id' => $data['subject_id']], ['snoozed_until' => $data['snoozed_until'] ?? null]);

        return back()->with('success', 'Your follow-up reminder was updated.');
    }
}
