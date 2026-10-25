<?php

namespace App\Http\Requests;

use App\Services\AccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('device') ? $this->user()->can('update', $this->route('device')) : $this->user()->can('create', \App\Models\Device::class);
    }

    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('devices')->ignore($this->route('device')?->id)],
            'name' => ['required', 'string', 'max:100'], 'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'type' => ['required', Rule::in(['DVR', 'NVR', 'IP Camera', 'Analog Camera', 'PoE Switch', 'Router', 'UPS', 'HDD / Storage', 'Power Supply', 'Other'])],
            'parent_id' => ['nullable', 'integer', 'exists:devices,id'], 'ip' => ['nullable', 'ipv4'],
            'status' => ['required', Rule::in(['online', 'offline', 'degraded', 'maintenance', 'unknown'])],
            'location' => ['nullable', 'string', 'max:255'], 'brand' => ['nullable', 'string', 'max:255'], 'model' => ['nullable', 'string', 'max:255'],
            'serial' => ['nullable', 'string', 'max:255'], 'owner' => ['nullable', 'string', 'max:255'],
            'channel' => ['nullable', 'integer', 'min:1', 'max:256'], 'channels' => ['nullable', 'integer', 'min:1', 'max:256'],
            'storage_tb' => ['nullable', 'numeric', 'min:0', 'max:10000'], 'disk_health' => ['nullable', Rule::in(['Unknown', 'Healthy', 'Warning', 'Failed'])],
            'installed_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'warranty_at' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'], 'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
