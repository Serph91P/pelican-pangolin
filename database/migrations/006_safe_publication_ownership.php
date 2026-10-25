<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pangolin_egg_profile_rules', function (Blueprint $table) {
            $table->unsignedInteger('allocation_port')->nullable();
            $table->string('purpose', 16)->nullable();
        });
        Schema::table('pangolin_managed_resources', function (Blueprint $table) {
            $table->string('ownership_name')->nullable();
            $table->string('confirmed_endpoint')->nullable();
        });
        Schema::create('pangolin_allocation_aliases', function (Blueprint $table) {
            $table->unsignedBigInteger('allocation_id')->primary();
            $table->foreignId('server_publication_id')->constrained('pangolin_server_publications')->cascadeOnDelete();
            $table->string('applied_alias');
        });
    }

    public function down(): void
    {
        if (DB::table('pangolin_managed_resources')->exists()) {
            throw new DomainException('Opt out all publications and complete remote cleanup before uninstalling; ownership records must not be discarded.');
        }
        if (Schema::hasTable('allocations')) {
            foreach (DB::table('pangolin_allocation_aliases')->get() as $owner) {
                DB::table('allocations')->where('id', $owner->allocation_id)->where('ip_alias', $owner->applied_alias)->update(['ip_alias' => null]);
            }
        }
        Schema::dropIfExists('pangolin_allocation_aliases');
        Schema::table('pangolin_managed_resources', fn (Blueprint $table) => $table->dropColumn(['ownership_name', 'confirmed_endpoint']));
        Schema::table('pangolin_egg_profile_rules', fn (Blueprint $table) => $table->dropColumn(['allocation_port', 'purpose']));
    }
};
