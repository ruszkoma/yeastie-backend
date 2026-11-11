<?php

namespace App\Commands;

use App\Libraries\Google\GoogleUnavailable;
use App\Libraries\Google\ServiceAccountTokens;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Checks the Google configuration from the server without touching any real account or purchase:
 * the service account key, a Firebase Admin lookup of a user id that cannot exist, a Play Developer
 * API lookup of a purchase token that cannot exist, and the Pub/Sub settings. Prints results only,
 * never tokens or key material. Meant for a one-off cron run (no SSH on the host).
 */
final class GoogleCheck extends BaseCommand
{
    protected $group       = 'Yeastie';
    protected $name        = 'yeastie:google-check';
    protected $description = 'Checks the service account key, Firebase Admin and Play Developer API access, and the RTDN settings.';

    private const LOOKUP_URL = 'https://identitytoolkit.googleapis.com/v1/projects/%s/accounts:lookup';
    private const PLAY_URL   = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/%s/purchases/subscriptionsv2/tokens/%s';

    private bool $failed = false;

    public function run(array $params)
    {
        $config = config('Yeastie');
        $tokens = service('serviceAccountTokens');

        // 1. Key file.
        $file = $config->serviceAccountFile;
        if ($file === '' || ! is_readable($file)) {
            $this->flag('Key file', $file === '' ? 'yeastie.serviceAccountFile is empty in .env.' : "Cannot read {$file}.");

            return EXIT_ERROR;
        }
        $key = json_decode((string) file_get_contents($file), true);
        if (! is_array($key) || ! is_string($key['client_email'] ?? null) || ! is_string($key['private_key'] ?? null)) {
            $this->flag('Key file', 'Not a service account JSON key.');

            return EXIT_ERROR;
        }
        $this->pass('Key file', 'service account ' . $key['client_email'] . ', project ' . ($key['project_id'] ?? '?'));
        $perms = fileperms($file) & 0o777;
        if (($perms & 0o077) !== 0) {
            CLI::write(sprintf('  Note: key file permissions are %o; 600 is enough.', $perms), 'yellow');
        }

        // 2. Firebase Admin (needed by DELETE /me).
        $this->checkFirebase($tokens, $config->firebaseProjectId);

        // 3. Play Developer API (needed by purchase verification and RTDN).
        $this->checkPlay($tokens, $config->playPackageName);

        // 4. RTDN settings.
        if ($config->pubsubAudience === '' || $config->pubsubServiceAccountEmail === '') {
            $this->flag('RTDN', 'Set yeastie.pubsubAudience and yeastie.pubsubServiceAccountEmail in .env.');
        } else {
            $this->pass('RTDN', 'audience ' . $config->pubsubAudience . ', push signed by ' . $config->pubsubServiceAccountEmail);
        }

        CLI::write($this->failed ? 'Some checks failed.' : 'All checks passed.', $this->failed ? 'red' : 'green');

        return $this->failed ? EXIT_ERROR : EXIT_SUCCESS;
    }

    private function checkFirebase(ServiceAccountTokens $tokens, string $projectId): void
    {
        if ($projectId === '') {
            $this->flag('Firebase Admin', 'yeastie.firebaseProjectId is empty in .env.');

            return;
        }
        $status = $this->httpStatus('Firebase Admin', fn () => service('curlrequest', [], null, null, false)->post(
            sprintf(self::LOOKUP_URL, rawurlencode($projectId)),
            [
                'http_errors' => false,
                'timeout'     => 15,
                'headers'     => ['Authorization' => 'Bearer ' . $tokens->accessToken(ServiceAccountTokens::SCOPE_IDENTITY_TOOLKIT)],
                'json'        => ['localId' => ['yeastie-google-check-no-such-user']],
            ],
        ));
        match (true) {
            $status === null => null,
            $status === 200  => $this->pass('Firebase Admin', 'access granted (account deletion will work).'),
            in_array($status, [401, 403], true) => $this->flag('Firebase Admin', "HTTP {$status}: grant the service account the Firebase Authentication Admin role in project {$projectId}."),
            default => $this->flag('Firebase Admin', "Unexpected HTTP {$status}."),
        };
    }

    private function checkPlay(ServiceAccountTokens $tokens, string $packageName): void
    {
        $status = $this->httpStatus('Play Developer API', fn () => service('curlrequest', [], null, null, false)->get(
            sprintf(self::PLAY_URL, rawurlencode($packageName), 'yeastie-google-check-no-such-token'),
            [
                'http_errors' => false,
                'timeout'     => 15,
                'headers'     => ['Authorization' => 'Bearer ' . $tokens->accessToken(ServiceAccountTokens::SCOPE_ANDROID_PUBLISHER)],
            ],
        ));
        match (true) {
            $status === null => null,
            // Google knows the app and lets us in, but has no such purchase: exactly what we want.
            in_array($status, [400, 404, 410], true) => $this->pass('Play Developer API', "access granted for {$packageName} (HTTP {$status} for a made-up token, as expected)."),
            in_array($status, [401, 403], true) => $this->flag('Play Developer API', "HTTP {$status}: enable the Google Play Android Developer API and invite the service account in Play Console (new permissions can take up to 24 h)."),
            default => $this->flag('Play Developer API', "Unexpected HTTP {$status}."),
        };
    }

    /**
     * @param callable(): \CodeIgniter\HTTP\ResponseInterface $request
     */
    private function httpStatus(string $label, callable $request): ?int
    {
        try {
            return $request()->getStatusCode();
        } catch (GoogleUnavailable $e) {
            $this->flag($label, $e->getMessage() . ' (the key may be revoked or the clock wrong).');
        } catch (Throwable $e) {
            $this->flag($label, 'Request failed: ' . $e->getMessage());
        }

        return null;
    }

    private function pass(string $label, string $message): void
    {
        CLI::write("[OK]   {$label}: {$message}", 'green');
    }

    private function flag(string $label, string $message): void
    {
        $this->failed = true;
        CLI::write("[FAIL] {$label}: {$message}", 'red');
    }
}
