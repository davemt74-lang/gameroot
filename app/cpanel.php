<?php
declare(strict_types=1);
require_once __DIR__ . '/portal.php';

/**
 * Read-only cPanel UAPI connection check. No provisioning operations are performed.
 * Resolution is pinned to a public address so a DNS change cannot redirect the request.
 */
function cpanel_public_addresses(string $host): array {
    if (!valid_domain($host)) throw new DomainException('Invalid cPanel hostname.');
    $records = dns_get_record($host, DNS_A | DNS_AAAA);
    if (!is_array($records) || $records === []) throw new DomainException('The cPanel hostname could not be resolved.');
    $addresses = [];
    foreach ($records as $record) {
        $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new DomainException('The cPanel hostname must resolve only to public network addresses.');
        }
        $addresses[] = $ip;
    }
    if ($addresses === []) throw new DomainException('The cPanel hostname has no usable public address.');
    return array_values(array_unique($addresses));
}

function cpanel_verify_response(int $httpCode, string $body): array {
    if ($httpCode === 401 || $httpCode === 403) return ['ok'=>false,'message'=>'cPanel rejected the API token or account permissions.'];
    if ($httpCode !== 200) return ['ok'=>false,'message'=>'The cPanel server returned HTTP '.$httpCode.'.'];
    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['status']) || !in_array($data['status'], [1, '1', true], true)) {
        return ['ok'=>false,'message'=>'cPanel did not confirm a successful UAPI response.'];
    }
    return ['ok'=>true,'message'=>'Connection verified. Read-only cPanel API authentication succeeded.'];
}

function cpanel_verify_connection(callable $resolver = 'cpanel_public_addresses', ?callable $transport = null): array {
    $q = portal_pdo()->prepare('SELECT nonce,ciphertext,metadata_json FROM integrations WHERE provider=?');
    $q->execute(['hostgator']);
    $row = $q->fetch();
    if (!$row) throw new DomainException('Save your cPanel settings before testing.');
    $meta = json_decode((string)$row['metadata_json'], true, 8, JSON_THROW_ON_ERROR);
    $host = strtolower((string)($meta['host'] ?? ''));
    $username = (string)($meta['username'] ?? '');
    if (!valid_domain($host) || !preg_match('/^[a-zA-Z0-9_-]{1,50}$/D', $username)) {
        throw new DomainException('Saved cPanel connection settings are invalid.');
    }
    $addresses = $resolver($host);
    if (!is_array($addresses) || $addresses === []) throw new DomainException('No safe cPanel server address was found.');
    // Validate injected resolver results too; never connect to an internal network address.
    foreach ($addresses as $ip) {
        if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new DomainException('Unsafe cPanel server address.');
        }
    }
    $token = portal_decrypt(['nonce'=>$row['nonce'],'ciphertext'=>$row['ciphertext']]);
    if ($transport !== null) {
        $result = $transport($host, $username, $token, $addresses);
        if (!is_array($result) || !isset($result['code'], $result['body'])) throw new RuntimeException('Invalid transport result.');
        return cpanel_verify_response((int)$result['code'], (string)$result['body']);
    }
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for connection checks.');
    $ch = curl_init('https://'.$host.':2083/execute/Variables/get_user_information');
    if ($ch === false) throw new RuntimeException('Unable to initialize the connection.');
    $resolved = [];
    foreach ($addresses as $ip) $resolved[] = $host.':2083:'.(str_contains($ip, ':') ? '['.$ip.']' : $ip);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: cpanel '.$username.':'.$token, 'Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_RESOLVE => $resolved,
        CURLOPT_USERAGENT => 'GameRoot-ConnectionCheck/0.2',
        CURLOPT_MAXFILESIZE => 262144,
    ]);
    try {
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (!is_string($body)) return ['ok'=>false,'message'=>'Could not reach cPanel securely. Check hostname, TLS certificate, and port 2083.'];
        if (strlen($body) > 262144) return ['ok'=>false,'message'=>'cPanel response exceeded the safety limit.'];
        return cpanel_verify_response($code, $body);
    } finally {
        curl_close($ch);
    }
}
