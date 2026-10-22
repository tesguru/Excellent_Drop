<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zone_tlds', function (Blueprint $table) {
            $table->timestampTz('last_modified')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('zone_tlds', function (Blueprint $table) {
            $table->dropColumn('last_modified');
        });
    }
};
