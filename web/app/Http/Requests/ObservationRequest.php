<?php

namespace App\Http\Requests;

use App\Services\AccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(AccessService::class)->write($this->user());
    }

    public function rules(): array
    {
        return [
            'device_ids' => ['required', 'array', 'min:1', 'max:100'], 'device_ids.*' => ['required', 'integer', 'distinct', 'exists:devices,id'],
            'date' => ['required', 'date_format:Y-m-d', Rule::in([now()->toDateString()])],
            'update_status' => ['required', 'boolean'], 'attach_images' => ['required', 'boolean'], 'create_ticket' => ['required', 'boolean'],
            'status' => ['nullable', 'required_if:update_status,1', Rule::in(['online', 'offline'])],
            'notes' => ['required', 'string', 'max:4000'], 'ticket_title' => ['nullable', 'required_if:create_ticket,1', 'string', 'max:180'],
            'ticket_board_id' => ['nullable', 'integer', Rule::in(array_column(config('tickets.boards', []), 'id'))],
            'images' => ['nullable', 'array', 'max:6'], 'images.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:max_width=8000,max_height=8000'],
            'remove_attachment_ids' => ['nullable', 'array', 'max:6'], 'remove_attachment_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return ['date.in' => 'Daily actions are allowed only for today in Philippine time. Past records are read-only.'];
    }
}
