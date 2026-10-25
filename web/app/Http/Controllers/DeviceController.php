<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeviceDashboardRequest;
use App\Http\Requests\DeviceRequest;
use App\Http\Requests\DeviceSearchRequest;
use App\Models\Device;
use App\Services\DeviceDashboardService;
use App\Services\DeviceSearchService;
use App\Services\DeviceService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;

class DeviceController extends Controller
{
    public function search(DeviceSearchRequest $request, DeviceSearchService $service): JsonResponse
    {
        return response()->json($service->search($request->user(), $request->validated('q')))
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(DeviceDashboardRequest $request, Device $device, DeviceDashboardService $service)
    {
        return Inertia::render('Devices/Show', [
            'dashboard' => $service->data($request->user(), $device, $request->validated()),
        ]);
    }

    public function store(DeviceRequest $request, DeviceService $service)
    {
        $service->save($request->user(), $request->validated());

        return back()->with('success', 'Device registered.');
    }

    public function update(DeviceRequest $request, Device $device, DeviceService $service)
    {
        $service->save($request->user(), $request->validated(), $device);

        return back()->with('success', 'Device updated.');
    }
}
