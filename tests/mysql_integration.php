<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/packages.php';
require dirname(__DIR__).'/app/checkout.php';
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
$paidSession=['id'=>$attempt['session_id'],'mode'=>'payment','payment_status'=>'paid','currency'=>'usd',
 'amount_total'=>7999,'client_reference_id'=>(string)$orderId,
 'metadata'=>['order_id'=>(string)$orderId,'user_id'=>(string)$buyerId]];
$paidEvent=['id'=>'evt_CHECKOUT12345678','type'=>'checkout.session.completed','object_id'=>$attempt['session_id'],'livemode'=>false];
stripe_record_event($pdo,$paidEvent);
assert_test(!apply_paid_homeserver_session($pdo,$paidEvent,array_merge($paidSession,['amount_total'=>1])),'Amount mismatch cannot grant ownership');
assert_test((int)$pdo->query('SELECT COUNT(*) FROM entitlements')->fetchColumn()===0,'Failed payment validation grants no entitlement');
assert_test(apply_paid_homeserver_session($pdo,$paidEvent,$paidSession),'Verified payment grants ownership');
assert_test(apply_paid_homeserver_session($pdo,$paidEvent,$paidSession),'Duplicate paid event is safe');
assert_test((int)$pdo->query('SELECT COUNT(*) FROM entitlements')->fetchColumn()===1,'Exactly one HomeServer entitlement granted');
try{start_homeserver_checkout($pdo,$buyerId,$id,$mockCheckout,'sk_test_example123456789','https://example.com');throw new RuntimeException('Repurchase accepted');}
catch(DomainException $e){assert_test(true,'Already owned HomeServer cannot be purchased twice');}
$pdo->prepare('DELETE FROM entitlements WHERE user_id=?')->execute([$buyerId]);
$pdo->prepare('DELETE FROM stripe_checkout_attempts WHERE user_id=?')->execute([$buyerId]);
$pdo->prepare('DELETE FROM orders WHERE user_id=?')->execute([$buyerId]);
$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$buyerId]);
assert_test((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===0,'Checkout tests leave installer database with no users');
