<?php

namespace App\Http\Controllers;

use App\Services\WorkspaceService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WorkspaceController extends Controller
{
    public function index(Request $request, WorkspaceService $workspace)
    {
        $input = $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $section = $request->route('section') ?: 'overview';
        if ($section === 'users') {
            abort_unless($request->user()->role === 'admin', 403);
        }

        return Inertia::render('Workspace', ['section' => $section, 'workspace' => $workspace->data($request->user(), $input['date'] ?? now()->toDateString())]);
    }
}
