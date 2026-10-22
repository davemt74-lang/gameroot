<?php
declare(strict_types=1);
// Stripe endpoint is deliberately stateless: no browser session or CSRF cookie.
require dirname(__DIR__).'/app/stripe.php';
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
 stripe_record_event(portal_pdo(),$event);
 http_response_code(200);echo '{"received":true}';
} catch(JsonException|DomainException $e) {
 http_response_code(400);echo '{"received":false}';
} catch(Throwable $e) {
 error_log('Stripe webhook inbox unavailable: '.get_class($e));
 http_response_code(503);echo '{"received":false}';
}
