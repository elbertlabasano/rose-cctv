<?php

namespace App\Http\Controllers;

use App\Exceptions\TicketProviderException;
use App\Http\Requests\TicketListRequest;
use App\Jobs\CreateExternalTicket;
use App\Models\TicketRequest;
use App\Services\AccessService;
use App\Services\TicketIntegration;
use App\Services\TicketReadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TicketController extends Controller
{
    public function index(TicketListRequest $request, TicketReadService $tickets)
    {
        $input = $request->validated();
        try {
            return response()->json($tickets->page($request->user(), $input['date'], (int) ($input['page'] ?? 1)))
                ->header('Cache-Control', 'private, no-store');
        } catch (Throwable $error) {
            Log::warning('Ticket viewing failed.', ['exception_class' => $error::class]);

            return response()->json(['message' => $error instanceof TicketProviderException ? $error->getMessage()
                : 'Live tickets could not be loaded. Please try again later.'], 503)->header('Cache-Control', 'private, no-store');
        }
    }

    public function retry(Request $request, TicketRequest $ticket, AccessService $access)
    {
        abort_unless($access->write($request->user()), 403);
        $access->branch($request->user(), $ticket->observation->device->branch_id);
        if ($ticket->observation->date->format('Y-m-d') !== now()->toDateString()) {
            return back()->with('error', 'Past daily records are read-only. Ticket creation can only be triggered for today.');
        }
        if (! app(TicketIntegration::class)->canCreate()) {
            return back()->with('error', 'Ticket integration is not connected yet.');
        }
        if ($ticket->status === 'unconfirmed') {
            return back()->with('error', 'A ticket may already exist. Check Support Hub before any new attempt. This request was not resent.');
        }
        DB::transaction(function () use ($ticket) {
            $ticket = TicketRequest::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($ticket->external_url || ! in_array($ticket->status, ['failed', 'unavailable', 'pending'])) {
                return;
            }
            $ticket->update(['status' => 'queued', 'error' => null]);
            CreateExternalTicket::dispatch($ticket->id)->afterCommit();
        });

        return back()->with('success', 'Request reviewed. Only rejected or unsent requests can be queued; uncertain requests must be checked in Support Hub.');
    }
}
