<?php
declare(strict_types=1);
require_once __DIR__.'/checkout.php';

/**
 * Hosted billing is independent from perpetual HomeServer entitlements.
 * One reviewed installation request can have at most one hosting subscription.
 */
function hosting_subscription_table_sql(): string {
 return "CREATE TABLE IF NOT EXISTS hosting_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  installation_request_id BIGINT UNSIGNED NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL UNIQUE,
  idempotency_key VARCHAR(100) NOT NULL UNIQUE,
  session_id VARCHAR(190) NULL UNIQUE,
  checkout_url TEXT NULL,
  stripe_subscription_id VARCHAR(190) NULL UNIQUE,
  stripe_customer_id VARCHAR(190) NULL,
  billing_interval VARCHAR(16) NOT NULL,
  recurring_cents INT UNSIGNED NOT NULL,
  state VARCHAR(24) NOT NULL DEFAULT 'creating',
  cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
  last_event_created BIGINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (installation_request_id) REFERENCES installation_requests(id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (order_id) REFERENCES orders(id),
  INDEX hosting_user (user_id,state)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
function hosting_invoice_table_sql(): string {
 return "CREATE TABLE IF NOT EXISTS hosting_subscription_invoices (
  invoice_id VARCHAR(190) NOT NULL PRIMARY KEY,
  hosting_subscription_id BIGINT UNSIGNED NOT NULL,
  amount_paid INT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  paid_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (hosting_subscription_id) REFERENCES hosting_subscriptions(id),
  INDEX hosting_invoice_history (hosting_subscription_id,paid_at)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
function hosting_schema_ready(PDO $pdo): bool {
 return $pdo->query("SHOW TABLES LIKE 'hosting_subscriptions'")->fetchColumn()!==false
     && $pdo->query("SHOW TABLES LIKE 'hosting_subscription_invoices'")->fetchColumn()!==false;
}
function upgrade_hosting_schema(PDO $pdo): void {
 $pdo->exec(hosting_subscription_table_sql());
 $pdo->exec(hosting_invoice_table_sql());
}
function hosting_interval(string $name): string {
 return match($name) {'monthly'=>'month','annual'=>'year',default=>throw new DomainException('Unsupported subscription billing interval.')};
}
function hosting_stripe_id(mixed $id,string $prefix): bool {
 return is_string($id) && (bool)preg_match('/^'.preg_quote($prefix,'/').'_[a-zA-Z0-9]{8,185}$/D',$id);
}
function hosting_subscription_rows(PDO $pdo,int $userId): array {
 if(!hosting_schema_ready($pdo))return [];
 $q=$pdo->prepare("SELECT h.id,h.installation_request_id,h.product_id,h.state,h.billing_interval,
 h.recurring_cents,h.cancel_at_period_end,h.stripe_customer_id,r.slug,r.project_name,p.name
 FROM hosting_subscriptions h INNER JOIN installation_requests r ON r.id=h.installation_request_id
 INNER JOIN products p ON p.id=h.product_id WHERE h.user_id=? ORDER BY h.id DESC");
 $q->execute([$userId]);return $q->fetchAll();
}
function hosting_checkout_ready(PDO $pdo): bool {
 return hosting_schema_ready($pdo) && stripe_schema_ready($pdo)
     && str_starts_with((string)stripe_secret('stripe_api'),'sk_test_') && get_setting('portal_origin')!=='';
}
function start_hosting_subscription(PDO $pdo,int $userId,int $requestId,int $productId,?callable $transport=null,?string $keyOverride=null,?string $originOverride=null): string {
 if(min($userId,$requestId,$productId)<1)throw new DomainException('Invalid hosting subscription.');
 if(!hosting_schema_ready($pdo) || !stripe_schema_ready($pdo))throw new DomainException('Subscription and webhook database upgrades are required.');
 $key=$keyOverride??stripe_secret('stripe_api');
 if(!is_string($key)||!str_starts_with($key,'sk_test_'))throw new DomainException('Hosting Checkout is available in Stripe test mode only.');
 $origin=$originOverride??checkout_site_origin();
 $pdo->beginTransaction();
 try {
  $q=$pdo->prepare("SELECT id,email FROM users WHERE id=? AND status='active' FOR UPDATE");
  $q->execute([$userId]);$user=$q->fetch();if(!$user)throw new DomainException('Account unavailable.');
  $q=$pdo->prepare("SELECT id,slug,status FROM installation_requests WHERE id=? AND user_id=? FOR UPDATE");
  $q->execute([$requestId,$userId]);$request=$q->fetch();
  if(!$request||$request['status']!=='reviewed')throw new DomainException('A reviewed installation request is required before hosting checkout.');
  $q=$pdo->prepare("SELECT p.id,p.name,p.price_cents,p.active,d.delivery_type,d.billing_type,d.billing_interval,d.currency
    FROM products p INNER JOIN package_details d ON d.product_id=p.id WHERE p.id=?");
  $q->execute([$productId]);$product=$q->fetch();
  if(!$product || !$product['active'] || $product['delivery_type']!=='hosting_addon' || $product['billing_type']!=='subscription'
    || !in_array($product['billing_interval'],['monthly','annual'],true) || $product['currency']!=='USD'
    || (int)$product['price_cents']<1)throw new DomainException('Choose an active monthly or annual hosting package.');
  $q=$pdo->prepare('SELECT * FROM hosting_subscriptions WHERE installation_request_id=? FOR UPDATE');
  $q->execute([$requestId]);$existing=$q->fetch();
  if($existing && $existing['state']!=='expired' && $existing['state']!=='canceled') {
   if((int)$existing['product_id']!==$productId)throw new DomainException('This installation already has a different hosting plan.');
   if($existing['state']==='ready' && strtotime((string)$existing['expires_at'])>time()
      && is_string($existing['checkout_url']) && stripe_checkout_url($existing['checkout_url'])) {
    $pdo->commit();return $existing['checkout_url'];
   }
   if($existing['state']==='failed' && strtotime((string)$existing['expires_at'])>time()){
    $orderId=(int)$existing['order_id'];$cents=(int)$existing['recurring_cents'];
    $interval=(string)$existing['billing_interval'];$expires=strtotime((string)$existing['expires_at']);
    $q=$pdo->prepare("UPDATE hosting_subscriptions SET state='creating' WHERE id=?");$q->execute([$existing['id']]);
   } else throw new DomainException('A hosting subscription or checkout already exists for this installation.');
  } else {
   $cents=(int)$product['price_cents'];$interval=(string)$product['billing_interval'];$expires=time()+3600;
   $q=$pdo->prepare("INSERT INTO orders(user_id,product_id,status,total_cents) VALUES (?,?,'pending',?)");
   $q->execute([$userId,$productId,$cents]);$orderId=(int)$pdo->lastInsertId();
   $idempotencyKey='gameroot-hosting-'.$orderId;
   if($existing){
    $q=$pdo->prepare("UPDATE hosting_subscriptions SET product_id=?,order_id=?,idempotency_key=?,session_id=NULL,checkout_url=NULL,
     stripe_subscription_id=NULL,stripe_customer_id=NULL,billing_interval=?,recurring_cents=?,state='creating',
     cancel_at_period_end=0,last_event_created=0,expires_at=FROM_UNIXTIME(?) WHERE id=?");
    $q->execute([$productId,$orderId,$idempotencyKey,$interval,$cents,$expires,$existing['id']]);
   } else {
    $q=$pdo->prepare("INSERT INTO hosting_subscriptions(installation_request_id,user_id,product_id,order_id,idempotency_key,
     billing_interval,recurring_cents,state,expires_at) VALUES (?,?,?,?,?,?,?,'creating',FROM_UNIXTIME(?))");
    $q->execute([$requestId,$userId,$productId,$orderId,$idempotencyKey,$interval,$cents,$expires]);
   }
  }
  $pdo->commit();
 } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 $params=[
  'mode'=>'subscription','expires_at'=>$expires,'client_reference_id'=>(string)$orderId,
  'customer_email'=>(string)$user['email'],
  'line_items'=>[['price_data'=>['currency'=>'usd','unit_amount'=>$cents,
    'recurring'=>['interval'=>hosting_interval($interval)],
    'product_data'=>['name'=>(string)$product['name']]],'quantity'=>1]],
  'metadata'=>['order_id'=>(string)$orderId,'user_id'=>(string)$userId,'installation_request_id'=>(string)$requestId],
  'subscription_data'=>['metadata'=>['order_id'=>(string)$orderId,'user_id'=>(string)$userId,'installation_request_id'=>(string)$requestId]],
  'success_url'=>$origin.'/account.php?subscription=returned',
  'cancel_url'=>$origin.'/account.php?subscription=canceled'
 ];
 try {
  $session=stripe_test_checkout_request($key,$params,'gameroot-hosting-'.$orderId,$transport);
  $q=$pdo->prepare("UPDATE hosting_subscriptions SET session_id=?,checkout_url=?,state='ready' WHERE order_id=? AND state='creating'");
  $q->execute([$session['id'],$session['url'],$orderId]);
  if($q->rowCount()!==1)throw new RuntimeException('Hosting checkout state changed.');
  return $session['url'];
 } catch(Throwable $e) {
  $q=$pdo->prepare("UPDATE hosting_subscriptions SET state='failed' WHERE order_id=? AND state='creating'");
  $q->execute([$orderId]);
  throw new DomainException('Stripe hosting Checkout is temporarily unavailable. Retry from your account.');
 }
}
function apply_paid_hosting_checkout(PDO $pdo,array $event,array $session): bool {
 if(($event['livemode']??null)!==false || !in_array($event['type']??null,['checkout.session.completed','checkout.session.async_payment_succeeded'],true))return false;
 if(($session['livemode']??null)!==false || ($session['mode']??null)!=='subscription' || ($session['payment_status']??null)!=='paid'
    || ($session['id']??null)!==($event['object_id']??null)
    || ($session['currency']??null)!=='usd' || !is_int($session['amount_total']??null)
    || !hosting_stripe_id($session['subscription']??null,'sub') || !hosting_stripe_id($session['customer']??null,'cus'))return false;
 $orderId=filter_var($session['client_reference_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 if(!$orderId || (string)$orderId!==(string)($session['metadata']['order_id']??''))return false;
 $pdo->beginTransaction();
 try {
  $q=$pdo->prepare("SELECT h.id,h.user_id,h.product_id,h.installation_request_id,h.session_id,h.state,
    h.recurring_cents,h.stripe_subscription_id,h.stripe_customer_id,
    o.status AS order_status,o.total_cents
    FROM hosting_subscriptions h INNER JOIN orders o ON o.id=h.order_id WHERE h.order_id=? FOR UPDATE");
  $q->execute([$orderId]);$row=$q->fetch();
  if(!$row){
   // A canceled subscription may have been replaced by a new checkout. Historical,
   // already-paid Checkout webhooks are acknowledged but may never restore service.
   $old=$pdo->prepare('SELECT status,provider_reference,user_id FROM orders WHERE id=? FOR UPDATE');
   $old->execute([$orderId]);$paid=$old->fetch();
   if(!$paid || $paid['status']!=='paid' || $paid['provider_reference']!==$session['id']
      || (string)$paid['user_id']!==(string)($session['metadata']['user_id']??'')){$pdo->rollBack();return false;}
   $q=$pdo->prepare("UPDATE stripe_webhook_events SET state='applied' WHERE event_id=?");
   $q->execute([$event['id']]);$pdo->commit();return true;
  }
  if($row['session_id']!==$session['id']
     || !in_array($row['order_status'],['pending','paid'],true)
     || (int)$row['recurring_cents']!==$session['amount_total']
     || (int)$row['total_cents']!==$session['amount_total']
     || (string)$row['user_id']!==(string)($session['metadata']['user_id']??'')
     || (string)$row['installation_request_id']!==(string)($session['metadata']['installation_request_id']??'')
     || ($row['stripe_subscription_id']!==null && $row['stripe_subscription_id']!==$session['subscription'])
     || ($row['stripe_customer_id']!==null && $row['stripe_customer_id']!==$session['customer'])){
   $pdo->rollBack();return false;
  }
  if($row['state']==='ready' && $row['order_status']==='pending'){
   $q=$pdo->prepare("UPDATE hosting_subscriptions SET state='active',stripe_subscription_id=?,stripe_customer_id=?,cancel_at_period_end=0 WHERE id=?");
   $q->execute([$session['subscription'],$session['customer'],$row['id']]);
   $q=$pdo->prepare("UPDATE orders SET status='paid',provider_reference=? WHERE id=? AND status='pending'");
   $q->execute([$session['id'],$orderId]);
  } elseif($row['order_status']!=='paid' || $row['stripe_subscription_id']!==$session['subscription']
     || $row['stripe_customer_id']!==$session['customer']){
   $pdo->rollBack();return false;
  }
  // A late/duplicate checkout MUST NOT change past_due, canceled or cancel_at_period_end.
  $q=$pdo->prepare("UPDATE stripe_webhook_events SET state='applied' WHERE event_id=?");
  $q->execute([$event['id']]);$pdo->commit();return true;
 } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function hosting_invoice_subscription_id(array $invoice): ?string {
 $direct=$invoice['subscription']??null;
 if(hosting_stripe_id($direct,'sub'))return $direct;
 $parent=$invoice['parent']['subscription_details']['subscription']??null;
 return hosting_stripe_id($parent,'sub')?$parent:null;
}
function apply_hosting_lifecycle_event(PDO $pdo,array $event,array $object,int $eventCreated): bool {
 if(($event['livemode']??null)!==false || $eventCreated<1)return false;
 $type=$event['type']??'';
 if(!in_array($type,['invoice.paid','invoice.payment_failed','customer.subscription.updated','customer.subscription.deleted'],true))return false;
 if(($object['id']??null)!==($event['object_id']??null) || ($object['livemode']??null)!==false)return false;
 $subId=str_starts_with($type,'invoice.')?hosting_invoice_subscription_id($object):($object['id']??null);
 $customerId=$object['customer']??null;
 if(!hosting_stripe_id($subId,'sub') || !hosting_stripe_id($customerId,'cus'))return false;
 $pdo->beginTransaction();
 try {
  $q=$pdo->prepare('SELECT id,state,stripe_customer_id,last_event_created,recurring_cents FROM hosting_subscriptions WHERE stripe_subscription_id=? FOR UPDATE');
  $q->execute([$subId]);$row=$q->fetch();
  if(!$row || $row['stripe_customer_id']!==$customerId){$pdo->rollBack();return false;}
  if($eventCreated<(int)$row['last_event_created'] || $row['state']==='canceled'){
   $q=$pdo->prepare("UPDATE stripe_webhook_events SET state='applied' WHERE event_id=?");$q->execute([$event['id']]);
   $pdo->commit();return true;
  }
  $next=$row['state'];$cancel=null;
  if($type==='invoice.paid'){
   if(($object['paid']??null)!==true || ($object['status']??null)!=='paid' || ($object['currency']??null)!=='usd'
     || !is_int($object['amount_paid']??null) || $object['amount_paid']!==(int)$row['recurring_cents']
     || !hosting_stripe_id($object['id'],'in')){$pdo->rollBack();return false;}
   $q=$pdo->prepare("INSERT INTO hosting_subscription_invoices(invoice_id,hosting_subscription_id,amount_paid) VALUES (?,?,?)
     ON DUPLICATE KEY UPDATE invoice_id=invoice_id");
   $q->execute([$object['id'],$row['id'],$object['amount_paid']]);
   $next='active';
  } elseif($type==='invoice.payment_failed'){
   if(($object['paid']??null)!==false){$pdo->rollBack();return false;}
   $next='past_due';
  } elseif($type==='customer.subscription.deleted'){
   $next='canceled';$cancel=0;
  } else {
   $status=(string)($object['status']??'');
   if(!in_array($status,['active','trialing','past_due','unpaid','paused','incomplete','incomplete_expired','canceled'],true)){$pdo->rollBack();return false;}
   $cancel=($object['cancel_at_period_end']??false)===true?1:0;
   if($status==='canceled'||$status==='incomplete_expired')$next='canceled';
   elseif(in_array($status,['past_due','unpaid','paused','incomplete'],true))$next='past_due';
   // "active" alone never grants access: a verified paid invoice/check-out is required.
  }
  $sql='UPDATE hosting_subscriptions SET state=?,last_event_created=GREATEST(last_event_created,?)';
  $params=[$next,$eventCreated];
  if($cancel!==null){$sql.=',cancel_at_period_end=?';$params[]=$cancel;}
  $sql.=' WHERE id=?';$params[]=$row['id'];
  $q=$pdo->prepare($sql);$q->execute($params);
  $q=$pdo->prepare("UPDATE stripe_webhook_events SET state='applied' WHERE event_id=?");$q->execute([$event['id']]);
  $pdo->commit();return true;
 } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function apply_expired_hosting_checkout(PDO $pdo,array $event,array $session): bool {
 if(($event['livemode']??null)!==false || ($event['type']??null)!=='checkout.session.expired'
  || ($session['id']??null)!==($event['object_id']??null) || ($session['mode']??null)!=='subscription' || ($session['livemode']??null)!==false)return false;
 $pdo->beginTransaction();
 try {
  $q=$pdo->prepare("SELECT id,state FROM hosting_subscriptions WHERE session_id=? FOR UPDATE");
  $q->execute([$session['id']]);$row=$q->fetch();
  if(!$row){$pdo->rollBack();return false;}
  if($row['state']==='ready'){
   $q=$pdo->prepare("UPDATE hosting_subscriptions SET state='expired',checkout_url=NULL WHERE id=?");
   $q->execute([$row['id']]);
  }
  $q=$pdo->prepare("UPDATE stripe_webhook_events SET state='applied' WHERE event_id=?");$q->execute([$event['id']]);
  $pdo->commit();return true;
 } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function stripe_portal_url(string $url): bool {
 $p=parse_url($url);
 return is_array($p) && ($p['scheme']??null)==='https' && ($p['host']??null)==='billing.stripe.com'
    && !isset($p['port']) && !isset($p['user']) && !isset($p['pass']) && str_starts_with((string)($p['path']??''),'/p/session');
}
function start_hosting_billing_portal(PDO $pdo,int $userId,int $subscriptionId,?callable $transport=null,?string $keyOverride=null,?string $originOverride=null): string {
 if(min($userId,$subscriptionId)<1 || !hosting_schema_ready($pdo))throw new DomainException('Hosting subscription unavailable.');
 $key=$keyOverride??stripe_secret('stripe_api');
 if(!is_string($key)||!str_starts_with($key,'sk_test_'))throw new DomainException('Stripe Billing Portal is test-only.');
 $origin=$originOverride??checkout_site_origin();
 $q=$pdo->prepare("SELECT stripe_customer_id,state FROM hosting_subscriptions WHERE id=? AND user_id=?");
 $q->execute([$subscriptionId,$userId]);$row=$q->fetch();
 if(!$row || !in_array($row['state'],['active','past_due'],true) || !hosting_stripe_id($row['stripe_customer_id'],'cus'))throw new DomainException('No manageable billing subscription is available.');
 $params=['customer'=>$row['stripe_customer_id'],'return_url'=>$origin.'/account.php'];
 if($transport!==null)$response=$transport($params);
 else {
  if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL required.');
  $ch=curl_init('https://api.stripe.com/v1/billing_portal/sessions');
  if($ch===false)throw new RuntimeException('Unable to open billing portal.');
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($params,'','&',PHP_QUERY_RFC3986),
   CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key],
   CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
   CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
  try {
   $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
   if($status!==200||!is_string($body)||strlen($body)>262144)throw new RuntimeException('Billing portal unavailable.');
   $response=json_decode($body,true,16,JSON_THROW_ON_ERROR);
  } finally{curl_close($ch);}
 }
 if(!is_array($response) || !is_string($response['url']??null) || !stripe_portal_url($response['url']))
  throw new RuntimeException('Untrusted billing portal response.');
 return $response['url'];
}
