<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * @internal
 */
final class DatabaseConfigTest extends CIUnitTestCase
{
    public function testMigrationsUseTheApiUserWhenNoSeparateUserIsConfigured(): void
    {
        $config = new Database();

        $this->assertSame('', (new \ReflectionClass(Database::class))->getConstant('MARIADB')['username']);
        $this->assertSame($config->default, $config->migrations);
    }
}
