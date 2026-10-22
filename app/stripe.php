<?php
declare(strict_types=1);
require_once __DIR__ . '/portal.php';

function stripe_event_table_sql(): string {
    return "CREATE TABLE IF NOT EXISTS stripe_webhook_events (
      event_id VARCHAR(190) NOT NULL PRIMARY KEY,
      event_type VARCHAR(100) NOT NULL,
      object_id VARCHAR(190) NOT NULL,
      live_mode TINYINT(1) NOT NULL,
      state VARCHAR(20) NOT NULL DEFAULT 'pending',
      deliveries INT UNSIGNED NOT NULL DEFAULT 1,
      received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      INDEX stripe_pending (state,received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
function stripe_schema_ready(PDO $pdo): bool {
    return $pdo->query("SHOW TABLES LIKE 'stripe_webhook_events'")->fetchColumn() !== false;
}
function upgrade_stripe_schema(PDO $pdo): void {
    $pdo->exec(stripe_event_table_sql());
}
function stripe_secret(string $provider): ?string {
    if (!in_array($provider,['stripe_api','stripe_webhook'],true)) throw new LogicException('Unknown Stripe secret type.');
    $q=portal_pdo()->prepare('SELECT nonce,ciphertext FROM integrations WHERE provider=?');
    $q->execute([$provider]);$row=$q->fetch();
    if(!$row)return null;
    return portal_decrypt(['nonce'=>$row['nonce'],'ciphertext'=>$row['ciphertext']]);
}
function stripe_verify_signature(string $payload,string $header,string $secret,?int $now=null): bool {
    if($payload==='' || strlen($payload)>262144 || strlen($header)>4096 || !str_starts_with($secret,'whsec_'))return false;
    $timestamp=null;$signatures=[];
    foreach(explode(',',$header) as $entry) {
        $pair=explode('=',trim($entry),2);
        if(count($pair)!==2)continue;
        if($pair[0]==='t' && ctype_digit($pair[1]))$timestamp=$pair[1];
        if($pair[0]==='v1' && preg_match('/^[a-f0-9]{64}$/D',$pair[1]))$signatures[]=$pair[1];
    }
    if($timestamp===null || $signatures===[])return false;
    $clock=$now??time();
    if(abs($clock-(int)$timestamp)>300)return false;
    $expected=hash_hmac('sha256',$timestamp.'.'.$payload,$secret);
    foreach($signatures as $candidate)if(hash_equals($expected,$candidate))return true;
    return false;
}
function stripe_allowed_event_types(): array {
    return [
      'checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.async_payment_failed','checkout.session.expired',
      'invoice.paid','invoice.payment_failed','customer.subscription.updated','customer.subscription.deleted',
      'charge.refunded'
    ];
}
function stripe_parse_event(string $payload): array {
    $event=json_decode($payload,true,24,JSON_THROW_ON_ERROR);
    if(!is_array($event))throw new DomainException('Invalid event.');
    $id=$event['id']??null;$type=$event['type']??null;
    $object=$event['data']['object']['id']??null;$live=$event['livemode']??null;
    if(!is_string($id)||!preg_match('/^evt_[a-zA-Z0-9]{8,185}$/D',$id) ||
       !is_string($type)||strlen($type)>100 ||
       !is_string($object)||!preg_match('/^[a-zA-Z0-9_]{3,190}$/D',$object) ||
       !is_bool($live))throw new DomainException('Invalid event.');
    return ['id'=>$id,'type'=>$type,'object_id'=>$object,'livemode'=>$live];
}
function stripe_record_event(PDO $pdo,array $event): bool {
    if(!in_array($event['type']??'',stripe_allowed_event_types(),true))return false;
    if(!stripe_schema_ready($pdo))throw new RuntimeException('Stripe database upgrade required.');
    $q=$pdo->prepare("INSERT INTO stripe_webhook_events(event_id,event_type,object_id,live_mode) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE deliveries=deliveries+1");
    $q->execute([$event['id'],$event['type'],$event['object_id'],$event['livemode']?1:0]);
    return true;
}
