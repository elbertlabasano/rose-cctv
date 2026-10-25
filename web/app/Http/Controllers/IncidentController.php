<?php

namespace App\Http\Controllers;

use App\Http\Requests\IncidentActionRequest;
use App\Http\Requests\IncidentRequest;
use App\Models\IncidentReport;
use App\Services\IncidentService;

class IncidentController extends Controller
{
    public function store(IncidentRequest $request, IncidentService $service)
    {
        $service->create($request->user(), $request->validated());

        return back()->with('success', 'Incident report created.');
    }

    public function action(IncidentActionRequest $request, IncidentReport $incident, IncidentService $service)
    {
        $service->act($request->user(), $incident, $request->validated());

        return back()->with('success', 'Incident history updated.');
    }
}
