<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchlist', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->string('status')->default('watching');
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index('status');
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->index();
            $table->text('body');
            $table->timestampsTz();
        });

        Schema::create('rdap_status', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->string('status')->nullable();
            $table->date('expiry')->nullable();
            $table->date('pending_delete_date')->nullable();
            $table->date('predicted_drop_date')->nullable();
            $table->timestampTz('last_checked')->nullable();
            $table->boolean('restored')->default(false);
            $table->timestampsTz();

            $table->index('status');
            $table->index('predicted_drop_date');
        });

        Schema::create('weaker_checks', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->integer('http_status')->nullable();
            $table->boolean('has_contact_page')->default(false);
            $table->string('title')->nullable();
            $table->jsonb('nameservers')->nullable();
            $table->timestampTz('checked_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('job_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_id')->index();
            $table->string('step');
            $table->string('tld')->nullable();
            $table->string('status')->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedBigInteger('rows')->nullable();
            $table->unsignedBigInteger('bytes_done')->default(0);
            $table->unsignedBigInteger('bytes_total')->default(0);
            $table->text('message')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();

            $table->index(['run_id', 'step']);
            $table->index('status');
        });

        Schema::create('zone_tlds', function (Blueprint $table) {
            $table->id();
            $table->string('tld')->unique();
            $table->string('status')->default('unknown');
            $table->date('expiry_at')->nullable();
            $table->timestampTz('last_download_at')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('rows')->default(0);
            $table->text('message')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_tlds');
        Schema::dropIfExists('job_runs');
        Schema::dropIfExists('weaker_checks');
        Schema::dropIfExists('rdap_status');
        Schema::dropIfExists('notes');
        Schema::dropIfExists('watchlist');
    }
};
