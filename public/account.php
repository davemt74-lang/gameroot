<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';require_installed();$user=require_user();$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
 portal_require_https();verify_csrf();
 try {
  if((string)($_POST['action']??'')!=='request_installation')throw new DomainException('Unsupported action.');
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
page_start('My account',$user);
?><main class="dashboard"><div class="dashboard-header"><div><span class="eyebrow">YOUR LUCKY GAME HUB</span><h1>Welcome back, <?=e($user['display_name'])?>.</h1><p>Manage your game spaces, downloads, and account from one place.</p></div><span class="pill">● ACCOUNT ACTIVE</span></div>
<div class="stat-grid"><div class="stat"><span>INSTALLATION REQUESTS</span><strong><?=count($requests)?></strong><small>Awaiting administrator provisioning</small></div><div class="stat"><span>OWNED PRODUCTS</span><strong><?=count($entitlements)?></strong><small>Entitlements live here</small></div><div class="stat"><span>ACCOUNT</span><strong>FREE</strong><small>Purchases will be available later</small></div></div>
<div class="dashboard-grid"><section class="panel"><div class="panel-heading"><div><span class="eyebrow">HOSTED GAME SPACE</span><h2>Request a private installation</h2></div><span class="pill pill-muted">Approval required</span></div><p>Choose a subdomain name for a future private game installation. Requests do not create DNS records or bill your account.</p><?php form_error($error); ?>
<form method="post" class="form-stack"><?=csrf_input()?><input type="hidden" name="action" value="request_installation"><label>Game space name<input name="project_name" required minlength="3" maxlength="100" placeholder="Friday Game Night" value="<?=e($_POST['project_name']??'')?>"></label><label>Preferred subdomain<input name="slug" required minlength="3" maxlength="40" pattern="[a-zA-Z][a-zA-Z0-9-]{1,38}[a-zA-Z0-9]" placeholder="friday-games" value="<?=e($_POST['slug']??'')?>"><small><?= $domain? 'Example: your-name.'.e($domain) : 'The administrator will confirm the domain before provisioning.' ?></small></label><button class="button button-gold" type="submit">Submit request →</button></form></section>
<section class="panel"><div class="panel-heading"><div><span class="eyebrow">MY GAME SPACES</span><h2>Installation requests</h2></div></div>
<?php if(!$requests):empty_notice('Nothing here yet','Submit a request to reserve a private hosted game-space name.');else:foreach($requests as $item):?><div class="request-row"><div><strong><?=e($item['project_name'])?></strong><small><?=e($item['slug'].($domain?'.'.$domain:''))?> · Requested <?=e(substr($item['created_at'],0,10))?></small></div><span class="pill pill-muted"><?=e(ucfirst($item['status']))?></span></div><?php endforeach;endif;?></section></div>
<section class="panel panel-wide"><div class="panel-heading"><div><span class="eyebrow">SOFTWARE LIBRARY</span><h2>Purchases &amp; downloads</h2></div><span class="pill pill-muted">Checkout coming soon</span></div>
<?php if(!$entitlements):empty_notice('Your library is empty','Once products become available, purchased software and licensed downloads will appear here.');else:foreach($entitlements as $item):?><div class="request-row"><div><strong><?=e($item['name'])?></strong><small><?=e($item['sku'])?></small></div><span class="pill">Owned · Download pending</span></div><?php endforeach;endif;?></section></main><?php page_end(); ?>
