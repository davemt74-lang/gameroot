<?php
declare(strict_types=1);
require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/packages.php';

function stripe_checkout_table_sql(): string {
 return "CREATE TABLE IF NOT EXISTS stripe_checkout_attempts (
   id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
   order_id BIGINT UNSIGNED NOT NULL UNIQUE,
   user_id BIGINT UNSIGNED NOT NULL,
   product_id BIGINT UNSIGNED NOT NULL,
   idempotency_key VARCHAR(100) NOT NULL UNIQUE,
   session_id VARCHAR(190) NULL UNIQUE,
   checkout_url TEXT NULL,
   state VARCHAR(20) NOT NULL DEFAULT 'creating',
   expires_at TIMESTAMP NOT NULL,
   created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
   updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
   FOREIGN KEY (order_id) REFERENCES orders(id),
   FOREIGN KEY (user_id) REFERENCES users(id),
   FOREIGN KEY (product_id) REFERENCES products(id),
   INDEX checkout_user_product (user_id,product_id,state,expires_at)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
function stripe_checkout_schema_ready(PDO $pdo): bool {
 return $pdo->query("SHOW TABLES LIKE 'stripe_checkout_attempts'")->fetchColumn()!==false;
}
function upgrade_stripe_checkout_schema(PDO $pdo): void {$pdo->exec(stripe_checkout_table_sql());}
function stripe_checkout_url(string $url): bool {
 $parts=parse_url($url);
 return is_array($parts) && strtolower((string)($parts['scheme']??''))==='https'
     && strtolower((string)($parts['host']??''))==='checkout.stripe.com'
     && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
     && str_starts_with((string)($parts['path']??''),'/c/');
}
function checkout_site_origin(): string {
 $origin=get_setting('portal_origin');
 if($origin==='' || !preg_match('~^https://(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$~iD',$origin))
  throw new DomainException('Configure the portal HTTPS URL in owner integrations before Checkout.');
 return $origin;
}
function stripe_test_checkout_request(string $apiKey,array $params,string $idempotencyKey,?callable $transport=null): array {
 if(!str_starts_with($apiKey,'sk_test_'))throw new DomainException('Checkout is test-mode only until live acceptance is certified.');
 if($transport!==null){$result=$transport($params,$idempotencyKey);if(!is_array($result))throw new RuntimeException('Invalid Stripe response.');}
 else {
  if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL is required for Stripe Checkout.');
  $ch=curl_init('https://api.stripe.com/v1/checkout/sessions');
  if($ch===false)throw new RuntimeException('Stripe Checkout could not start.');
  curl_setopt_array($ch,[
   CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($params,'','&',PHP_QUERY_RFC3986),
   CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey,'Idempotency-Key: '.$idempotencyKey],
   CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
   CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS
  ]);
  try {
   $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
   if($code!==200 || !is_string($body) || strlen($body)>262144)throw new RuntimeException('Stripe Checkout could not start.');
   $result=json_decode($body,true,16,JSON_THROW_ON_ERROR);
  } finally {curl_close($ch);}
 }
 $id=$result['id']??null;$url=$result['url']??null;
 if(!is_string($id)||!preg_match('/^cs_test_[a-zA-Z0-9]{8,}$/D',$id)||!is_string($url)||!stripe_checkout_url($url))throw new RuntimeException('Stripe returned an invalid Checkout Session.');
 return ['id'=>$id,'url'=>$url];
}
function start_homeserver_checkout(PDO $pdo,int $userId,int $productId,?callable $transport=null,?string $keyOverride=null,?string $originOverride=null): string {
 if($userId<1||$productId<1)throw new DomainException('Invalid checkout.');
 if(!stripe_checkout_schema_ready($pdo))throw new DomainException('Stripe Checkout database upgrade required.');
 $key=$keyOverride??stripe_secret('stripe_api');
 if(!$key || !str_starts_with($key,'sk_test_'))throw new DomainException('Test-mode Stripe Checkout is not configured.');
 $origin=$originOverride??checkout_site_origin();
 $pdo->beginTransaction();
 try {
  $u=$pdo->prepare("SELECT id,email FROM users WHERE id=? AND status='active' FOR UPDATE");
  $u->execute([$userId]);$user=$u->fetch();if(!$user)throw new DomainException('Account unavailable.');
  $q=$pdo->prepare('SELECT p.id,p.name,p.price_cents,p.active,d.delivery_type,d.billing_type FROM products p INNER JOIN package_details d ON d.product_id=p.id WHERE p.id=?');
  $q->execute([$productId]);$product=$q->fetch();
  if(!$product||!$product['active']||$product['delivery_type']!=='homeserver'||$product['billing_type']!=='one_time'||(int)$product['price_cents']<1)
   throw new DomainException('This package is not available for one-time checkout.');
  $q=$pdo->prepare('SELECT id FROM entitlements WHERE user_id=? AND product_id=?');
  $q->execute([$userId,$productId]);if($q->fetchColumn())throw new DomainException('This HomeServer package is already owned.');
  $q=$pdo->prepare("SELECT a.order_id,a.state,a.checkout_url,a.expires_at,o.total_cents FROM stripe_checkout_attempts a INNER JOIN orders o ON o.id=a.order_id WHERE a.user_id=? AND a.product_id=? AND a.expires_at>NOW() AND a.state IN ('creating','ready','failed') ORDER BY a.id DESC LIMIT 1 FOR UPDATE");
  $q->execute([$userId,$productId]);$attempt=$q->fetch();
  if($attempt && $attempt['state']==='ready' && is_string($attempt['checkout_url']) && stripe_checkout_url($attempt['checkout_url'])) {
   $pdo->commit();return $attempt['checkout_url'];
  }
  if($attempt){
   $orderId=(int)$attempt['order_id'];$cents=(int)$attempt['total_cents'];$sessionExpiry=strtotime((string)$attempt['expires_at']);
   $q=$pdo->prepare("UPDATE stripe_checkout_attempts SET state='creating' WHERE order_id=?");$q->execute([$orderId]);
  } else {
   $cents=(int)$product['price_cents'];$sessionExpiry=time()+3600;
   $q=$pdo->prepare("INSERT INTO orders(user_id,product_id,status,total_cents) VALUES (?,?,'pending',?)");
   $q->execute([$userId,$productId,$cents]);$orderId=(int)$pdo->lastInsertId();
   $q=$pdo->prepare("INSERT INTO stripe_checkout_attempts(order_id,user_id,product_id,idempotency_key,state,expires_at) VALUES (?,?,?,?,'creating',FROM_UNIXTIME(?))");
   $q->execute([$orderId,$userId,$productId,'gameroot-order-'.$orderId,$sessionExpiry]);
  }
  $pdo->commit();
 } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 $params=[
  'mode'=>'payment','expires_at'=>$sessionExpiry,'client_reference_id'=>(string)$orderId,'customer_email'=>(string)$user['email'],
  'line_items'=>[['price_data'=>['currency'=>'usd','unit_amount'=>$cents,'product_data'=>['name'=>$product['name']]],'quantity'=>1]],
  'metadata'=>['order_id'=>(string)$orderId,'user_id'=>(string)$userId],
  'success_url'=>$origin.'/account.php?checkout=returned','cancel_url'=>$origin.'/account.php?checkout=canceled'
 ];
 try {
  $session=stripe_test_checkout_request($key,$params,'gameroot-order-'.$orderId,$transport);
  $q=$pdo->prepare("UPDATE stripe_checkout_attempts SET session_id=?,checkout_url=?,state='ready' WHERE order_id=? AND state='creating'");
  $q->execute([$session['id'],$session['url'],$orderId]);
  return $session['url'];
 } catch(Throwable $e) {
  $q=$pdo->prepare("UPDATE stripe_checkout_attempts SET state='failed' WHERE order_id=? AND state='creating'");
  $q->execute([$orderId]);
  throw new DomainException('Stripe Checkout is temporarily unavailable. Please retry.');
 }
}
function apply_paid_homeserver_session(PDO $pdo,array $event,array $session): bool {
 if(!in_array($event['type'],['checkout.session.completed','checkout.session.async_payment_succeeded'],true))return false;
 if(($session['payment_status']??null)!=='paid'||($session['mode']??null)!=='payment')return false;
 if(($session['id']??null)!==$event['object_id'] || !is_string($session['id']))return false;
 if(($session['currency']??null)!=='usd' || !is_int($session['amount_total']??null))return false;
 $orderId=filter_var($session['client_reference_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 if(!$orderId)return false;
 $pdo->beginTransaction();
 try {
  $q=$pdo->prepare("SELECT a.session_id,a.user_id,a.product_id,o.total_cents,o.status,d.delivery_type,d.billing_type FROM stripe_checkout_attempts a INNER JOIN orders o ON o.id=a.order_id INNER JOIN package_details d ON d.product_id=a.product_id WHERE a.order_id=? FOR UPDATE");
  $q->execute([$orderId]);$row=$q->fetch();
  if(!$row || $row['session_id']!==$session['id'] || (int)$row['total_cents']!==$session['amount_total']
    || $row['delivery_type']!=='homeserver'||$row['billing_type']!=='one_time'
    || (string)$row['user_id']!==(string)($session['metadata']['user_id']??'')
    || (string)$orderId!==(string)($session['metadata']['order_id']??'')){$pdo->rollBack();return false;}
  $q=$pdo->prepare("UPDATE orders SET status='paid',provider_reference=? WHERE id=? AND status IN ('pending','paid')");
  $q->execute([$session['id'],$orderId]);
  $q=$pdo->prepare('INSERT INTO entitlements(user_id,product_id,source_order_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE id=id');
  $q->execute([$row['user_id'],$row['product_id'],$orderId]);
  $q=$pdo->prepare("UPDATE stripe_webhook_events SET state='applied' WHERE event_id=?");
  $q->execute([$event['id']]);$pdo->commit();return true;
 } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
