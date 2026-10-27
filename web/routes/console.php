<?php

use App\Models\User;
use App\Services\AccessService;
use App\Services\OctoberMonitoringImportService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;

Artisan::command('rosewatch:import-october-2026 {--dry-run} {--initialize-status}', function (OctoberMonitoringImportService $import) {
    $result = $import->run((bool) $this->option('dry-run'), (bool) $this->option('initialize-status'));
    $this->line(json_encode($result, JSON_PRETTY_PRINT));

    return 0;
})->purpose('Import the reviewed October worksheet without overwriting existing daily records');

Artisan::command('rosewatch:create-user {email} {--name=} {--role=admin}', function () {
    $email = strtolower(trim($this->argument('email')));
    $name = $this->option('name') ?: $this->ask('Full name');
    $role = $this->option('role');
    if (! in_array($role, AccessService::ROLES)) {
        $this->error('Choose a valid role.');

        return 1;
    }
    $password = $this->secret('Password (at least 8 characters)');
    $confirmation = $this->secret('Confirm password');
    $validator = Validator::make(compact('email', 'name', 'password'), [
        'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        'name' => ['required', 'string', 'max:255'],
        'password' => ['required', 'string', 'min:8', 'max:4096'],
    ]);
    if ($validator->fails() || $password !== $confirmation) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }
        if ($password !== $confirmation) {
            $this->error('Passwords do not match.');
        }

        return 1;
    }
    User::create([...compact('email', 'name', 'password', 'role'), 'active' => true, 'all_branches' => true]);
    $this->info('Account created. Sign in with the email and password you supplied.');

    return 0;
})->purpose('Create an authorized RoseWatch account without public registration');
