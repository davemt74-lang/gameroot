<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/packages.php';
require dirname(__DIR__).'/app/subscriptions.php';
function assert_test(bool $condition,string $label):void {if(!$condition)throw new RuntimeException($label);echo "PASS $label\n";}
$settings=[
 'db'=>['host'=>getenv('TEST_DB_HOST')?:'127.0.0.1','port'=>(int)(getenv('TEST_DB_PORT')?:3306),'name'=>getenv('TEST_DB_NAME')?:'gameroot_test','user'=>getenv('TEST_DB_USER')?:'portal','pass'=>getenv('TEST_DB_PASSWORD')?:'portalpass'],
 'secret_key'=>base64_encode(random_bytes(32))
];
$pdo=portal_pdo($settings);install_schema($pdo);
assert_test((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===0,'New portal database empty');
$file=dirname(__DIR__).'/var/config.php';
file_put_contents($file,'<?php return '.var_export($settings,true).';',LOCK_EX);chmod($file,0600);
// HTTP acceptance will perform the installer; remove test config after verifying schema.
unlink($file);
assert_test(!is_file($file),'Runtime configuration is not included in source');
assert_test((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===0,'Setup can still create the first owner');

assert_test(package_schema_ready($pdo),'New installation has package schema');
upgrade_package_schema($pdo);
upgrade_package_schema($pdo);
assert_test(package_schema_ready($pdo),'Package migration can be repeated safely');
$input=['sku'=>'homeserver-base','name'=>'HomeServer Standard','description'=>'One-time local installation',
 'delivery_type'=>'homeserver','billing_type'=>'one_time','billing_interval'=>'','price'=>'79.99',
 'features'=>"Local play\nPhone controller"];
$id=create_package($pdo,$input);
assert_test($id>0,'Draft HomeServer product created');
$rows=list_packages($pdo);
assert_test(count($rows)===1 && (int)$rows[0]['price_cents']===7999 && !$rows[0]['active'],'Package price and draft status persisted');
set_package_active($pdo,$id,true);
assert_test(count(list_packages($pdo,true))===1,'Activation controls package visibility');
update_package($pdo,$id,$input+['name'=>'not used']);
assert_test(count(list_packages($pdo))===1,'Package update is idempotent');
try {create_package($pdo,$input);throw new RuntimeException('Duplicate SKU accepted');}
catch(DomainException $e){assert_test(true,'Duplicate SKU rejected without orphan rows');}
assert_test((int)$pdo->query('SELECT COUNT(*) FROM package_details')->fetchColumn()===1,'No orphan package metadata');

$pdo->prepare("INSERT INTO users(email,display_name,password_hash,role,status) VALUES (?,?,?,?,?)")->execute([
 'package-test@example.test','Package Tester',password_hash('test-only-password',PASSWORD_DEFAULT),'customer','active'
]);
$userId=(int)$pdo->lastInsertId();
select_customer_package($pdo,$userId,$id);
select_customer_package($pdo,$userId,$id);
assert_test(count(customer_package_selections($pdo,$userId))===1,'Repeated selection is idempotent');
$pdo->prepare("INSERT INTO users(email,display_name,password_hash,role,status) VALUES (?,?,?,?,?)")->execute([
 'second-package-test@example.test','Second Tester',password_hash('test-only-password-2',PASSWORD_DEFAULT),'customer','active'
]);
$secondUserId=(int)$pdo->lastInsertId();
select_customer_package($pdo,$secondUserId,$id);
assert_test(count(customer_package_selections($pdo,$secondUserId))===1,'Other account owns its separate selection');
remove_customer_package($pdo,$userId,$id);
assert_test(count(customer_package_selections($pdo,$userId))===0,'Customer can remove own selection');
assert_test(count(customer_package_selections($pdo,$secondUserId))===1,'Removing selection cannot affect another customer');
select_customer_package($pdo,$userId,$id);
assert_test((int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()===0,'Selection does not create an order');
assert_test((int)$pdo->query('SELECT COUNT(*) FROM entitlements')->fetchColumn()===0,'Selection does not create a license');
set_package_active($pdo,$id,false);
try {select_customer_package($pdo,$userId,$id);throw new RuntimeException('Inactive package selected');}
catch(DomainException $e){assert_test(true,'Inactive packages cannot be newly selected');}
$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$secondUserId]);
assert_test((int)$pdo->query('SELECT COUNT(*) FROM customer_package_selections')->fetchColumn()===0,'Customer deletion cleans up package selections');

assert_test(stripe_schema_ready($pdo),'New installation has Stripe event inbox');
upgrade_stripe_schema($pdo);upgrade_stripe_schema($pdo);
$stripeEvent=['id'=>'evt_TEST12345678','type'=>'checkout.session.completed','object_id'=>'cs_test_12345678','livemode'=>false];
assert_test(stripe_record_event($pdo,$stripeEvent),'Supported Stripe event recorded');
assert_test(stripe_record_event($pdo,$stripeEvent),'Duplicate Stripe event accepted idempotently');
$stored=$pdo->query("SELECT state,deliveries FROM stripe_webhook_events WHERE event_id='evt_TEST12345678'")->fetch();
assert_test($stored && $stored['state']==='pending' && (int)$stored['deliveries']===2,'Duplicate delivery tracked without duplicate rows');
assert_test(!stripe_record_event($pdo,array_merge($stripeEvent,['type'=>'unknown.event'])),'Unsupported events ignored');
assert_test((int)$pdo->query('SELECT COUNT(*) FROM stripe_webhook_events')->fetchColumn()===1,'Event inbox stores one unique event');
assert_test((int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()===0,'Webhook inbox does not issue orders');
assert_test((int)$pdo->query('SELECT COUNT(*) FROM entitlements')->fetchColumn()===0,'Webhook inbox does not grant licenses');

assert_test(stripe_checkout_schema_ready($pdo),'New installation has Stripe Checkout schema');
upgrade_stripe_checkout_schema($pdo);upgrade_stripe_checkout_schema($pdo);
set_package_active($pdo,$id,true);
$pdo->prepare("INSERT INTO users(email,display_name,password_hash,role,status) VALUES (?,?,?,?,?)")->execute([
 'checkout-test@example.test','Checkout Tester',password_hash('sandbox-only-password',PASSWORD_DEFAULT),'customer','active'
]);
$buyerId=(int)$pdo->lastInsertId();
$mockCheckout=function(array $params,string $idempotencyKey):array {
 if($params['mode']!=='payment'||$params['line_items'][0]['price_data']['unit_amount']!==7999||$idempotencyKey==='')throw new RuntimeException('Wrong Stripe request');
 return ['id'=>'cs_test_123456789ABC','url'=>'https://checkout.stripe.com/c/pay/cs_test_123456789ABC'];
};
$url=start_homeserver_checkout($pdo,$buyerId,$id,$mockCheckout,'sk_test_example123456789','https://example.com');
assert_test(stripe_checkout_url($url),'Sandbox Checkout session prepared');
$secondUrl=start_homeserver_checkout($pdo,$buyerId,$id,$mockCheckout,'sk_test_example123456789','https://example.com');
assert_test($url===$secondUrl,'Repeated Checkout request reuses same session');
$q=$pdo->prepare('SELECT order_id,session_id FROM stripe_checkout_attempts WHERE user_id=?');$q->execute([$buyerId]);$attempt=$q->fetch();
$orderId=(int)$attempt['order_id'];
assert_test($attempt['session_id']==='cs_test_123456789ABC','Stripe session ID persisted');
$paidSession=['id'=>$attempt['session_id'],'livemode'=>false,'mode'=>'payment','payment_status'=>'paid','currency'=>'usd',
 'amount_total'=>7999,'client_reference_id'=>(string)$orderId,
 'metadata'=>['order_id'=>(string)$orderId,'user_id'=>(string)$buyerId]];
$paidEvent=['id'=>'evt_CHECKOUT12345678','type'=>'checkout.session.completed','object_id'=>$attempt['session_id'],'livemode'=>false];
stripe_record_event($pdo,$paidEvent);
assert_test(!apply_paid_homeserver_session($pdo,$paidEvent,array_merge($paidSession,['amount_total'=>1])),'Amount mismatch cannot grant ownership');
assert_test(!apply_paid_homeserver_session($pdo,$paidEvent,array_merge($paidSession,['livemode'=>true])),'Live-mode payment cannot use test-only fulfillment');
assert_test((int)$pdo->query('SELECT COUNT(*) FROM entitlements')->fetchColumn()===0,'Failed payment validation grants no entitlement');
assert_test(apply_paid_homeserver_session($pdo,$paidEvent,$paidSession),'Verified payment grants ownership');
assert_test(apply_paid_homeserver_session($pdo,$paidEvent,$paidSession),'Duplicate paid event is safe');
assert_test((int)$pdo->query('SELECT COUNT(*) FROM entitlements')->fetchColumn()===1,'Exactly one HomeServer entitlement granted');
$pdo->prepare("UPDATE orders SET status='refunded' WHERE id=?")->execute([$orderId]);
assert_test(!apply_paid_homeserver_session($pdo,$paidEvent,$paidSession),'Late webhook cannot regrant refunded order');
try{start_homeserver_checkout($pdo,$buyerId,$id,$mockCheckout,'sk_test_example123456789','https://example.com');throw new RuntimeException('Repurchase accepted');}
catch(DomainException $e){assert_test(true,'Already owned HomeServer cannot be purchased twice');}
$pdo->prepare('DELETE FROM entitlements WHERE user_id=?')->execute([$buyerId]);
$pdo->prepare('DELETE FROM stripe_checkout_attempts WHERE user_id=?')->execute([$buyerId]);
$pdo->prepare('DELETE FROM orders WHERE user_id=?')->execute([$buyerId]);
$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$buyerId]);
assert_test((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===0,'Checkout tests leave installer database with no users');


// C3: monthly/annual hosting subscriptions are separate from permanent software licenses.
assert_test(hosting_schema_ready($pdo),'Fresh install has hosting subscription and invoice schemas');
upgrade_hosting_schema($pdo);upgrade_hosting_schema($pdo);
$hostingPlan=[
 'sku'=>'hosting-monthly-test','name'=>'Private Hosted Monthly','description'=>'Hosted private game domain',
 'delivery_type'=>'hosting_addon','billing_type'=>'subscription','billing_interval'=>'monthly',
 'price'=>'12.00','features'=>'Private subdomain'
];
$hostingId=create_package($pdo,$hostingPlan);set_package_active($pdo,$hostingId,true);
$annualPlan=array_merge($hostingPlan,['sku'=>'hosting-annual-test','name'=>'Private Hosted Annual','billing_interval'=>'annual','price'=>'120.00']);
$annualId=create_package($pdo,$annualPlan);set_package_active($pdo,$annualId,true);
$pdo->prepare("INSERT INTO users(email,display_name,password_hash,role,status) VALUES (?,?,?,?,?)")
 ->execute(['hosting-test@example.test','Hosting Buyer',password_hash('hosting-test-password',PASSWORD_DEFAULT),'customer','active']);
$hostingUser=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users(email,display_name,password_hash,role,status) VALUES (?,?,?,?,?)")
 ->execute(['hosting-other@example.test','Other User',password_hash('hosting-other-password',PASSWORD_DEFAULT),'customer','active']);
$hostingOther=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO installation_requests(user_id,project_name,slug,kind,status) VALUES (?,?,?,?,?)")
 ->execute([$hostingUser,'Hosting Night','hosting-night','hosted_game','pending']);
$hostingRequest=(int)$pdo->lastInsertId();
$mockSubscription=function(array $params,string $key):array {
 if($params['mode']!=='subscription' || $params['line_items'][0]['price_data']['recurring']['interval']!=='month'
  || $params['line_items'][0]['price_data']['unit_amount']!==1200 || $key==='')throw new RuntimeException('Wrong subscription request');
 return ['id'=>'cs_test_SUBS12345678','url'=>'https://checkout.stripe.com/c/pay/cs_test_SUBS12345678'];
};
try{start_hosting_subscription($pdo,$hostingUser,$hostingRequest,$hostingId,$mockSubscription,'sk_test_1234567890','https://example.com');throw new RuntimeException('Pending request billed');}
catch(DomainException $e){assert_test(true,'Pending installation cannot start a paid hosting subscription');}
$pdo->prepare("UPDATE installation_requests SET status='reviewed' WHERE id=?")->execute([$hostingRequest]);
try{start_hosting_subscription($pdo,$hostingOther,$hostingRequest,$hostingId,$mockSubscription,'sk_test_1234567890','https://example.com');throw new RuntimeException('Cross-account installation billed');}
catch(DomainException $e){assert_test(true,'Cross-account hosting Checkout blocked');}
$url=start_hosting_subscription($pdo,$hostingUser,$hostingRequest,$hostingId,$mockSubscription,'sk_test_1234567890','https://example.com');
assert_test(stripe_checkout_url($url),'Monthly hosting Stripe test Checkout created');
assert_test($url===start_hosting_subscription($pdo,$hostingUser,$hostingRequest,$hostingId,$mockSubscription,'sk_test_1234567890','https://example.com'),'Repeated hosting checkout reuses session');
try{start_hosting_subscription($pdo,$hostingUser,$hostingRequest,$annualId,$mockSubscription,'sk_test_1234567890','https://example.com');throw new RuntimeException('Duplicate plan billed');}
catch(DomainException $e){assert_test(true,'One subdomain cannot start two competing hosting plans');}
$q=$pdo->prepare('SELECT * FROM hosting_subscriptions WHERE installation_request_id=?');$q->execute([$hostingRequest]);$hosting=$q->fetch();
$order=(int)$hosting['order_id'];
$paidHosting=['id'=>'cs_test_SUBS12345678','livemode'=>false,'mode'=>'subscription','payment_status'=>'paid','currency'=>'usd',
 'amount_total'=>1200,'client_reference_id'=>(string)$order,'subscription'=>'sub_1234567890','customer'=>'cus_1234567890',
 'metadata'=>['order_id'=>(string)$order,'user_id'=>(string)$hostingUser,'installation_request_id'=>(string)$hostingRequest]];
$hostingEvent=['id'=>'evt_HOSTING12345678','type'=>'checkout.session.completed','object_id'=>$paidHosting['id'],'livemode'=>false];
stripe_record_event($pdo,$hostingEvent);
assert_test(!apply_paid_hosting_checkout($pdo,$hostingEvent,array_merge($paidHosting,['amount_total'=>1199])),'Mismatched hosting amount cannot activate');
assert_test(!apply_paid_hosting_checkout($pdo,$hostingEvent,array_merge($paidHosting,['metadata'=>['user_id'=>(string)$hostingOther]])),'Forged subscription metadata cannot activate');
assert_test(apply_paid_hosting_checkout($pdo,$hostingEvent,$paidHosting),'Paid signed hosting checkout activates');
assert_test(apply_paid_hosting_checkout($pdo,$hostingEvent,$paidHosting),'Duplicate hosting checkout is idempotent');
assert_test((int)$pdo->query('SELECT COUNT(*) FROM entitlements')->fetchColumn()===0,'Hosting subscriptions grant no permanent HomeServer entitlements');

$scheduledEvent=['id'=>'evt_CANCELPEND12345','type'=>'customer.subscription.updated','object_id'=>'sub_1234567890','livemode'=>false];
$scheduledObject=['id'=>'sub_1234567890','livemode'=>false,'customer'=>'cus_1234567890','status'=>'active','cancel_at_period_end'=>true];
stripe_record_event($pdo,$scheduledEvent);
assert_test(apply_hosting_lifecycle_event($pdo,$scheduledEvent,$scheduledObject,100),'Scheduled cancellation recorded without premature suspension');
assert_test((int)hosting_subscription_rows($pdo,$hostingUser)[0]['cancel_at_period_end']===1,'Hosting scheduled cancellation shown to customer');
assert_test(apply_paid_hosting_checkout($pdo,$hostingEvent,$paidHosting),'Old Checkout duplicate acknowledged after scheduling cancellation');
assert_test((int)hosting_subscription_rows($pdo,$hostingUser)[0]['cancel_at_period_end']===1,'Old Checkout cannot clear scheduled cancellation');

$portalResult=start_hosting_billing_portal($pdo,$hostingUser,(int)$hosting['id'],
 fn(array $params)=>['url'=>'https://billing.stripe.com/p/session/test_1234567890'],
 'sk_test_1234567890','https://example.com');
assert_test(stripe_portal_url($portalResult),'Owner can manage own paid subscription through Stripe Billing Portal');
try{start_hosting_billing_portal($pdo,$hostingOther,(int)$hosting['id'],null,'sk_test_1234567890','https://example.com');throw new RuntimeException('Cross-account portal accepted');}
catch(DomainException $e){assert_test(true,'Cross-account Stripe Billing Portal rejected');}
$invoice=['id'=>'in_1234567890','livemode'=>false,'paid'=>true,'status'=>'paid','currency'=>'usd','amount_paid'=>1200,
 'subscription'=>'sub_1234567890','customer'=>'cus_1234567890'];
$renewEvent=['id'=>'evt_RENEWAL12345678','type'=>'invoice.paid','object_id'=>'in_1234567890','livemode'=>false];
stripe_record_event($pdo,$renewEvent);
assert_test(!apply_hosting_lifecycle_event($pdo,$renewEvent,array_merge($invoice,['customer'=>'cus_OTHER12345678']),101),'Foreign customer invoice rejected');
assert_test(!apply_hosting_lifecycle_event($pdo,$renewEvent,array_merge($invoice,['amount_paid'=>1]),101),'Underpaid or changed subscription amount cannot renew hosting');
assert_test(apply_hosting_lifecycle_event($pdo,$renewEvent,$invoice,101),'Verified recurring invoice recorded');
assert_test(apply_hosting_lifecycle_event($pdo,$renewEvent,$invoice,101),'Duplicate invoice does not duplicate renewal');
assert_test((int)$pdo->query('SELECT COUNT(*) FROM hosting_subscription_invoices')->fetchColumn()===1,'Renewal invoice deduplicated');
$fail=['id'=>'in_FAIL12345678','livemode'=>false,'paid'=>false,'subscription'=>'sub_1234567890','customer'=>'cus_1234567890'];
$failedEvent=['id'=>'evt_FAILED12345678','type'=>'invoice.payment_failed','object_id'=>$fail['id'],'livemode'=>false];
stripe_record_event($pdo,$failedEvent);
assert_test(apply_hosting_lifecycle_event($pdo,$failedEvent,$fail,102),'Failed renewal suspends hosted subscription');
assert_test(hosting_subscription_rows($pdo,$hostingUser)[0]['state']==='past_due','Hosting billing suspended on failure');
assert_test(apply_paid_hosting_checkout($pdo,$hostingEvent,$paidHosting),'Duplicate old Checkout acknowledged after failure');
assert_test(hosting_subscription_rows($pdo,$hostingUser)[0]['state']==='past_due','Duplicate Checkout cannot undo past-due state');
assert_test(apply_hosting_lifecycle_event($pdo,$renewEvent,$invoice,101),'Out-of-order paid invoice ignored safely');
assert_test(hosting_subscription_rows($pdo,$hostingUser)[0]['state']==='past_due','Older invoice cannot reactivate after failure');
$renewEvent2=['id'=>'evt_RENEWAL22345678','type'=>'invoice.paid','object_id'=>'in_RENEW12345678','livemode'=>false];
$invoice2=array_merge($invoice,['id'=>'in_RENEW12345678']);
stripe_record_event($pdo,$renewEvent2);
assert_test(apply_hosting_lifecycle_event($pdo,$renewEvent2,$invoice2,103),'New verified renewal restores hosting');
$cancelEvent=['id'=>'evt_CANCEL12345678','type'=>'customer.subscription.deleted','object_id'=>'sub_1234567890','livemode'=>false];
$cancelObject=['id'=>'sub_1234567890','livemode'=>false,'customer'=>'cus_1234567890'];
stripe_record_event($pdo,$cancelEvent);
assert_test(apply_hosting_lifecycle_event($pdo,$cancelEvent,$cancelObject,104),'Cancellation removes hosting authorization');
assert_test(hosting_subscription_rows($pdo,$hostingUser)[0]['state']==='canceled','Canceled hosting remains canceled');
assert_test(apply_paid_hosting_checkout($pdo,$hostingEvent,$paidHosting),'Duplicate Checkout acknowledged after cancellation');
assert_test(hosting_subscription_rows($pdo,$hostingUser)[0]['state']==='canceled','Duplicate Checkout cannot re-enable canceled hosting');
assert_test(apply_hosting_lifecycle_event($pdo,$renewEvent2,$invoice2,105),'Late renewal cannot re-enable canceled subscription');
assert_test(hosting_subscription_rows($pdo,$hostingUser)[0]['state']==='canceled','Canceled hosting not resurrected by late invoice');
$secondMock=function(array $params,string $key):array{if($params['line_items'][0]['price_data']['recurring']['interval']!=='year' || $params['line_items'][0]['price_data']['unit_amount']!==12000)throw new RuntimeException('Wrong annual interval/price');return [
 'id'=>'cs_test_RESTART12345678','url'=>'https://checkout.stripe.com/c/pay/cs_test_RESTART12345678'];};
assert_test(stripe_checkout_url(start_hosting_subscription($pdo,$hostingUser,$hostingRequest,$annualId,$secondMock,'sk_test_1234567890','https://example.com')),
 'Canceled hosting may subscribe again with annual package');
$q=$pdo->prepare('SELECT billing_interval,recurring_cents,state FROM hosting_subscriptions WHERE installation_request_id=?');
$q->execute([$hostingRequest]);$restarted=$q->fetch();
assert_test($restarted['billing_interval']==='annual' && (int)$restarted['recurring_cents']===12000 && $restarted['state']==='ready',
 'Annual plan has independent 12-month price and checkout');
assert_test(apply_paid_hosting_checkout($pdo,$hostingEvent,$paidHosting),'Historical paid Checkout safely ignored after resubscribe');
assert_test(hosting_subscription_rows($pdo,$hostingUser)[0]['state']==='ready','Old paid Checkout cannot activate replacement annual subscription');
$pdo->exec('DELETE FROM hosting_subscription_invoices');
$pdo->exec('DELETE FROM hosting_subscriptions');
$pdo->prepare('DELETE FROM orders WHERE user_id=?')->execute([$hostingUser]);
$pdo->prepare('DELETE FROM installation_requests WHERE user_id=?')->execute([$hostingUser]);
$pdo->prepare('DELETE FROM users WHERE id IN (?,?)')->execute([$hostingUser,$hostingOther]);
assert_test((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===0,'Subscription integration leaves fresh database installable');
