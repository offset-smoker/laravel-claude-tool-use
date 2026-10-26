<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_releases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('record_id', 8);
            $table->foreign('record_id')->references('id')->on('records')->cascadeOnDelete();
            $table->json('redactions');
            $table->text('rationale');
            $table->char('record_body_sha256', 64);
            $table->uuid('requested_in_run');
            $table->string('status', 16)->default('pending')->index();
            $table->string('reviewed_by')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('released_body')->nullable();
            $table->timestamps();
        });

        // One open proposal per record. A partial index (SQLite and PostgreSQL).
        DB::statement("create unique index pending_releases_one_open_per_record on pending_releases (record_id) where status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_releases');
    }
};
