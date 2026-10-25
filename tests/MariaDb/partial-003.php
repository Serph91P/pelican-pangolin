<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Original 003: MariaDB commits CREATE/FK before rejecting the long UNIQUE name.
$legacy = new class extends Migration
{
    public function up(): void
    {
        Schema::create('pangolin_egg_profile_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('egg_profile_id')->constrained('pangolin_egg_profiles')->cascadeOnDelete();
            $table->unsignedInteger('allocation_position');
            $table->string('mode', 8);
            $table->string('domain')->nullable();
            $table->string('subdomain')->nullable();
            $table->timestamps();
            $table->unique(['egg_profile_id', 'allocation_position']);
        });
    }
};
