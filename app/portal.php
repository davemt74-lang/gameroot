<?php
declare(strict_types=1);
require_once __DIR__ . '/core.php';

function normalize_email(string $email): string { return strtolower(trim($email)); }
function register_customer(string $name, string $email, string $password): int {
    $name = trim($name); $email = normalize_email($email);
    if (strlen($name) < 2 || strlen($name) > 100) throw new DomainException('Enter a name between 2 and 100 characters.');
    if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new DomainException('Enter a valid email address.');
    if (strlen($password) < 12 || strlen($password) > 200) throw new DomainException('Use a password of at least 12 characters.');
    $pdo = portal_pdo();
    $q = $pdo->prepare('INSERT INTO users (display_name,email,password_hash,role,status) VALUES (?,?,?,?,?)');
    try { $q->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),'customer','active']); }
    catch (PDOException $e) { if ($e->getCode() === '23000') throw new DomainException('This email address cannot be registered.'); throw $e; }
    return (int)$pdo->lastInsertId();
}
function fingerprint(string $email): string {
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'),0,64);
    return hash_hmac('sha256', strtolower($email).'|'.$ip, base64_decode((string)portal_config()['secret_key'],true));
}
function limit_auth(string $fingerprint, int $max=8, int $minutes=15): bool {
    $pdo=portal_pdo();
    // PDO MySQL may treat an interval parameter as string; convert validated integer directly.
    $q=$pdo->prepare('SELECT COUNT(*) FROM auth_attempts WHERE fingerprint=? AND attempted_at > DATE_SUB(NOW(), INTERVAL '.max(1,min(60,$minutes)).' MINUTE)');
    $q->execute([$fingerprint]);
    return (int)$q->fetchColumn() < $max;
}
function record_auth_failure(string $fingerprint): void {
    $pdo=portal_pdo();
    $q=$pdo->prepare('INSERT INTO auth_attempts (fingerprint) VALUES (?)'); $q->execute([$fingerprint]);
    if (random_int(1,50)===1) $pdo->exec('DELETE FROM auth_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
}
function try_login(string $email, string $password): int {
    $email=normalize_email($email); $fp=fingerprint($email);
    if (!limit_auth($fp)) throw new DomainException('Too many attempts. Try again later.');
    $q=portal_pdo()->prepare('SELECT id,password_hash FROM users WHERE email=? AND status=?');
    $q->execute([$email,'active']); $row=$q->fetch();
    $valid = $row && password_verify($password,$row['password_hash']);
    if (!$valid) { record_auth_failure($fp); throw new DomainException('Incorrect email or password.'); }
    $q=portal_pdo()->prepare('DELETE FROM auth_attempts WHERE fingerprint=?');$q->execute([$fp]);
    if (password_needs_rehash($row['password_hash'],PASSWORD_DEFAULT)) {
        $q=portal_pdo()->prepare('UPDATE users SET password_hash=? WHERE id=?');
        $q->execute([password_hash($password,PASSWORD_DEFAULT),$row['id']]);
    }
    return (int)$row['id'];
}
function registration_rate_limit(): void {
    $ip=substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'),0,64);
    $hash=hash_hmac('sha256','signup|'.$ip,base64_decode((string)portal_config()['secret_key'],true));
    if (!limit_auth($hash,5,60)) throw new DomainException('Too many registrations from this address. Please try again later.');
    record_auth_failure($hash);
}
function create_installation_request(int $userId, string $name, string $slug): void {
    $name=trim($name);$slug=strtolower(trim($slug));
    if(strlen($name)<3||strlen($name)>100)throw new DomainException('Enter a project name (3–100 characters).');
    if(!valid_slug($slug))throw new DomainException('Choose a valid, non-reserved subdomain name (3–40 characters).');
    try {
        $q=portal_pdo()->prepare('INSERT INTO installation_requests(user_id,project_name,slug,kind,status) VALUES (?,?,?,?,?)');
        $q->execute([$userId,$name,$slug,'hosted_game','pending']);
    } catch(PDOException $e) { if ($e->getCode()==='23000') throw new DomainException('This subdomain is already requested.'); throw $e; }
}
function get_setting(string $key): string {
    $q=portal_pdo()->prepare('SELECT setting_value FROM portal_settings WHERE name=?');$q->execute([$key]);
    return (string)($q->fetchColumn() ?: '');
}
function save_setting(string $key, string $value): void {
    if(!in_array($key,['tenant_domain'],true))throw new LogicException('Unknown setting.');
    if($value!==''&&!valid_domain($value))throw new DomainException('Enter a valid base domain without http:// or slashes.');
    $q=portal_pdo()->prepare('INSERT INTO portal_settings(name,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $q->execute([$key,$value]);
}
function integration_providers(): array {
    return [
        'hostgator' => ['label'=>'HostGator / cPanel','hint'=>'Account-level cPanel API token. Provisioning remains disabled until connection and SSL certification.'],
        'openai' => ['label'=>'OpenAI','hint'=>'OpenAI API key for future server-side portal AI features.'],
        'anthropic' => ['label'=>'Claude (Anthropic)','hint'=>'Anthropic API key for future server-side Claude features.'],
    ];
}
function integration_statuses(): array {
    return portal_pdo()->query('SELECT provider,updated_at,metadata_json FROM integrations')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
}
function save_integration(string $provider, string $key, string $host='', string $username='', bool $revoke=false): void {
    if(!array_key_exists($provider,integration_providers()))throw new DomainException('Unknown integration.');
    $pdo=portal_pdo();
    if($revoke){$q=$pdo->prepare('DELETE FROM integrations WHERE provider=?');$q->execute([$provider]);return;}
    if($key==='')return; // An empty field is never interpreted as exposing or replacing a saved key.
    if(strlen($key)>8192 || strlen($key)<12)throw new DomainException('Invalid credential length.');
    $meta=[];
    if($provider==='hostgator'){
        $host=strtolower(trim($host));$username=trim($username);
        if(!valid_domain($host) || strlen($username)<1 || strlen($username)>50 || !preg_match('/^[a-zA-Z0-9_-]+$/D',$username))
            throw new DomainException('Enter a valid cPanel host name and account username.');
        $meta=['host'=>$host,'username'=>$username];
    }
    $encrypted=portal_encrypt($key);
    $q=$pdo->prepare('INSERT INTO integrations(provider,nonce,ciphertext,metadata_json) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE nonce=VALUES(nonce),ciphertext=VALUES(ciphertext),metadata_json=VALUES(metadata_json)');
    $q->execute([$provider,$encrypted['nonce'],$encrypted['ciphertext'],json_encode($meta,JSON_THROW_ON_ERROR)]);
}
