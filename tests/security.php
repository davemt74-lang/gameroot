<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/cpanel.php';
require dirname(__DIR__).'/app/packages.php';
require dirname(__DIR__).'/app/subscriptions.php';
function test(bool $ok,string $name):void {if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
$key=base64_encode(random_bytes(32));$config=['secret_key'=>$key];
$sealed=portal_encrypt('hg_test_secret_do_not_print',$config);
test(!str_contains($sealed['ciphertext'],'hg_test_secret'),'API key ciphertext is opaque');
test(portal_decrypt($sealed,$config)==='hg_test_secret_do_not_print','Secret decrypts with correct key');
try{portal_decrypt($sealed,['secret_key'=>base64_encode(random_bytes(32))]);throw new RuntimeException('Wrong key accepted');}catch(RuntimeException $e){if($e->getMessage()==='Wrong key accepted')throw $e;}test(true,'Wrong key is rejected');
$changed=$sealed;$changed['ciphertext']=base64_encode(random_bytes(48));
try{portal_decrypt($changed,$config);throw new RuntimeException('Tampering accepted');}catch(RuntimeException $e){if($e->getMessage()==='Tampering accepted')throw $e;}test(true,'Modified ciphertext is rejected');
foreach(['friday-games','game1','my-game-night'] as $slug)test(valid_slug($slug),'Valid subdomain: '.$slug);
foreach(['admin','www','root','../etc','foo.bar','a','two--','a_b','EXAMPLE','x'.str_repeat('a',42)] as $slug)test(!valid_slug($slug),'Invalid or reserved: '.$slug);
foreach(['games.example.com','game-night.example.co.uk'] as $d)test(valid_domain($d),'Valid base domain');
foreach(['http://example.com','bad_domain','example.com/foo','localhost','-wrong.com'] as $d)test(!valid_domain($d),'Reject malformed domain');
ob_start();require dirname(__DIR__).'/app/layout.php';
$escaped=e('<img src=x onerror="run()">');
test($escaped!=='<img src=x onerror="run()">'&&str_contains($escaped,'&lt;img'),'Output HTML escaping');
ob_end_clean();

foreach ([[200,'{"status":1,"data":{}}',true],[200,'{"status":0,"errors":["invalid"]}',false],[403,'Forbidden',false],[500,'error',false],[200,'<html>not json</html>',false],[200,'{"data":{}}',false]] as [$code,$body,$expected]) {
    $result=cpanel_verify_response($code,$body);
    test($result['ok']===$expected,'cPanel response verification '.$code.' '.substr(hash('sha256',$body),0,6));
    test(!str_contains($result['message'],$body),'cPanel error body never exposed');
}
foreach (['127.0.0.1','10.0.0.4','192.168.1.2','169.254.169.254','::1','fc00::5'] as $ip) {
    test(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE),'Private or reserved address rejected '.$ip);
}

$base=['sku'=>'homeserver-standard','name'=>'HomeServer Standard','description'=>'Local game engine',
 'delivery_type'=>'homeserver','billing_type'=>'one_time','billing_interval'=>'','price'=>'99.00','features'=>"Local play\nPhone controllers"];
test(validate_package($base)['price_cents']===9900,'HomeServer one-time package validation');
foreach ([
 array_merge($base,['delivery_type'=>'homeserver','billing_type'=>'subscription','billing_interval'=>'monthly']),
 array_merge($base,['delivery_type'=>'hosting_addon','billing_type'=>'one_time']),
 array_merge($base,['delivery_type'=>'cloud','billing_type'=>'one_time']),
 array_merge($base,['price'=>'-1']),
 array_merge($base,['price'=>'1.999']),
 array_merge($base,['sku'=>'../../escape']),
 array_merge($base,['billing_interval'=>'annual']),
] as $invalid) {
 try {validate_package($invalid);throw new RuntimeException('Invalid package accepted');}
 catch(DomainException $e){test(true,'Invalid package model rejected');}
}
$hosting=array_merge($base,['sku'=>'hosting-monthly','delivery_type'=>'hosting_addon','billing_type'=>'subscription','billing_interval'=>'monthly']);
test(validate_package($hosting)['billing_type']==='subscription','Optional subdomain hosting subscription valid');

