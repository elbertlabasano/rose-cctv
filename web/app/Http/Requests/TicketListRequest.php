<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TicketListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->active;
    }

    public function rules(): array
    {
        return ['date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000']];
    }
}
