<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_imports', function (Blueprint $table) {
            $table->id();
            $table->string('source', 64)->default('pendingdelete.net');
            $table->string('dataset', 64);
            $table->string('tld', 32);
            $table->string('format', 16)->default('json');
            $table->date('dataset_day')->nullable();
            $table->string('built_utc', 32);
            $table->string('status', 16)->default('running');
            $table->unsignedBigInteger('downloaded')->default(0);
            $table->unsignedBigInteger('new_domains')->default(0);
            $table->unsignedBigInteger('updated_domains')->default(0);
            $table->unsignedBigInteger('status_transitions')->default(0);
            $table->unsignedBigInteger('invalid_rows')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();

            $table->unique(['source', 'dataset', 'tld', 'built_utc']);
            $table->index(['status', 'started_at']);
            $table->index(['dataset', 'tld', 'dataset_day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_imports');
    }
};
