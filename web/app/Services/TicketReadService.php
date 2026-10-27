<?php

namespace App\Services;

use App\Contracts\TicketProvider;
use App\Models\TicketRequest;
use App\Models\User;
use Carbon\CarbonImmutable;

class TicketReadService
{
    public function __construct(private AccessService $access, private TicketProvider $provider) {}

    public function page(User $user, string $date, int $page): array
    {
        $date = CarbonImmutable::parse($date);
        $branches = $this->access->branches($user)->pluck('id');
        $requests = TicketRequest::whereNotNull('external_url')->whereHas('observation', fn ($query) => $query
            ->whereBetween('date', [$date->startOfMonth()->toDateString(), $date->endOfMonth()->toDateString()])
            ->whereHas('device', fn ($device) => $device->whereIn('branch_id', $branches)))
            ->get(['id', 'external_id', 'external_url']);
        if ($requests->isEmpty()) {
            return ['data' => [], 'page' => $page, 'has_more' => false];
        }
        $remote = $this->provider->tickets($page);
        $items = [];
        foreach ($remote['data'] as $ticket) {
            // The shared assignee can receive tickets from other apps and branches.
            // Never expose those: require both the confirmed ID and URL of an authorized local request.
            $request = $requests->first(fn ($request) => $request->external_id === $ticket['id'] && $request->external_url === $ticket['url']);
            if ($request) {
                $items[] = [...$ticket, 'request_id' => $request->id];
            }
        }

        return ['data' => $items, 'page' => $remote['page'], 'has_more' => $remote['has_more']];
    }
}
