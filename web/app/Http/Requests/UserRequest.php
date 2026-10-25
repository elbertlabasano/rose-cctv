<?php

namespace App\Http\Requests;

use App\Services\AccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role === 'admin';
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->route('account')?->id)],
            'password' => [$this->route('account') ? 'nullable' : 'required', 'string', 'min:8', 'max:4096'],
            'idcode' => ['nullable', 'string', 'max:100', 'regex:/^\S+$/u', Rule::unique('users', 'idcode')->ignore($this->route('account')?->id)],
            'role' => ['required', Rule::in(AccessService::ROLES)], 'active' => ['required', 'boolean'], 'all_branches' => ['required', 'boolean'],
            'branch_ids' => ['present', 'array'], 'branch_ids.*' => ['integer', 'distinct', 'exists:branches,id'], 'team' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
