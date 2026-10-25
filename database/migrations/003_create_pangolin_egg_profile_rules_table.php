<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pangolin_egg_profile_rules')) {
            $this->recoverPartialTable();

            return;
        }

        Schema::create('pangolin_egg_profile_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('egg_profile_id')->constrained('pangolin_egg_profiles')->cascadeOnDelete();
            $table->unsignedInteger('allocation_position');
            $table->string('mode', 8);
            $table->string('domain')->nullable();
            $table->string('subdomain')->nullable();
            $table->timestamps();
            $table->unique(['egg_profile_id', 'allocation_position'], 'pangolin_rule_profile_position_unique');
        });
    }

    private function recoverPartialTable(): void
    {
        $table = 'pangolin_egg_profile_rules';
        $fail = static function (): never {
            throw new DomainException('Unsafe partial migration 003: unexpected pangolin_egg_profile_rules schema or data; no recovery changes made. Investigate before retrying native plugin installation.');
        };
        // Only the known MySQL/MariaDB nontransactional DDL failure is recoverable.
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $fail();
        }
        $expected = [
            'id' => ['bigint unsigned', false, true],
            'egg_profile_id' => ['bigint unsigned', false, false],
            'allocation_position' => ['int unsigned', false, false],
            'mode' => ['varchar(8)', false, false],
            'domain' => ['varchar(255)', true, false],
            'subdomain' => ['varchar(255)', true, false],
            'created_at' => ['timestamp', true, false],
            'updated_at' => ['timestamp', true, false],
        ];
        $columns = Schema::getColumns($table);
        if (array_column($columns, 'name') !== array_keys($expected)) {
            $fail();
        }
        $extras = DB::table('information_schema.columns')->where('table_schema', DB::connection()->getDatabaseName())
            ->where('table_name', $table)->pluck('extra', 'column_name');
        foreach ($columns as $column) {
            $type = preg_replace('/\b(bigint|int)\(\d+\)/', '$1', $column['type']);
            if ([$type, $column['nullable'], $column['auto_increment']] !== $expected[$column['name']]
                || ! in_array($column['default'], [null, 'NULL'], true) || $column['generation'] !== null
                || $extras[$column['name']] !== ($column['name'] === 'id' ? 'auto_increment' : '')) {
                $fail();
            }
        }
        $tables = array_column(Schema::getTables(), null, 'name');
        if (strtolower($tables[$table]['engine'] ?? '') !== 'innodb') {
            $fail();
        }
        $foreign = Schema::getForeignKeys($table);
        if (count($foreign) !== 1 || $foreign[0]['columns'] !== ['egg_profile_id']
            || $foreign[0]['foreign_schema'] !== DB::connection()->getDatabaseName()
            || $foreign[0]['foreign_table'] !== 'pangolin_egg_profiles' || $foreign[0]['foreign_columns'] !== ['id']
            || $foreign[0]['on_delete'] !== 'cascade' || $foreign[0]['on_update'] !== 'restrict') {
            $fail();
        }
        $hasUnique = false;
        $hasPrimary = false;
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['primary'] && $index['unique'] && $index['columns'] === ['id']) {
                $hasPrimary = true;
            } elseif ($index['name'] === 'pangolin_egg_profile_rules_egg_profile_id_foreign'
                && ! $index['unique'] && $index['columns'] === ['egg_profile_id']) {
                // Preserve the original FK support index.
            } elseif ($index['name'] === 'pangolin_rule_profile_position_unique'
                && $index['unique'] && $index['columns'] === ['egg_profile_id', 'allocation_position']) {
                $hasUnique = true;
            } else {
                $fail();
            }
        }
        if (! $hasPrimary
            || DB::table($table)->select('egg_profile_id', 'allocation_position')->groupBy('egg_profile_id', 'allocation_position')->havingRaw('COUNT(*) > 1')->exists()
            || DB::table($table.' as rules')->leftJoin('pangolin_egg_profiles as profiles', 'profiles.id', '=', 'rules.egg_profile_id')->whereNull('profiles.id')->exists()) {
            $fail();
        }
        if (! $hasUnique) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique(['egg_profile_id', 'allocation_position'], 'pangolin_rule_profile_position_unique'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pangolin_egg_profile_rules');
    }
};
