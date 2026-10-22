<?php
declare(strict_types=1);
require __DIR__.'/_init.php';
require_once dirname(__DIR__).'/app/subscriptions.php';
require_installed();
$user=require_user();
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit('POST required.');}
portal_require_https();verify_csrf();
try {
 $id=filter_var($_POST['subscription_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 if(!$id)throw new DomainException('Invalid subscription.');
 $url=start_hosting_billing_portal(portal_pdo(),(int)$user['id'],$id);
 if(!stripe_portal_url($url))throw new RuntimeException('Untrusted billing portal URL.');
 header('Location: '.$url,true,303);exit;
} catch(DomainException $e){
 flash('error',$e->getMessage());redirect('/account.php');
} catch(Throwable $e){
 error_log('Stripe Billing Portal error: '.get_class($e));
 flash('error','Billing management is unavailable. Please retry later.');redirect('/account.php');
}
