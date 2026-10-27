<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->string('check_result', 25)->nullable();
            $table->string('import_source')->nullable();
            $table->string('import_reference', 80)->nullable();
        });
        Schema::table('devices', function (Blueprint $table) {
            $table->date('status_reported_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->dropColumn(['check_result', 'import_source', 'import_reference']);
        });
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('status_reported_on');
        });
    }
};
