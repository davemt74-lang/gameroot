<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';require_once dirname(__DIR__).'/app/subscriptions.php';require_installed();$user=require_user();$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
 portal_require_https();verify_csrf();
 try {
  $action=(string)($_POST['action']??'');
  if($action==='remove_package_selection'){
   $id=filter_var($_POST['package_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if(!$id)throw new DomainException('Invalid package.');
   remove_customer_package(portal_pdo(),(int)$user['id'],$id);
   flash('success','Package selection removed.');redirect('/account.php');
  }
  if($action!=='request_installation')throw new DomainException('Unsupported action.');
  registration_rate_limit(); // Controls automated provisioning-request spam on public accounts.
  create_installation_request((int)$user['id'],(string)($_POST['project_name']??''),(string)($_POST['slug']??''));
  flash('success','Your installation request has been received. We will review it before any hosting changes are made.');redirect('/account.php');
 }catch(DomainException $e){$error=$e->getMessage();}
 catch(Throwable $e){error_log('Installation request: '.$e->getMessage());$error='Unable to submit your request.';}
}
$pdo=portal_pdo();
$q=$pdo->prepare('SELECT id,project_name,slug,status,created_at FROM installation_requests WHERE user_id=? ORDER BY id DESC LIMIT 100');$q->execute([$user['id']]);$requests=$q->fetchAll();
$q=$pdo->prepare('SELECT p.name,p.sku,e.created_at FROM entitlements e INNER JOIN products p ON p.id=e.product_id WHERE e.user_id=? ORDER BY e.id DESC');$q->execute([$user['id']]);$entitlements=$q->fetchAll();
$domain=get_setting('tenant_domain');
$selectedPackages=customer_package_selections($pdo,(int)$user['id']);
$testCheckoutReady=stripe_checkout_schema_ready($pdo) && str_starts_with((string)stripe_secret('stripe_api'),'sk_test_') && get_setting('portal_origin')!=='';
$hostingCheckoutReady=hosting_checkout_ready($pdo);
$hostingSubscriptions=hosting_subscription_rows($pdo,(int)$user['id']);
$reviewedRequests=array_values(array_filter($requests,static fn(array $r):bool=>$r['status']==='reviewed'));
page_start('My account',$user);
?><main class="dashboard"><div class="dashboard-header"><div><span class="eyebrow">YOUR LUCKY GAME HUB</span><h1>Welcome back, <?=e($user['display_name'])?>.</h1><p>Manage your game spaces, downloads, and account from one place.</p></div><span class="pill">● ACCOUNT ACTIVE</span></div>
<p><a class="button button-gold" href="/plans.php">Browse game packages →</a></p><div class="stat-grid"><div class="stat"><span>INSTALLATION REQUESTS</span><strong><?=count($requests)?></strong><small>Awaiting administrator provisioning</small></div><div class="stat"><span>OWNED PRODUCTS</span><strong><?=count($entitlements)?></strong><small>Entitlements live here</small></div><div class="stat"><span>ACCOUNT</span><strong>ACTIVE</strong><small>Manage purchases and hosting below</small></div></div>
<div class="dashboard-grid"><section class="panel"><div class="panel-heading"><div><span class="eyebrow">HOSTED GAME SPACE</span><h2>Request a private installation</h2></div><span class="pill pill-muted">Approval required</span></div><p>Choose a subdomain name for a future private game installation. Requests do not create DNS records or bill your account.</p><?php form_error($error); ?>
<form method="post" class="form-stack"><?=csrf_input()?><input type="hidden" name="action" value="request_installation"><label>Game space name<input name="project_name" required minlength="3" maxlength="100" placeholder="Friday Game Night" value="<?=e($_POST['project_name']??'')?>"></label><label>Preferred subdomain<input name="slug" required minlength="3" maxlength="40" pattern="[a-zA-Z][a-zA-Z0-9-]{1,38}[a-zA-Z0-9]" placeholder="friday-games" value="<?=e($_POST['slug']??'')?>"><small><?= $domain? 'Example: your-name.'.e($domain) : 'The administrator will confirm the domain before provisioning.' ?></small></label><button class="button button-gold" type="submit">Submit request →</button></form></section>
<section class="panel"><div class="panel-heading"><div><span class="eyebrow">MY GAME SPACES</span><h2>Installation requests</h2></div></div>
<?php if(!$requests):empty_notice('Nothing here yet','Submit a request to reserve a private hosted game-space name.');else:foreach($requests as $item):?><div class="request-row"><div><strong><?=e($item['project_name'])?></strong><small><?=e($item['slug'].($domain?'.'.$domain:''))?> · Requested <?=e(substr($item['created_at'],0,10))?></small></div><span class="pill pill-muted"><?=e(ucfirst($item['status']))?></span></div><?php endforeach;endif;?></section></div>
<section class="panel panel-wide"><div class="panel-heading"><div><span class="eyebrow">MY SELECTED PACKAGES</span><h2>Packages awaiting checkout</h2></div><a class="button button-outline button-compact" href="/plans.php">Browse packages</a></div>
<p>Selecting a package saves your preference. It does not authorize a payment, subscription, or download.</p>
<?php if(!$selectedPackages):empty_notice('No packages selected','Browse available packages to choose HomeServer or hosted plans.');else:foreach($selectedPackages as $item):?>
<div class="request-row"><div><strong><?=e($item['name'])?></strong><small><?=e($item['delivery_type'])?> · <?=e(str_replace('_',' ',$item['billing_type']))?><?= $item['billing_interval']?' / '.e($item['billing_interval']):''?> · $<?=e(number_format((int)$item['price_cents']/100,2))?></small></div><div class="integration-actions"><span class="pill pill-muted"><?=$item['active']?'Selected':'Plan unavailable'?></span><?php if($testCheckoutReady&&$item['active']&&$item['delivery_type']==='homeserver'&&$item['billing_type']==='one_time'&&(int)$item['price_cents']>0):?><form method="post" action="/checkout.php" class="inline-control"><?=csrf_input()?><input type="hidden" name="package_id" value="<?=e($item['id'])?>"><button class="button button-compact button-gold" type="submit">Stripe test checkout</button></form><?php endif;?>
<?php if($hostingCheckoutReady&&$item['active']&&$item['delivery_type']==='hosting_addon'&&$item['billing_type']==='subscription'&&(int)$item['price_cents']>0):?>
<?php if($reviewedRequests):?><form method="post" action="/subscribe.php" class="inline-control"><?=csrf_input()?><input type="hidden" name="package_id" value="<?=e($item['id'])?>"><label>Installation
<select name="request_id" required><?php foreach($reviewedRequests as $request):?><option value="<?=e($request['id'])?>"><?=e($request['slug'])?></option><?php endforeach;?></select></label><button class="button button-compact button-gold" type="submit">Stripe test subscription</button></form>
<?php else:?><span class="pill pill-muted">Requires reviewed installation</span><?php endif;?>
<?php endif;?>
<form method="post" class="inline-control"><?=csrf_input()?><input type="hidden" name="action" value="remove_package_selection"><input type="hidden" name="package_id" value="<?=e($item['id'])?>"><button class="button button-compact button-outline" type="submit">Remove</button></form></div></div>
<?php endforeach;endif;?></section>
<section class="panel panel-wide"><div class="panel-heading"><div><span class="eyebrow">HOSTED BILLING</span><h2>Subdomain &amp; hosting subscriptions</h2></div><span class="pill pill-muted">Test billing · No automatic provisioning</span></div>
<p>Each reviewed subdomain installation can have one monthly or annual hosting subscription. Successful test payment changes the billing status, but hosting, DNS and SSL are still provisioned separately by the administrator. Permanent HomeServer ownership is never affected by hosting cancellation.</p>
<?php if(!$hostingSubscriptions):empty_notice('No hosting subscriptions','Select a hosting plan and have your installation request reviewed before Stripe test checkout.');else:foreach($hostingSubscriptions as $sub):?>
<div class="request-row"><div><strong><?=e($sub['project_name'])?> · <?=e($sub['name'])?></strong><small><?=e($sub['slug'].($domain?'.'.$domain:''))?> · $<?=e(number_format((int)$sub['recurring_cents']/100,2))?> / <?=e($sub['billing_interval'])?></small></div><div class="integration-actions"><span class="pill <?= $sub['state']==='active'?'pill-ready':'pill-muted' ?>"><?=e(ucwords(str_replace('_',' ',$sub['state'])))?><?= $sub['cancel_at_period_end']?' · Cancels at period end':''?></span>
<?php if(in_array($sub['state'],['active','past_due'],true)):?><form method="post" action="/billing_portal.php" class="inline-control"><?=csrf_input()?><input type="hidden" name="subscription_id" value="<?=e($sub['id'])?>"><button type="submit" class="button button-outline button-compact">Manage billing / cancel</button></form><?php endif;?></div></div>
<?php endforeach;endif;?></section>
<section class="panel panel-wide"><div class="panel-heading"><div><span class="eyebrow">SOFTWARE LIBRARY</span><h2>Purchases &amp; downloads</h2></div><span class="pill pill-muted">HomeServer download pending</span></div>
<?php if(!$entitlements):empty_notice('Your library is empty','Once products become available, purchased software and licensed downloads will appear here.');else:foreach($entitlements as $item):?><div class="request-row"><div><strong><?=e($item['name'])?></strong><small><?=e($item['sku'])?></small></div><span class="pill">Owned · Download pending</span></div><?php endforeach;endif;?></section></main><?php page_end(); ?>
