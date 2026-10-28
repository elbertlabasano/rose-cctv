<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Home', [
            'can_open_workspace' => (bool) $request->user()?->active,
            'year' => now('Asia/Manila')->year,
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }
}
