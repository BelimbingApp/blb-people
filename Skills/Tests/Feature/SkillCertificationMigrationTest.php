<?php

use Illuminate\Database\Migrations\Migration;

function skillCertificationMigration(): Migration
{
    return require __DIR__.'/../../Database/Migrations/0330_02_12_000000_create_people_connector_skill_certification_tables.php';
}

test('skill certification immutability guards can be installed again safely', function (): void {
    $migration = skillCertificationMigration();
    $installer = new ReflectionMethod($migration, 'createImmutabilityGuards');
    $installer->setAccessible(true);

    $installer->invoke($migration);
    $installer->invoke($migration);

    expect(true)->toBeTrue();
});
