<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clusters', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('code', 30)->unique();
            $table->timestamps();
        });
        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('cluster_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cluster_id');
        });
        Schema::dropIfExists('clusters');
    }
};
