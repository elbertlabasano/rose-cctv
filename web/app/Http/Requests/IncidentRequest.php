<?php

namespace App\Http\Requests;

use App\Rules\DocumentUrl;
use App\Services\AccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\IncidentReport::class);
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'integer', 'exists:devices,id'], 'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:4000'], 'priority' => ['required', Rule::in(['critical', 'high', 'medium', 'low'])],
            'category' => ['required', Rule::in(['Camera offline', 'Unstable video', 'Network fault', 'Power issue', 'Storage issue', 'Other'])],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'], 'signed_document_url' => ['nullable', 'max:2000', new DocumentUrl],
        ];
    }
}
