<?php

namespace App\Controllers;

use App\Libraries\Http\ApiProblem;
use App\Services\Sync\ChangeIn;
use CodeIgniter\HTTP\ResponseInterface;

final class SyncController extends BaseController
{
    private const DEFAULT_LIMIT = 500;

    /** `GET /sync?after=&limit=` */
    public function pull(): ResponseInterface
    {
        return $this->guarded(function (): ResponseInterface {
            $after = $this->request->getGet('after');
            if (! is_string($after) || preg_match('/^\d{1,18}$/', $after) !== 1) {
                throw ApiProblem::validation('after must be a non-negative integer.');
            }
            $limit = $this->request->getGet('limit') ?? (string) self::DEFAULT_LIMIT;
            $max   = config('Yeastie')->maxSyncChanges;
            if (! is_string($limit) || preg_match('/^\d{1,4}$/', $limit) !== 1 || (int) $limit < 1 || (int) $limit > $max) {
                throw ApiProblem::validation("limit must be between 1 and {$max}.");
            }

            return $this->json(service('syncService')->pull(service('currentUser')->uid(), (int) $after, (int) $limit));
        });
    }

    /** `POST /sync` */
    public function push(): ResponseInterface
    {
        return $this->guarded(function (): ResponseInterface {
            $config = config('Yeastie');
            if (strlen((string) $this->request->getBody()) > $config->maxSyncBytes) {
                throw ApiProblem::payloadTooLarge("Send at most {$config->maxSyncBytes} bytes per request.");
            }
            $raw = $this->jsonBody()->changes ?? null;
            if (! is_array($raw)) {
                throw ApiProblem::validation('changes must be an array.');
            }
            if (count($raw) > $config->maxSyncChanges) {
                throw ApiProblem::validation("Send at most {$config->maxSyncChanges} changes per request.");
            }
            // Every change is validated before anything is written.
            $changes = [];
            foreach (array_values($raw) as $index => $item) {
                $changes[] = ChangeIn::fromJson($item, $index);
            }

            return $this->json(['results' => service('syncService')->push(service('currentUser')->uid(), $changes)]);
        });
    }
}
