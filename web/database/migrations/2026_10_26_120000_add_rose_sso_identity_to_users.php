<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('idcode', 100)->nullable()->unique();
            $table->string('keycloak_issuer', 191)->nullable();
            $table->string('keycloak_subject', 191)->nullable();
            $table->unique(['keycloak_issuer', 'keycloak_subject']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['idcode']);
            $table->dropUnique(['keycloak_issuer', 'keycloak_subject']);
            $table->dropColumn(['idcode', 'keycloak_issuer', 'keycloak_subject']);
        });
    }
};
