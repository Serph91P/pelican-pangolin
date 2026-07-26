<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pangolin_server_publications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id')->unique();
            $table->uuid('server_uuid')->unique();
            $table->boolean('opted_in')->default(false);
            $table->boolean('suspended')->default(false);
            $table->boolean('deletion_pending')->default(false);
            $table->unsignedBigInteger('desired_revision')->default(1);
            $table->string('status', 16)->default('pending');
            $table->json('desired_state')->default('{}');
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pangolin_server_publications');
    }
};
