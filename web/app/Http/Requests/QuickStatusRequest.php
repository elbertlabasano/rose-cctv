<?php

namespace App\Http\Requests;

use App\Services\AccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuickStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(AccessService::class)->write($this->user());
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'integer', 'exists:devices,id'],
            'date' => ['required', 'date_format:Y-m-d', Rule::in([now()->toDateString()])],
            'status' => ['required', Rule::in(['online', 'offline'])],
        ];
    }

    public function messages(): array
    {
        return ['date.in' => 'Quick status checks are allowed only for today in Philippine time.'];
    }
}