$webhookSecret='whsec_testsecret123456789';
$eventPayload=json_encode(['id'=>'evt_TEST12345678','type'=>'checkout.session.completed','livemode'=>false,
 'data'=>['object'=>['id'=>'cs_test_12345678']]],JSON_THROW_ON_ERROR);
$now=time();$signed=(string)$now.'.'.$eventPayload;
$signature='t='.$now.',v1='.hash_hmac('sha256',$signed,$webhookSecret);
test(stripe_verify_signature($eventPayload,$signature,$webhookSecret,$now),'Signed Stripe event accepted');
test(!stripe_verify_signature($eventPayload.' ', $signature,$webhookSecret,$now),'Tampered Stripe payload rejected');
test(!stripe_verify_signature($eventPayload,$signature,$webhookSecret,$now+301),'Stale Stripe signature rejected');
test(!stripe_verify_signature($eventPayload,'t='.$now.',v1='.str_repeat('0',64),$webhookSecret,$now),'Invalid Stripe signature rejected');
test(!stripe_verify_signature($eventPayload,'t='.$now.',v1='.hash_hmac('sha256',$signed,'whsec_wrong12345678'),$webhookSecret,$now),'Wrong signing secret rejected');
$parsed=stripe_parse_event($eventPayload);
test($parsed['id']==='evt_TEST12345678' && $parsed['livemode']===false,'Signed event fields parse');
try{stripe_parse_event('{"type":"checkout.session.completed"}');throw new RuntimeException('Malformed event accepted');}
catch(DomainException $e){test(true,'Malformed event rejected');}

test(stripe_checkout_url('https://checkout.stripe.com/c/pay/cs_test_12345678'),'Trusted Stripe hosted URL accepted');
foreach(['http://checkout.stripe.com/c/pay/test','https://checkout.stripe.com.evil.test/c/pay/test',
 'https://someone:secret@checkout.stripe.com/c/pay/test','https://checkout.stripe.com:444/c/pay/test',
 'https://evil.test/c/pay/test'] as $badUrl)test(!stripe_checkout_url($badUrl),'Unsafe checkout URL rejected');
$checkoutParams=['mode'=>'payment'];
$mock=function(array $params,string $idempotencyKey) use (&$checkoutParams): array {
 $checkoutParams=$params;
 return ['id'=>'cs_test_1234567890','url'=>'https://checkout.stripe.com/c/pay/cs_test_1234567890'];
};
$mockSession=stripe_test_checkout_request('sk_test_dummy123456789',$checkoutParams,'gameroot-order-42',$mock);
test($mockSession['id']==='cs_test_1234567890','Mocked Stripe Checkout response validated');
try{stripe_test_checkout_request('sk_live_dummy123456789',$checkoutParams,'gameroot-order-42',$mock);throw new RuntimeException('Live checkout accepted');}
catch(DomainException $e){test(true,'Live-mode Stripe Checkout blocked');}


test(hosting_interval('monthly')==='month' && hosting_interval('annual')==='year','Monthly/annual Stripe intervals mapped correctly');
try{hosting_interval('weekly');throw new RuntimeException('Unknown billing interval accepted');}
catch(DomainException $e){test(true,'Unsupported recurring interval rejected');}
test(stripe_portal_url('https://billing.stripe.com/p/session/test_1234567890'),'Stripe-hosted billing portal URL recognized');
foreach(['http://billing.stripe.com/p/session/test','https://billing.stripe.com.evil.test/p/session/test',
 'https://billing.stripe.com:8443/p/session/test','https://bad@billing.stripe.com/p/session/test',
 'https://billing.stripe.com.evil.test/path','https://billing.stripe.com/not-session'] as $bad)
 test(!stripe_portal_url($bad),'Untrusted billing management redirect rejected');
test(hosting_invoice_subscription_id(['parent'=>['subscription_details'=>['subscription'=>'sub_1234567890']]])==='sub_1234567890',
 'Current Stripe invoice subscription reference parsed');
test(hosting_invoice_subscription_id(['subscription'=>'sub_1234567890'])==='sub_1234567890',
 'Legacy Stripe invoice subscription reference parsed');
test(hosting_invoice_subscription_id(['subscription'=>'sub_wrong'])===null,'Invalid invoice subscription reference rejected');
