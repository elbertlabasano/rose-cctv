<?php

namespace App\Http\Requests;

use App\Rules\DocumentUrl;
use App\Services\AccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IncidentActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('incident'));
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['log', 'resolve', 'verify', 'reopen', 'document'])],
            'notes' => ['required', 'string', 'max:4000'], 'status' => ['nullable', Rule::in(['assigned', 'in_progress', 'on_hold'])],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'], 'cause' => ['nullable', 'required_if:action,resolve', 'string', 'max:2000'],
            'work' => ['nullable', 'required_if:action,resolve', 'string', 'max:4000'],
            'restored_at' => ['nullable', 'required_if:action,resolve', 'date', 'before_or_equal:now'],
            'prevention' => ['nullable', 'string', 'max:2000'], 'confirmed' => ['nullable', 'boolean', 'accepted_if:action,resolve,verify'],
            'signed_document_url' => ['nullable', 'max:2000', new DocumentUrl],
            'proofs' => ['nullable', 'array', 'max:3'], 'proofs.*' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,pdf', 'max:8192'],
        ];
    }
}
