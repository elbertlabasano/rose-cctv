<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DeviceDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('device'));
    }

    public function rules(): array
    {
        return [
            'tab' => ['nullable', Rule::in(['overview', 'status', 'tickets', 'incidents', 'evidence', 'outages', 'activity'])],
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'changes_page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $validator->errors()->any() && $this->filled('from')
                && CarbonImmutable::parse($this->input('from'))->diffInDays(CarbonImmutable::parse($this->input('to'))) > 89) {
                $validator->errors()->add('from', 'Select a chart period of 90 days or fewer.');
            }
        }];
    }
}
