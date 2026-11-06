<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('statamic-polls.drivers.database.connection');
    }

    public function up(): void
    {
        Schema::create(config('statamic-polls.drivers.database.table', 'poll_votes'), function (Blueprint $table) {
            $table->id();
            $table->string('poll_id', 64);
            $table->string('option_key', 128);
            $table->string('voter_id', 64);
            $table->string('ip_hash', 64);
            $table->string('site', 32)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['poll_id', 'voter_id']);
            $table->index(['poll_id', 'ip_hash']);
            $table->index(['poll_id', 'option_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('statamic-polls.drivers.database.table', 'poll_votes'));
    }
};
