<?php
declare(strict_types=1);
require __DIR__.'/_init.php';
require_once dirname(__DIR__).'/app/subscriptions.php';
require_installed();
$user=require_user();
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit('POST required.');}
portal_require_https();verify_csrf();
try {
 $requestId=filter_var($_POST['request_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 $packageId=filter_var($_POST['package_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 if(!$requestId||!$packageId)throw new DomainException('Choose a reviewed installation and hosting plan.');
 $url=start_hosting_subscription(portal_pdo(),(int)$user['id'],$requestId,$packageId);
 if(!stripe_checkout_url($url))throw new RuntimeException('Untrusted Stripe checkout URL.');
 header('Location: '.$url,true,303);exit;
} catch(DomainException $e){
 flash('error',$e->getMessage());redirect('/account.php');
} catch(Throwable $e){
 error_log('Hosting Checkout error: '.get_class($e));
 flash('error','Subscription Checkout is currently unavailable.');redirect('/account.php');
}
