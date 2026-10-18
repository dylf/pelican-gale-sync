<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gale_sync_servers', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('server_id')->unique();

            // What to follow: a Gale sync ID, or a profile code (a frozen snapshot).
            $table->string('profile')->nullable();

            // How to sync.
            $table->boolean('skip_client_only')->default(true);
            $table->string('config_mode')->default('missing');
            $table->boolean('auto_apply_stopped')->default(false);
            $table->boolean('auto_apply_empty')->default(false);

            // What the last look at the profile found.
            $table->timestamp('last_checked_at')->nullable();
            $table->string('remote_updated_at')->nullable();
            $table->json('pending')->nullable();
            $table->text('last_error')->nullable();

            // What the last sync did, and whether one is running.
            $table->string('status')->default('idle');
            $table->timestamp('status_since')->nullable();
            $table->text('status_message')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gale_sync_servers');
    }
};
