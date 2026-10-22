<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';require_once dirname(__DIR__).'/app/cpanel.php';require_once dirname(__DIR__).'/app/checkout.php';require_installed();$owner=require_admin();$error=null;
$tab=(string)($_GET['tab']??'overview');if(!in_array($tab,['overview','customers','requests','integrations','products'],true))$tab='overview';
if($_SERVER['REQUEST_METHOD']==='POST'){
 portal_require_https();verify_csrf();
 try {
  $action=(string)($_POST['action']??'');
  if($action==='upgrade_stripe_checkout'){
   upgrade_stripe_checkout_schema(portal_pdo());
   flash('success','Test-mode Stripe Checkout tables are ready. Live payments remain disabled.');redirect('/admin.php?tab=integrations');
  }
  if($action==='upgrade_stripe'){
   upgrade_stripe_schema(portal_pdo());
   flash('success','Stripe event inbox is ready. No charges or subscriptions are enabled.');redirect('/admin.php?tab=integrations');
  }
  if($action==='save_provider'){
   $provider=(string)($_POST['provider']??'');
   save_integration($provider,trim((string)($_POST['credential']??'')),(string)($_POST['cpanel_host']??''),(string)($_POST['cpanel_user']??''),isset($_POST['revoke']));
   flash('success',isset($_POST['revoke'])?'Credential revoked.':'Integration settings saved. Credential values are never returned to the browser.');redirect('/admin.php?tab=integrations');
  }
  if($action==='test_cpanel'){
   $result=cpanel_verify_connection();
   flash($result['ok']?'success':'error',$result['message']);
   redirect('/admin.php?tab=integrations');
  }
  if($action==='save_origin'){
   save_setting('portal_origin',strtolower(trim((string)($_POST['portal_origin']??''))));
   flash('success','Trusted portal HTTPS origin saved.');redirect('/admin.php?tab=integrations');
  }
  if($action==='save_domain'){
   save_setting('tenant_domain',strtolower(trim((string)($_POST['tenant_domain']??''))));
   flash('success','Preferred subdomain base updated. No DNS records were created.');redirect('/admin.php?tab=integrations');
  }
  if($action==='review_request'){
   $id=filter_var($_POST['request_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if(!$id)throw new DomainException('Invalid installation request.');
   $status=(string)($_POST['request_status']??'');
   if(!in_array($status,['pending','reviewed','declined'],true))throw new DomainException('Invalid request status.');
   $q=portal_pdo()->prepare('UPDATE installation_requests SET status=? WHERE id=? AND status IN (?,?,?)');
   $q->execute([$status,$id,'pending','reviewed','declined']);
   flash('success','Request reviewed. No hosting, DNS, or billing operation was executed.');redirect('/admin.php?tab=requests');
  }
  throw new DomainException('Unsupported action.');
 }catch(DomainException $e){$error=$e->getMessage();}
 catch(Throwable $e){error_log('Admin action failed: '.$e->getMessage());$error='Unable to save these settings.';}
}
$pdo=portal_pdo();
$counts=['customers'=>(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn(),
 'requests'=>(int)$pdo->query('SELECT COUNT(*) FROM installation_requests')->fetchColumn(),
 'pending'=>(int)$pdo->query("SELECT COUNT(*) FROM installation_requests WHERE status='pending'")->fetchColumn()];
page_start('Admin control center',$owner);
?><main class="dashboard"><div class="dashboard-header"><div><span class="eyebrow">PLATFORM ADMINISTRATION</span><h1>Control Center</h1><p>Accounts, requests, products, and private server-side integrations.</p></div><span class="pill">✦ OWNER ACCESS</span></div>
<div class="admin-layout"><nav class="admin-menu" aria-label="Admin sections"><a class="<?= $tab==='overview'?'active':'' ?>" href="/admin.php?tab=overview">⌂ <span>Overview</span></a><a class="<?= $tab==='customers'?'active':'' ?>" href="/admin.php?tab=customers">♟ <span>Customers</span></a><a class="<?= $tab==='requests'?'active':'' ?>" href="/admin.php?tab=requests">☁ <span>Installations</span></a><a class="<?= $tab==='products'?'active':'' ?>" href="/admin.php?tab=products">▧ <span>Products &amp; purchases</span></a><a class="<?= $tab==='integrations'?'active':'' ?>" href="/admin.php?tab=integrations">⚙ <span>Integrations / API keys</span></a><a href="/packages.php">▦ <span>Package builder</span></a><a href="/account.php">← <span>My account</span></a></nav>
<div class="admin-main"><?php form_error($error); ?>
<?php if($tab==='overview'): ?><section class="panel"><span class="eyebrow">SYSTEM STATUS</span><h2>Portal at a glance</h2><div class="stat-grid"><div class="stat"><span>CUSTOMERS</span><strong><?=$counts['customers']?></strong></div><div class="stat"><span>INSTALLATION REQUESTS</span><strong><?=$counts['requests']?></strong></div><div class="stat"><span>AWAITING REVIEW</span><strong><?=$counts['pending']?></strong></div></div><div class="notice"><strong>Provisioning and checkout are disabled.</strong><p>The portal currently records customer registrations and installation requests. Connecting a HostGator token does not create accounts, DNS records, or SSL certificates. Payments and HomeServer downloads require a separately certified implementation.</p></div></section>
<?php elseif($tab==='customers'): $rows=$pdo->query('SELECT id,display_name,email,role,created_at FROM users ORDER BY id DESC LIMIT 200')->fetchAll();?><section class="panel"><div class="panel-heading"><h2>Customer accounts</h2><span class="pill pill-muted">Showing <?=count($rows)?> latest</span></div><div class="table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Created</th></tr></thead><tbody><?php foreach($rows as $row):?><tr><td><?=e($row['display_name'])?></td><td><?=e($row['email'])?></td><td><?=e($row['role'])?></td><td><?=e(substr($row['created_at'],0,10))?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php elseif($tab==='requests'): $rows=$pdo->query('SELECT r.id,r.slug,r.project_name,r.status,r.created_at,u.email FROM installation_requests r JOIN users u ON u.id=r.user_id ORDER BY r.id DESC LIMIT 200')->fetchAll();?><section class="panel"><div class="panel-heading"><h2>Hosted installation requests</h2><span class="pill pill-muted"><?=count($rows)?> latest</span></div><p>Review customer requests here. Marking a request reviewed does not provision hosting.</p><div class="table-wrap"><table><thead><tr><th>Project</th><th>Customer</th><th>Status</th><th>Review</th></tr></thead><tbody><?php foreach($rows as $row):?><tr><td><strong><?=e($row['project_name'])?></strong><small><?=e($row['slug'])?></small></td><td><?=e($row['email'])?></td><td><?=e(ucfirst($row['status']))?></td><td><form method="post" class="inline-control"><?=csrf_input()?><input type="hidden" name="action" value="review_request"><input type="hidden" name="request_id" value="<?=e($row['id'])?>"><select name="request_status"><option value="pending" <?=$row['status']==='pending'?'selected':''?>>Pending</option><option value="reviewed" <?=$row['status']==='reviewed'?'selected':''?>>Reviewed</option><option value="declined" <?=$row['status']==='declined'?'selected':''?>>Declined</option></select><button class="button button-compact" type="submit">Save</button></form></td></tr><?php endforeach;?></tbody></table></div></section>
<?php elseif($tab==='products'):?><section class="panel"><span class="eyebrow">COMING IN NEXT RELEASES</span><h2>Purchases &amp; downloads</h2><p>The account/entitlement tables are ready for verified purchases, product catalogs, licenses, and release downloads. Checkout is deliberately disabled until a payment provider, verified webhooks, refunds, and entitlement issuance have been tested.</p><div class="notice"><strong>No live payments or product fulfillment yet.</strong><p>Customers cannot accidentally purchase, download, or gain a license from a pending order.</p></div></section>
<?php else: $statuses=integration_statuses();$domain=get_setting('tenant_domain'); ?><section class="panel"><span class="eyebrow">OWNER-ONLY · SERVER-SIDE</span><h2>Integrations &amp; API keys</h2><p>Save tokens here rather than in source files or browser storage. Existing values are never displayed. Clearing an input leaves a stored key unchanged; choose Revoke to delete it.</p><div class="notice"><strong>Credentials are stored encrypted.</strong><p>Use Test connection to verify your saved cPanel token through a read-only UAPI call. Subdomain provisioning, AI API calls, and automatic installations remain disabled.</p></div>
<form method="post" class="integration-domain form-stack"><?=csrf_input()?><input type="hidden" name="action" value="save_domain"><label>Game installation base domain<input name="tenant_domain" maxlength="253" placeholder="games.example.com" value="<?=e($domain)?>"><small>Enter a domain without HTTPS or a path; this is the namespace for future game subdomains.</small></label><button class="button button-outline" type="submit">Save domain</button></form>
<form method="post" class="integration-domain form-stack"><?=csrf_input()?><input type="hidden" name="action" value="save_origin"><label>GameRoot portal HTTPS URL<input name="portal_origin" maxlength="253" placeholder="https://example.com" value="<?=e(get_setting('portal_origin'))?>"><small>Exact root-domain origin for Stripe checkout return links; no path or trailing slash.</small></label><button class="button button-outline" type="submit">Save portal URL</button></form>
<div class="notice"><strong>Stripe webhook setup</strong><p>After saving your Stripe API key and signing secret, register <code>https://YOUR-DOMAIN/stripe_webhook.php</code> as an HTTPS snapshot-event endpoint in Stripe Workbench. This release only stores signed events for future processing; it cannot accept payments or grant licenses.</p></div>
<?php if(!stripe_schema_ready($pdo)):?><form method="post" class="form-stack"><?=csrf_input()?><input type="hidden" name="action" value="upgrade_stripe"><button class="button button-outline" type="submit">Enable Stripe event storage</button></form><?php endif;?>
<?php if(!stripe_checkout_schema_ready($pdo)):?><form method="post" class="form-stack"><?=csrf_input()?><input type="hidden" name="action" value="upgrade_stripe_checkout"><button class="button button-outline" type="submit">Enable test Checkout storage</button></form><?php endif;?>
<div class="integration-grid"><?php foreach(integration_providers() as $provider=>$definition):$status=$statuses[$provider]??null;$metadata=$status?json_decode($status['metadata_json'],true):[];?><form method="post" class="integration-card form-stack"><?=csrf_input()?><input type="hidden" name="action" value="save_provider"><input type="hidden" name="provider" value="<?=e($provider)?>"><div class="integration-top"><h3><?=e($definition['label'])?></h3><span class="pill <?=$status?'pill-ready':'pill-muted'?>"><?=$status?'● Configured':'Not configured'?></span></div><p><?=e($definition['hint'])?></p>
<?php if($provider==='hostgator'):?><label>cPanel hostname<input name="cpanel_host" autocomplete="off" placeholder="server.example.com" maxlength="253" value="<?=e($metadata['host']??'')?>"></label><label>cPanel username<input name="cpanel_user" autocomplete="off" maxlength="50" placeholder="hostingaccount" value="<?=e($metadata['username']??'')?>"></label><?php endif; ?>
<label>New API credential<input type="password" name="credential" autocomplete="new-password" maxlength="8192" placeholder="<?= $status?'•••••••• (saved, leave blank to keep)':'Paste credential to store' ?>" aria-label="New <?=e($definition['label'])?> API credential"></label><div class="integration-actions"><button class="button button-compact" type="submit">Save / replace key</button><?php if($status):?><label class="revoke"><input type="checkbox" name="revoke" value="1"> Revoke saved key</label><?php endif;?></div><?php if($provider==='hostgator'&&$status):?><div class="integration-actions"><button class="button button-outline button-compact" type="submit" name="action" value="test_cpanel" formnovalidate>Test cPanel connection</button><small>Read-only authentication test · No hosting changes</small></div><?php endif;?></form><?php endforeach;?></div></section>
<?php endif;?></div></div></main><?php page_end(); ?>
