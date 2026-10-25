<?php

namespace App\Http\Controllers;

use App\Jobs\CreateExternalTicket;
use App\Models\TicketRequest;
use App\Services\AccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketController extends Controller
{
    public function retry(Request $request, TicketRequest $ticket, AccessService $access)
    {
        abort_unless($access->write($request->user()), 403);
        $access->branch($request->user(), $ticket->observation->device->branch_id);
        if ($ticket->observation->date->format('Y-m-d') !== now()->toDateString()) {
            return back()->with('error', 'Past daily records are read-only. Ticket creation can only be triggered for today.');
        }
        if (! config('tickets.endpoint')) {
            return back()->with('error', 'Ticket integration is not connected yet.');
        }
        DB::transaction(function () use ($ticket) {
            $ticket = TicketRequest::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($ticket->external_url || in_array($ticket->status, ['processing', 'queued'])) {
                return;
            }
            $ticket->update(['status' => 'queued', 'error' => null]);
            CreateExternalTicket::dispatch($ticket->id)->afterCommit();
        });

        return back()->with('success', 'Ticket request queued. Retries keep the same request reference.');
    }
}
