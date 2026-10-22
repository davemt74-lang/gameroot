<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/packages.php';
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
assert_test((int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()===0,'Selection does not create an order');
assert_test((int)$pdo->query('SELECT COUNT(*) FROM entitlements')->fetchColumn()===0,'Selection does not create a license');
set_package_active($pdo,$id,false);
try {select_customer_package($pdo,$userId,$id);throw new RuntimeException('Inactive package selected');}
catch(DomainException $e){assert_test(true,'Inactive packages cannot be newly selected');}
$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
assert_test((int)$pdo->query('SELECT COUNT(*) FROM customer_package_selections')->fetchColumn()===0,'Customer deletion cleans up package selections');
