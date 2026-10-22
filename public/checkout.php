<?php
declare(strict_types=1);
require __DIR__.'/_init.php';
require_once dirname(__DIR__).'/app/checkout.php';
require_installed();
$user=require_user();
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit('POST required.');}
portal_require_https();verify_csrf();
try {
 $id=filter_var($_POST['package_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 if(!$id)throw new DomainException('Invalid package.');
 $url=start_homeserver_checkout(portal_pdo(),(int)$user['id'],$id);
 if(!stripe_checkout_url($url))throw new RuntimeException('Untrusted Stripe redirect.');
 header('Location: '.$url,true,303);exit;
} catch(DomainException $e){
 flash('error',$e->getMessage());redirect('/account.php');
} catch(Throwable $e){
 error_log('Stripe Checkout error: '.get_class($e));
 flash('error','Checkout is currently unavailable. Please try again.');redirect('/account.php');
}
