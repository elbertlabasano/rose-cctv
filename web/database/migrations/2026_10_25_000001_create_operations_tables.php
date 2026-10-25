<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('viewer');
            $table->boolean('active')->default(true);
            $table->boolean('all_branches')->default(false);
            $table->string('team')->nullable();
        });
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 30)->unique();
            $table->string('address')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('branch_user', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['branch_id', 'user_id']);
        });
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('asset_id', 50)->unique();
            $table->string('name', 100);
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->foreignId('parent_id')->nullable()->constrained('devices')->restrictOnDelete();
            $table->string('location')->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('status', 25)->default('unknown');
            $table->timestamp('status_observed_at')->nullable();
            $table->string('owner')->nullable();
            $table->unsignedSmallInteger('channel')->nullable();
            $table->unsignedSmallInteger('channels')->nullable();
            $table->decimal('storage_tb', 8, 1)->nullable();
            $table->string('disk_health')->nullable();
            $table->date('installed_at')->nullable();
            $table->date('warranty_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'ip']);
            $table->unique(['parent_id', 'channel']);
        });
        Schema::create('observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('status', 25)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'date']);
        });
        Schema::create('outages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('restored_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('incident_reports', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('priority', 20);
            $table->string('category', 60);
            $table->string('status', 30)->default('assigned');
            $table->timestamp('due_at');
            $table->text('signed_document_url')->nullable();
            $table->json('resolution')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('incident_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->text('notes');
            $table->json('details')->nullable();
            $table->timestamps();
        });
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('observation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('incident_log_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('name');
            $table->string('mime', 60);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
        Schema::create('ticket_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('observation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->json('payload')->nullable();
            $table->string('title', 180);
            $table->string('status', 25)->default('pending');
            $table->string('external_id')->nullable();
            $table->text('external_url')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamps();
        });
        Schema::create('followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->date('snoozed_until')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'subject_type', 'subject_id']);
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('action');
            $table->text('reason');
            $table->json('changes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'followups', 'ticket_requests', 'attachments', 'incident_logs', 'incident_reports', 'outages', 'observations', 'devices', 'branch_user', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'active', 'all_branches', 'team']));
    }
};
