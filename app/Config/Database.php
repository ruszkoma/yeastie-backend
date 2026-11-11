<?php

namespace Config;

use CodeIgniter\Database\Config;

/**
 * Database groups. Credentials come from `.env` (`database.<group>.<key>`), never from this file.
 *
 * - `default`: the API user (SELECT, INSERT, UPDATE, DELETE).
 * - `migrations`: the migrations user (also DDL), used by `php spark migrate -g migrations`.
 * - `tests`: the test database, selected automatically when ENVIRONMENT is `testing`.
 */
class Database extends Config
{
    public string $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;

    public string $defaultGroup = 'default';

    /**
     * @var array<string, mixed>
     */
    public array $default = self::MARIADB;

    /**
     * @var array<string, mixed>
     */
    public array $migrations = self::MARIADB;

    /**
     * @var array<string, mixed>
     */
    public array $tests = self::MARIADB;

    /**
     * MariaDB 11.4 through mysqli. Strict mode on, utf8mb4, no persistent connections (shared host).
     */
    private const MARIADB = [
        'DSN'          => '',
        'hostname'     => 'localhost',
        'username'     => '',
        'password'     => '',
        'database'     => '',
        'DBDriver'     => 'MySQLi',
        'DBPrefix'     => '',
        'pConnect'     => false,
        'DBDebug'      => true,
        'charset'      => 'utf8mb4',
        'DBCollat'     => 'utf8mb4_unicode_ci',
        'swapPre'      => '',
        'encrypt'      => false,
        'compress'     => false,
        'strictOn'     => true,
        'failover'     => [],
        'port'         => 3306,
        'numberNative' => true,
        'foundRows'    => false,
        'dateFormat'   => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
    ];

    public function __construct()
    {
        parent::__construct();

        // Tests never touch the live database.
        if (ENVIRONMENT === 'testing') {
            $this->defaultGroup = 'tests';
        }
    }
}
