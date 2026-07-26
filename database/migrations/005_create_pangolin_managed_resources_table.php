<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pangolin_managed_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_publication_id')->constrained('pangolin_server_publications')->cascadeOnDelete();
            $table->unsignedBigInteger('allocation_id');
            $table->string('mode', 8);
            $table->unsignedBigInteger('pangolin_resource_id')->nullable()->unique();
            $table->unsignedBigInteger('pangolin_target_id')->nullable()->unique();
            $table->unsignedInteger('public_port')->nullable();
            $table->string('confirmed_domain')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['server_publication_id', 'allocation_id', 'mode'], 'pangolin_resource_allocation_unique');
            $table->unique(['mode', 'public_port'], 'pangolin_public_port_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pangolin_managed_resources');
    }
};
