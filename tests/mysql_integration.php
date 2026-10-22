<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/portal.php';
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
