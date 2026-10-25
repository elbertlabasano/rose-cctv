<?php

namespace App\Http\Controllers;

use App\Http\Requests\ObservationRequest;
use App\Services\ObservationService;

class ObservationController extends Controller
{
    public function store(ObservationRequest $request, ObservationService $service)
    {
        $service->save($request->user(), $request->validated());

        return back()->with('success', 'Daily record saved. Ticket requests, if selected, are tracked separately.');
    }
}
