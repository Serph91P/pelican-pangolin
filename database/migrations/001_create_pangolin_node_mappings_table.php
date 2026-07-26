<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pangolin_node_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('node_id')->unique();
            $table->unsignedBigInteger('site_id');
            $table->string('site_nice_id');
            $table->string('target_host');
            $table->boolean('enabled')->default(true);
            $table->timestamp('validated_at')->nullable();
            $table->text('validation_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pangolin_node_mappings');
    }
};
