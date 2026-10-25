<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeviceRequest;
use App\Models\Device;
use App\Services\DeviceService;

class DeviceController extends Controller
{
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
