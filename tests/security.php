<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/portal.php';
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
