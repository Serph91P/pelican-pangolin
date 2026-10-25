<?php

// Runs only inside the disposable Panel image from scripts/test-mariadb-install.sh.
use App\Enums\PluginStatus;
use App\Models\Plugin;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
});
$scenario = $argv[1];
$table = 'pangolin_egg_profile_rules';
$path = '/var/www/html/plugins/pelican-pangolin/database/migrations/';
$files = glob($path.'*.php');
$check = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$sqlFails = function (Closure $operation, int $error) use ($check): void {
    try {
        $operation();
    } catch (QueryException $exception) {
        $check((int) $exception->errorInfo[1] === $error, $exception->getMessage());

        return;
    }
    throw new RuntimeException('Expected MariaDB error '.$error);
};
$ledger = fn () => DB::table('migrations')->whereIn('migration', array_map(fn ($file) => basename($file, '.php'), $files))->orderBy('migration')->pluck('migration')->all();
$check(Plugin::findOrFail('pelican-pangolin')->status === PluginStatus::NotInstalled, 'Must start not_installed');
$invalid = in_array($scenario, ['duplicate', 'orphan', 'wrong-column', 'missing-fk', 'wrong-fk', 'wrong-index', 'extra-column', 'wrong-extra', 'wrong-check'], true);
if ($scenario !== 'fresh') {
    // Native migrator seeds only 001/002; the old 003 DDL reproduces error 1059.
    Artisan::call('migrate', ['--path' => array_slice($files, 0, 2), '--realpath' => true, '--force' => true]);
    // Use the checked-in failing DDL fixture, not the candidate migration.
    require __DIR__.'/partial-003.php';
    $sqlFails(fn () => $legacy->up(), 1059);
    $check(count($ledger()) === 2, 'Only 001/002 may be logged before retry');
    $check(DB::table('migrations')->whereIn('migration', $ledger())->pluck('batch')->all() === [8, 8], 'Original partial ledger batch must be 8');
    $check(DB::table($table)->count() === 0 && DB::table('pangolin_egg_profiles')->count() === 0 && DB::table('pangolin_node_mappings')->count() === 0, 'Original partial tables must be empty');
    $check(count(Schema::getIndexes($table)) === 2 && count(Schema::getForeignKeys($table)) === 1, 'Exact partial indexes/FK');
    $check(! Schema::hasTable('pangolin_server_publications') && ! Schema::hasTable('pangolin_managed_resources'), 'No later tables');
    if (in_array($scenario, ['data', 'complete', 'duplicate', 'orphan', 'wrong-check'], true)) {
        DB::table('pangolin_egg_profiles')->insert(['id' => 1, 'egg_uuid' => 'e0a0a0a0-0000-4000-8000-000000000001', 'name' => 'Preserved']);
        DB::table($table)->insert(['id' => 41, 'egg_profile_id' => 1, 'allocation_position' => 0, 'mode' => 'tcp', 'domain' => 'keep.example']);
    }
    if ($scenario === 'duplicate') {
        DB::table($table)->insert(['egg_profile_id' => 1, 'allocation_position' => 0, 'mode' => 'udp']);
    }
    if ($scenario === 'orphan') {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table($table)->where('id', 41)->update(['egg_profile_id' => 999]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
    if ($scenario === 'complete') {
        DB::statement("ALTER TABLE $table ADD UNIQUE pangolin_rule_profile_position_unique (egg_profile_id, allocation_position)");
    }
    if ($scenario === 'wrong-column') {
        DB::statement("ALTER TABLE $table MODIFY mode varchar(9) NOT NULL");
    }
    if (in_array($scenario, ['missing-fk', 'wrong-fk'], true)) {
        DB::statement("ALTER TABLE $table DROP FOREIGN KEY pangolin_egg_profile_rules_egg_profile_id_foreign");
        if ($scenario === 'wrong-fk') {
            DB::statement("ALTER TABLE $table ADD CONSTRAINT pangolin_egg_profile_rules_egg_profile_id_foreign FOREIGN KEY (egg_profile_id) REFERENCES pangolin_egg_profiles(id) ON DELETE RESTRICT");
        }
    }
    if ($scenario === 'wrong-index') {
        DB::statement("ALTER TABLE $table ADD INDEX pangolin_rule_profile_position_unique (egg_profile_id, allocation_position)");
    }
    if ($scenario === 'wrong-extra') {
        DB::statement("ALTER TABLE $table MODIFY updated_at timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP");
    }
    if ($scenario === 'wrong-check') {
        DB::statement("ALTER TABLE $table ADD CONSTRAINT review_tcp_only CHECK (mode = 'tcp')");
        $sqlFails(fn () => DB::table($table)->insert(['egg_profile_id' => 1, 'allocation_position' => 1, 'mode' => 'udp']), 4025);
    }
    if ($scenario === 'extra-column') {
        DB::statement("ALTER TABLE $table ADD unexpected int");
    }
}
$beforeLedger = DB::table('migrations')->orderBy('id')->get()->all();
$beforeColumns = $scenario === 'fresh' ? [] : Schema::getColumnListing($table);
$beforeRows = $scenario === 'fresh' ? '[]' : DB::table($table)->orderBy('id')->get()->toJson();
$beforeSchema = $scenario === 'fresh' ? null : DB::selectOne("SHOW CREATE TABLE $table")->{'Create Table'};
$install = new Process([PHP_BINARY, 'artisan', 'p:plugin:install', 'pelican-pangolin', '--no-interaction'], '/var/www/html');
$install->setTimeout(120);
$exit = $install->run();
$output = $install->getOutput().$install->getErrorOutput();
echo $scenario.' install exit='.$exit."\n".$output;
Plugin::refreshRows();
$status = Plugin::findOrFail('pelican-pangolin')->status;
$check(DB::table('migrations')->whereIn('id', array_column($beforeLedger, 'id'))->orderBy('id')->get()->all() == $beforeLedger, 'Existing core/plugin migration records must stay unchanged');
if ($invalid) {
    $check($status === PluginStatus::NotInstalled, 'Invalid state must remain not_installed');
    $check(count($ledger()) === 2, 'Invalid state must not advance ledger');
    $check(DB::selectOne("SHOW CREATE TABLE $table")->{'Create Table'} === $beforeSchema, 'Invalid schema must not be mutated');
    $check(DB::table($table)->orderBy('id')->get()->toJson() === $beforeRows, 'Invalid data must not be mutated');
    $check(str_contains($output, 'Unsafe partial migration 003:'), 'Schema/data rejection must be visible');
} else {
    $check($status === PluginStatus::Enabled, 'Native install must actually enable plugin');
    $check($ledger() === array_map(fn ($file) => basename($file, '.php'), $files), 'Native migrator must log all six migrations');
    $check(Schema::hasColumn($table, 'purpose') && Schema::hasTable('pangolin_allocation_aliases'), '006 must complete');
    if ($scenario !== 'fresh') {
        $check(DB::table($table)->select($beforeColumns)->orderBy('id')->get()->toJson() === $beforeRows, 'All existing rule values/IDs must survive');
    }
    if (! in_array($scenario, ['data', 'complete'], true)) {
        DB::table('pangolin_egg_profiles')->insert(['id' => 1, 'egg_uuid' => 'e0a0a0a0-0000-4000-8000-000000000001', 'name' => 'Constraint test']);
        DB::table($table)->insert(['egg_profile_id' => 1, 'allocation_position' => 0, 'mode' => 'tcp']);
    }
    $sqlFails(fn () => DB::table($table)->insert(['egg_profile_id' => 1, 'allocation_position' => 0, 'mode' => 'udp']), 1062);
    $sqlFails(fn () => DB::table($table)->insert(['egg_profile_id' => 999, 'allocation_position' => 1, 'mode' => 'tcp']), 1452);
    DB::table('pangolin_egg_profiles')->where('id', 1)->delete();
    $check(DB::table($table)->count() === 0, 'FK cascade must work');
    foreach (Schema::getTables() as $schemaTable) {
        if (str_starts_with($schemaTable['name'], 'pangolin_')) {
            foreach (array_merge(Schema::getIndexes($schemaTable['name']), Schema::getForeignKeys($schemaTable['name'])) as $identifier) {
                $check(strlen($identifier['name']) <= 64, 'Identifier exceeds MariaDB limit');
            }
        }
    }
}
echo 'PASS '.$scenario.' MariaDB '.DB::selectOne('SELECT VERSION() AS version')->version.' status='.$status->value.' ledger='.count($ledger())."\n";
