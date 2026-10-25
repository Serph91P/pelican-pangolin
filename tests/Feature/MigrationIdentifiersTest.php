<?php

use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Support\Facades\DB;

it('keeps every generated plugin database identifier within the MariaDB limit', function () {
    $connection = DB::connection();
    $connection->getSchemaBuilder();
    $original = $connection->getSchemaGrammar();
    $connection->setSchemaGrammar(new MySqlGrammar($connection));
    try {
        $queries = $connection->pretend(function () {
            foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) {
                (require $file)->up();
            }
        });
        expect($queries)->not->toBeEmpty();
        foreach ($queries as $query) {
            preg_match_all('/`([^`]+)`/', $query['query'], $matches);
            foreach ($matches[1] as $identifier) {
                expect(strlen($identifier), $identifier)->toBeLessThanOrEqual(64);
            }
        }
    } finally {
        $connection->setSchemaGrammar($original);
    }
});
