<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once dirname(__DIR__).'/app/packages.php';
require_installed();
$owner=require_admin();$pdo=portal_pdo();$error=null;
$ready=package_schema_ready($pdo);
if($_SERVER['REQUEST_METHOD']==='POST'){
 portal_require_https();verify_csrf();
 try{
  $action=(string)($_POST['action']??'');
  if($action==='upgrade_packages'){
   upgrade_package_schema($pdo);flash('success','Package catalog database is ready. Existing data was preserved.');redirect('/packages.php');
  }
  if(!$ready)throw new DomainException('Upgrade the package catalog first.');
  if($action==='create_package'){
   create_package($pdo,$_POST);flash('success','Package saved as a draft. Activate it when ready.');redirect('/packages.php');
  }
  if($action==='update_package'){
   $id=filter_var($_POST['package_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if(!$id)throw new DomainException('Invalid package.');
   update_package($pdo,$id,$_POST);flash('success','Package updated.');redirect('/packages.php');
  }
  if($action==='toggle_package'){
   $id=filter_var($_POST['package_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if(!$id)throw new DomainException('Invalid package.');
   set_package_active($pdo,$id,(string)($_POST['enabled']??'')==='1');
   flash('success','Package visibility updated.');redirect('/packages.php');
  }
  throw new DomainException('Unsupported package action.');
 }catch(DomainException $e){$error=$e->getMessage();}
 catch(Throwable $e){error_log('Package admin error: '.$e->getMessage());$error='Unable to complete the package change.';}
}
$packages=$ready?list_packages($pdo):[];
$editId=filter_var($_GET['edit']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
$edit=null;
foreach($packages as $candidate)if((int)$candidate['id']===$editId)$edit=$candidate;
$submitted=isset($_POST['sku'])?$_POST:null;
$values=$submitted??($edit?[
 'sku'=>$edit['sku'],'name'=>$edit['name'],'description'=>$edit['description'],
 'price'=>number_format((int)$edit['price_cents']/100,2,'.',''),
 'delivery_type'=>$edit['delivery_type'],'billing_type'=>$edit['billing_type'],
 'billing_interval'=>$edit['billing_interval']??'',
 'features'=>implode("\n",json_decode((string)$edit['features_json'],true)?:[])
]:[]);
page_start('Package builder',$owner);
?>
<main class="dashboard">
<div class="dashboard-header"><div><span class="eyebrow">GAMEROOT ADMIN</span><h1>Packages</h1><p>Create HomeServer purchases and optional recurring hosting plans.</p></div><a class="button button-outline" href="/admin.php?tab=products">← Admin</a></div>
<?php form_error($error); ?>
<?php if(!$ready):?>
<section class="panel"><h2>Package database upgrade</h2><p>The portal was installed before package management was introduced. This safe upgrade creates the package-details table without deleting existing records.</p>
<form method="post"><?=csrf_input()?><input type="hidden" name="action" value="upgrade_packages"><button class="button button-gold" type="submit">Enable package management</button></form></section>
<?php else:?>
<section class="panel"><div class="panel-heading"><div><span class="eyebrow">CATALOG</span><h2>Configured packages</h2></div><span class="pill pill-muted"><?=count($packages)?> total</span></div>
<p>Active packages appear in the storefront. Stripe sandbox Checkout is available for eligible one-time HomeServer and reviewed monthly/annual hosting plans. Live charges, provisioning, and downloads remain disabled.</p>
<?php if(!$packages):empty_notice('No packages yet','Create your first HomeServer or hosting package below.');endif;?>
<div class="table-wrap"><table><thead><tr><th>Package</th><th>Delivery</th><th>Billing</th><th>Price</th><th>Status</th><th>Manage</th></tr></thead><tbody>
<?php foreach($packages as $item):?>
<tr><td><strong><?=e($item['name'])?></strong><small><?=e($item['sku'])?></small></td><td><?=e($item['delivery_type'])?></td>
<td><?=e(str_replace('_',' ',$item['billing_type']))?><?= $item['billing_interval']?' / '.e($item['billing_interval']):'' ?></td>
<td>$<?=e(number_format((int)$item['price_cents']/100,2))?></td>
<td><span class="pill <?=$item['active']?'pill-ready':'pill-muted'?>"><?=$item['active']?'Active':'Draft'?></span></td>
<td><div class="integration-actions"><a class="button button-outline button-compact" href="/packages.php?edit=<?=e($item['id'])?>">Edit</a>
<form method="post" class="inline-control"><?=csrf_input()?><input type="hidden" name="action" value="toggle_package"><input type="hidden" name="package_id" value="<?=e($item['id'])?>"><input type="hidden" name="enabled" value="<?=$item['active']?'0':'1'?>"><button class="button button-compact" type="submit"><?=$item['active']?'Disable':'Activate'?></button></form></div></td></tr>
<?php endforeach;?></tbody></table></div></section>
<section class="panel"><div class="panel-heading"><h2><?=$edit?'Edit package':'Create package'?></h2><?php if($edit):?><a href="/packages.php">Create new package</a><?php endif;?></div>
<form method="post" class="form-stack"><?=csrf_input()?><input type="hidden" name="action" value="<?=$edit?'update_package':'create_package'?>">
<?php if($edit):?><input type="hidden" name="package_id" value="<?=e($edit['id'])?>"><?php endif;?>
<label>Package SKU<input name="sku" required maxlength="80" placeholder="homeserver-standard" value="<?=e($values['sku']??'')?>"><small>Stable, unique catalog identifier</small></label>
<label>Package name<input name="name" required maxlength="150" value="<?=e($values['name']??'')?>"></label>
<label>Description<textarea name="description" required rows="3" maxlength="10000"><?=e($values['description']??'')?></textarea></label>
<div class="grid-two">
<label>Delivery method<select name="delivery_type"><option value="homeserver" <?=($values['delivery_type']??'')==='homeserver'?'selected':''?>>HomeServer download</option><option value="hosting_addon" <?=($values['delivery_type']??'')==='hosting_addon'?'selected':''?>>Optional subdomain hosting</option><option value="cloud" <?=($values['delivery_type']??'')==='cloud'?'selected':''?>>Fully hosted Cloud installation</option></select></label>
<label>Billing model<select name="billing_type"><option value="one_time" <?=($values['billing_type']??'')==='one_time'?'selected':''?>>One-time purchase</option><option value="subscription" <?=($values['billing_type']??'')==='subscription'?'selected':''?>>Recurring subscription</option><option value="free" <?=($values['billing_type']??'')==='free'?'selected':''?>>Free</option></select></label></div>
<div class="grid-two">
<label>Price (USD)<input type="number" name="price" required min="0" max="1000000" step="0.01" value="<?=e($values['price']??'0.00')?>"></label>
<label>Subscription interval<select name="billing_interval"><option value="">Not applicable</option><option value="monthly" <?=($values['billing_interval']??'')==='monthly'?'selected':''?>>Monthly</option><option value="annual" <?=($values['billing_interval']??'')==='annual'?'selected':''?>>Annual</option></select></label></div>
<label>Package features — one per line<textarea name="features" rows="5" maxlength="5000" placeholder="Private local play&#10;Phone controllers&#10;Video editor"><?=e($values['features']??'')?></textarea></label>
<div class="notice"><strong>No charge or fulfillment occurs when a package is activated.</strong><p>Activating a package makes it selectable, but does not create a charge. Eligible Stripe test-mode Checkout requires a separate customer action; live payments, DNS changes, and downloads remain disabled.</p></div>
<button class="button button-gold" type="submit"><?=$edit?'Save package changes':'Create draft package'?></button></form></section>
<?php endif;?></main><?php page_end();?>
