<?php

use App\Http\Controllers\AdministrationController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\FollowupController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\ObservationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Middleware\EnsureAccountActive;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:20,1');
});
Route::middleware(['auth', EnsureAccountActive::class, 'auth.session'])->group(function () {
    foreach (['dashboard' => 'overview', 'cameras' => 'cameras', 'checklist' => 'checklist', 'evidence' => 'evidence', 'outages' => 'outages', 'branches' => 'branches', 'process' => 'process', 'incidents' => 'incidents', 'tickets' => 'tickets', 'reminders' => 'reminders', 'users' => 'users', 'devices' => 'devices', 'reports' => 'reports'] as $path => $section) {
        Route::get('/'.$path, [WorkspaceController::class, 'index'])->defaults('section', $section)->name($path);
    }
    Route::post('/devices', [DeviceController::class, 'store']);
    Route::put('/devices/{device}', [DeviceController::class, 'update']);
    Route::post('/observations', [ObservationController::class, 'store']);
    Route::post('/incidents', [IncidentController::class, 'store']);
    Route::post('/incidents/{incident}/actions', [IncidentController::class, 'action']);
    Route::post('/branches', [AdministrationController::class, 'storeBranch']);
    Route::put('/branches/{branch}', [AdministrationController::class, 'updateBranch']);
    Route::post('/users', [AdministrationController::class, 'storeUser']);
    Route::put('/users/{account}', [AdministrationController::class, 'updateUser']);
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'show'])->name('attachments.download');
    Route::post('/tickets/{ticket}/retry', [TicketController::class, 'retry']);
    Route::post('/followups', [FollowupController::class, 'store']);
    Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
