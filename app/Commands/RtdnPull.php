<?php

namespace App\Commands;

use App\Libraries\Google\GoogleUnavailable;
use App\Libraries\Http\ApiProblem;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Cron job (every 5 minutes): fetches Google Play notifications (RTDN) from the Pub/Sub pull
 * subscription and applies them. The host's firewall drops Pub/Sub push requests, so the API
 * asks for the notifications itself.
 *
 * A message is acknowledged only after it was applied. When Google cannot be reached the run
 * stops and Pub/Sub delivers the rest again on the next run. Prints counts only, never tokens.
 */
final class RtdnPull extends BaseCommand
{
    protected $group       = 'Yeastie';
    protected $name        = 'yeastie:rtdn-pull';
    protected $description = 'Fetches Google Play notifications (RTDN) from Pub/Sub and applies them.';

    private const BATCH_SIZE  = 20;
    private const MAX_BATCHES = 25;

    public function run(array $params)
    {
        if (config('Yeastie')->pubsubSubscription === '') {
            CLI::write('yeastie.pubsubSubscription is empty in .env; nothing to pull.', 'yellow');

            return EXIT_ERROR;
        }
        $pubSub  = service('pubSub');
        $rtdn    = service('rtdnService');
        $applied = 0;
        $error   = null;

        try {
            for ($batch = 0; $batch < self::MAX_BATCHES && $error === null; $batch++) {
                $messages = $pubSub->pull(self::BATCH_SIZE);
                if ($messages === []) {
                    break;
                }
                $done = [];

                foreach ($messages as $message) {
                    try {
                        $rtdn->handleMessage($message->messageId, $message->data);
                    } catch (ApiProblem) {
                        // Unusable message: redelivery would not change that.
                    } catch (Throwable $e) {
                        $error = $e;
                        break;
                    }
                    $done[] = $message->ackId;
                }
                $pubSub->acknowledge($done);
                $applied += count($done);
            }
        } catch (GoogleUnavailable $e) {
            $error = $e;
        }

        if ($error !== null) {
            log_message('error', 'RTDN pull stopped: {class}: {message}', ['class' => $error::class, 'message' => $error->getMessage()]);
            CLI::write("Applied {$applied} notifications, then stopped: " . $error->getMessage(), 'red');

            return EXIT_ERROR;
        }
        CLI::write("Applied {$applied} notifications.");

        return EXIT_SUCCESS;
    }
}
