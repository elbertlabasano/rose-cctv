<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FollowupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return ['subject_type' => ['required', Rule::in(['device', 'incident'])], 'subject_id' => ['required', 'integer'], 'snoozed_until' => ['nullable', 'date_format:Y-m-d', 'after:today']];
    }
}
