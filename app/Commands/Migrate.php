<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\MigrationRunner;
use Config\Migrations;
use Throwable;

/**
 * Runs the API migrations with the `migrations` database user (DDL rights), so the API user can
 * stay without them. `spark migrate -g` does not do this: it still creates its bookkeeping table
 * through the default connection.
 */
final class Migrate extends BaseCommand
{
    protected $group       = 'Yeastie';
    protected $name        = 'yeastie:migrate';
    protected $description = 'Runs all new migrations with the migrations database user.';

    public function run(array $params)
    {
        // A group name (not a connection) makes the runner connect with that group and record it.
        $runner = new MigrationRunner(config(Migrations::class), 'migrations');
        $runner->setNamespace('App');

        try {
            $runner->latest();
        } catch (Throwable $e) {
            CLI::error('Migration failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }
        CLI::write('Migrations are up to date.', 'green');

        return EXIT_SUCCESS;
    }
}
