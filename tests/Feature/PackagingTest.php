<?php

it('builds an audited archive with one plugin root and no development artifacts', function () {
    $root = dirname(__DIR__, 2);
    $result = Process::path($root)->run(['bash', 'scripts/build-plugin.sh']);
    $result->throw();

    $zip = new ZipArchive;
    expect($zip->open($root.'/build/pelican-pangolin.zip'))->toBeTrue();

    $names = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $names[] = $zip->getNameIndex($index);
    }
    $zip->close();

    expect($names)->not->toBeEmpty()
        ->toContain('pelican-pangolin/resources/views/infrastructure.blade.php', 'pelican-pangolin/resources/views/infrastructure-blocks.blade.php')
        ->and(collect($names)->every(fn (string $name): bool => str_starts_with($name, 'pelican-pangolin/')))->toBeTrue()
        ->and(implode("\n", $names))->not->toContain('/tests/', '/vendor/', '/.git/', '.env', '.phpunit.cache', 'implementation-plan', '.opencode', '.hermes');
});
