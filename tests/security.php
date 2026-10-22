<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/cpanel.php';
require dirname(__DIR__).'/app/packages.php';
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
