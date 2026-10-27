<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('branches')->ignore($this->route('branch')?->id)],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('branches')->ignore($this->route('branch')?->id)],
            'address' => ['nullable', 'string', 'max:255'], 'active' => ['required', 'boolean'],
            'cluster_id' => ['nullable', 'integer', 'exists:clusters,id'],
        ];
    }
}
