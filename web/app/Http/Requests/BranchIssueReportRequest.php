<?php

namespace App\Http\Requests;

use App\Models\Cluster;
use Illuminate\Foundation\Http\FormRequest;

class BranchIssueReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->active;
    }

    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'cluster_id' => ['bail', 'nullable', 'string', 'max:30', function ($attribute, $value, $fail) {
                if (! in_array($value, ['all', 'clustered', 'unassigned'], true)
                    && (! ctype_digit($value) || ! Cluster::whereKey($value)->exists())) {
                    $fail('Choose a valid cluster.');
                }
            }],
            'include_resolved' => ['nullable', 'boolean'],
            'format' => ['nullable', 'in:html,csv'],
        ];
    }
}
