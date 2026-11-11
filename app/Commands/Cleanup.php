<?php

namespace App\Commands;

use App\Libraries\Time\Iso;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Daily cron job: drops idempotency records and RTDN message ids older than the retention period.
 * A client retrying after that long simply applies the change again, which gives the same result.
 */
final class Cleanup extends BaseCommand
{
    protected $group       = 'Yeastie';
    protected $name        = 'yeastie:cleanup';
    protected $description = 'Removes old sync idempotency records and RTDN message ids.';

    public function run(array $params)
    {
        $days   = config('Yeastie')->retentionDays;
        $cutoff = Iso::toDb(service('clock')->now()->modify("-{$days} days"));
        $db     = db_connect();

        $db->query('DELETE FROM applied_change WHERE created_at < ?', [$cutoff]);
        $changes = $db->affectedRows();
        $db->query('DELETE FROM rtdn_event WHERE received_at < ?', [$cutoff]);
        $events = $db->affectedRows();

        CLI::write("Removed {$changes} applied changes and {$events} RTDN events older than {$days} days.");

        return EXIT_SUCCESS;
    }
}
