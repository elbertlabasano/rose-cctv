<?php

namespace App\Http\Controllers;

use App\Http\Requests\ObservationRequest;
use App\Http\Requests\QuickStatusRequest;
use App\Models\Outage;
use App\Services\ObservationService;
use App\Services\WorkspaceService;

class ObservationController extends Controller
{
    public function quickStatus(QuickStatusRequest $request, ObservationService $service, WorkspaceService $workspace)
    {
        $record = $service->quickStatus($request->user(), $request->validated());

        return response()->json([
            'device' => $record->device->only('id', 'status', 'status_observed_at', 'status_reported_on'),
            'observation' => $workspace->observation($record),
            'outages' => Outage::where('device_id', $record->device_id)->orderByDesc('started_at')->get(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(ObservationRequest $request, ObservationService $service)
    {
        $service->save($request->user(), $request->validated());

        return back()->with('success', 'Daily record saved. Ticket requests, if selected, are tracked separately.');
    }
}
