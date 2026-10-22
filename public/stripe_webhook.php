<?php
declare(strict_types=1);
// Stripe webhooks use the raw signed body, never a browser session or CSRF cookie.
require dirname(__DIR__).'/app/subscriptions.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if($_SERVER['REQUEST_METHOD']!=='POST'){
 header('Allow: POST');http_response_code(405);echo '{"received":false}';exit;
}
portal_require_https();
if(!installed()){http_response_code(503);echo '{"received":false}';exit;}
try {
 $api=stripe_secret('stripe_api');
 $secret=stripe_secret('stripe_webhook');
 if(!$api || !$secret){http_response_code(503);echo '{"received":false}';exit;}
 $body=file_get_contents('php://input',false,null,0,262145);
 $signature=(string)($_SERVER['HTTP_STRIPE_SIGNATURE']??'');
 if(!is_string($body) || !stripe_verify_signature($body,$signature,$secret)){
  http_response_code(400);echo '{"received":false}';exit;
 }
 $event=stripe_parse_event($body);
 $isLive=str_starts_with($api,'sk_live_');
 $isTest=str_starts_with($api,'sk_test_');
 if((!$isLive&&!$isTest) || $event['livemode']!==$isLive){
  http_response_code(400);echo '{"received":false}';exit;
 }
 $pdo=portal_pdo();
 $tracked=stripe_record_event($pdo,$event);
 if(!$tracked){http_response_code(200);echo '{"received":true}';exit;}
 // Test-only handlers. Live events are stored, not used for fulfillment.
 if(!$event['livemode']){
  $full=json_decode($body,true,24,JSON_THROW_ON_ERROR);
  $object=$full['data']['object']??null;
  if(!is_array($object)){http_response_code(400);echo '{"received":false}';exit;}
  $type=$event['type'];
  $applied=true;
  if(in_array($type,['checkout.session.completed','checkout.session.async_payment_succeeded'],true)
     && ($object['payment_status']??null)==='paid'){
   if(($object['mode']??null)==='payment')$applied=apply_paid_homeserver_session($pdo,$event,$object);
   elseif(($object['mode']??null)==='subscription')$applied=apply_paid_hosting_checkout($pdo,$event,$object);
  } elseif($type==='checkout.session.expired' && ($object['mode']??null)==='subscription'){
   $applied=apply_expired_hosting_checkout($pdo,$event,$object);
  } elseif(in_array($type,['invoice.paid','invoice.payment_failed','customer.subscription.updated','customer.subscription.deleted'],true)){
   $created=$full['created']??0;
   $applied=is_int($created) && apply_hosting_lifecycle_event($pdo,$event,$object,$created);
  }
  // Stripe retries unmatched paid, failed-invoice, or lifecycle events, including deliveries
  // that raced ahead of checkout reconciliation. No access is granted on failure.
  if(!$applied){http_response_code(503);echo '{"received":false}';exit;}
 }
 http_response_code(200);echo '{"received":true}';
} catch(JsonException|DomainException $e){
 http_response_code(400);echo '{"received":false}';
} catch(Throwable $e){
 error_log('Stripe webhook processing unavailable: '.get_class($e));
 http_response_code(503);echo '{"received":false}';
}
