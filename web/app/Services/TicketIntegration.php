<?php

namespace App\Services;

class TicketIntegration
{
    public function canView(): bool
    {
        $endpoint = config('tickets.endpoint');

        return is_string($endpoint) && filter_var($endpoint, FILTER_VALIDATE_URL)
            && parse_url($endpoint, PHP_URL_SCHEME) === 'https'
            && ! parse_url($endpoint, PHP_URL_USER) && ! parse_url($endpoint, PHP_URL_PASS)
            && ! parse_url($endpoint, PHP_URL_QUERY) && ! parse_url($endpoint, PHP_URL_FRAGMENT)
            && is_string(config('tickets.token')) && trim(config('tickets.token')) !== ''
            && $this->positiveId('assignee_id');
    }

    public function canCreate(): bool
    {
        return $this->canView() && $this->positiveId('department_id') && $this->positiveId('category_id')
            && (! config('tickets.board_id') || $this->positiveId('board_id'));
    }

    private function positiveId(string $key): bool
    {
        return filter_var(config('tickets.'.$key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }
}
