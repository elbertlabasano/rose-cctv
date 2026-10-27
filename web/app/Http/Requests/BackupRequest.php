<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->active && $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [];
    }
}
