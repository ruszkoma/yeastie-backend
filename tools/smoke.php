<?php

/**
 * Local smoke test over real HTTP, run by tools/smoke.sh inside the php container. Signs its own
 * Firebase-like tokens with a development key (accepted only when CI_ENVIRONMENT=development) and
 * checks the main flows end to end with the API database user. Exits non-zero on the first failure.
 */

require __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;

[$base, $privateKeyFile, $project, $dbHost, $dbName, $dbUser, $dbPassword] = array_slice($argv, 1);
$privateKey = file_get_contents($privateKeyFile);

function token(string $uid): string
{
    global $privateKey, $project;
    $now = time();

    return JWT::encode([
        'iss' => 'https://securetoken.google.com/' . $project, 'aud' => $project, 'sub' => $uid,
        'iat' => $now - 10, 'exp' => $now + 600, 'auth_time' => $now - 10,
    ], $privateKey, 'RS256', 'dev');
}

/**
 * @return array{0: int, 1: string, 2: string}
 */
function request(string $method, string $path, ?string $uid, ?array $body = null): array
{
    global $base;
    $headers = ['Accept: application/json'];
    if ($uid !== null) {
        $headers[] = 'Authorization: Bearer ' . token($uid);
    }
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
    ]);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    $raw        = (string) curl_exec($curl);
    $status     = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);

    return [$status, substr($raw, 0, $headerSize), substr($raw, $headerSize)];
}

function check(string $name, bool $ok, string $detail = ''): void
{
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : " -- {$detail}") . PHP_EOL;
    if (! $ok) {
        exit(1);
    }
}

$uid = 'smoke-' . bin2hex(random_bytes(4));

[$status, $headers, $body] = request('GET', 'v1/sync?after=0', null);
check('no token -> 401 problem+json', $status === 401 && str_contains($headers, 'application/problem+json') && json_decode($body, true)['code'] === 'unauthorized', $body);

[$status, $headers, $body] = request('GET', 'v1/nothing', $uid);
check('unknown route -> 404 problem+json', $status === 404 && json_decode($body, true)['code'] === 'not_found', "{$status} {$body}");

[$status, , $body] = request('POST', 'v1/sync', $uid, ['changes' => [[
    'client_change_id' => '12345678-1234-4234-8234-123456789abc', 'entity_type' => 'recipe', 'entity_id' => 'r-1',
    'payload' => ['name' => 'Smoke Pils', 'og' => 1.048], 'updated_at' => '2026-11-11T10:00:00.000Z',
]]]);
check('push -> accepted', $status === 200 && json_decode($body, true)['results'][0]['status'] === 'accepted', "{$status} {$body}");

[$status, , $body] = request('GET', 'v1/sync?after=0', $uid);
$pulled = json_decode($body, true);
check('pull -> document back', $status === 200 && $pulled['changes'][0]['payload']['name'] === 'Smoke Pils', "{$status} {$body}");

[$status, , $body] = request('GET', 'v1/entitlement', $uid);
check('entitlement -> free, trial available', $status === 200 && json_decode($body, true)['tier'] === 'free' && json_decode($body, true)['trial_available'] === true, $body);

[$status, , $body] = request('POST', 'v1/trial', $uid);
check('trial -> started', $status === 200 && json_decode($body, true)['tier'] === 'trial', "{$status} {$body}");

[$status, , $body] = request('POST', 'v1/purchases/verify', $uid, ['package_name' => 'pl.cprojekt.yeastie', 'product_id' => 'premium', 'purchase_token' => 'x']);
check('verify without Google credentials -> 503, no details', $status === 503 && json_decode($body, true)['code'] === 'server_error', "{$status} {$body}");

[$status, , $body] = request('DELETE', 'v1/me', $uid);
check('delete without Google credentials -> 503', $status === 503, "{$status} {$body}");

[$status, , $body] = request('GET', 'v1/sync?after=0', $uid);
check('data kept after failed delete', $status === 200 && count(json_decode($body, true)['changes']) === 1, $body);

$db = new mysqli($dbHost, $dbUser, $dbPassword, $dbName);
try {
    $db->query('CREATE TABLE smoke_ddl (id INT)');
    $ddl = true;
} catch (mysqli_sql_exception) {
    $ddl = false;
}
check('API database user cannot run DDL', $ddl === false);
$db->query("DELETE FROM document WHERE user_id = '{$uid}'");
$db->query("DELETE FROM applied_change WHERE user_id = '{$uid}'");
$db->query("DELETE FROM sync_user WHERE user_id = '{$uid}'");
$db->query("DELETE FROM entitlement WHERE user_id = '{$uid}'");

echo 'Smoke test passed.' . PHP_EOL;
