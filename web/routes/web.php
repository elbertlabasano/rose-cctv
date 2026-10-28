<?php

use App\Http\Controllers\AdministrationController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\KeycloakController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\FollowupController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\ObservationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Middleware\EnsureAccountActive;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/auth/keycloak/redirect', [KeycloakController::class, 'redirect'])->middleware('throttle:20,1')->name('sso.redirect');
    Route::get('/auth/keycloak/callback', [KeycloakController::class, 'callback'])->middleware('throttle:20,1')->name('sso.callback');
});
Route::middleware(['auth', EnsureAccountActive::class, 'auth.session'])->group(function () {
    Route::get('/backups', [BackupController::class, 'index'])->name('backups');
    Route::post('/backups/download', [BackupController::class, 'download'])->middleware('throttle:2,1')->name('backups.download');
    Route::post('/backups/attachments/download', [BackupController::class, 'attachments'])->middleware('throttle:2,1')->name('backups.attachments.download');
    foreach (['dashboard' => 'overview', 'cameras' => 'cameras', 'checklist' => 'checklist', 'evidence' => 'evidence', 'outages' => 'outages', 'branches' => 'branches', 'process' => 'process', 'incidents' => 'incidents', 'tickets' => 'tickets', 'reminders' => 'reminders', 'users' => 'users', 'devices' => 'devices', 'reports' => 'reports'] as $path => $section) {
        Route::get('/'.$path, [WorkspaceController::class, 'index'])->defaults('section', $section)->name($path);
    }
    Route::get('/devices/search', [DeviceController::class, 'search'])->middleware('throttle:60,1')->name('devices.search');
    Route::get('/devices/{device}', [DeviceController::class, 'show'])->name('devices.show');
    Route::post('/devices', [DeviceController::class, 'store']);
    Route::put('/devices/{device}', [DeviceController::class, 'update']);
    Route::post('/observations', [ObservationController::class, 'store']);
    Route::post('/observations/quick-status', [ObservationController::class, 'quickStatus'])->name('observations.quick-status');
    Route::post('/incidents', [IncidentController::class, 'store']);
    Route::post('/incidents/{incident}/actions', [IncidentController::class, 'action']);
    Route::post('/branches', [AdministrationController::class, 'storeBranch']);
    Route::put('/branches/{branch}', [AdministrationController::class, 'updateBranch']);
    Route::post('/users', [AdministrationController::class, 'storeUser']);
    Route::put('/users/{account}', [AdministrationController::class, 'updateUser']);
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'show'])->name('attachments.download');
    Route::post('/tickets/{ticket}/retry', [TicketController::class, 'retry']);
    Route::get('/tickets/external', [TicketController::class, 'index'])->middleware('throttle:30,1')->name('tickets.external');
    Route::post('/followups', [FollowupController::class, 'store']);
    Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
