<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once dirname(__DIR__).'/app/packages.php';
require_installed();
$user=current_user();$pdo=portal_pdo();$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
 portal_require_https();
 $user=require_user();
 verify_csrf();
 try {
  if((string)($_POST['action']??'')!=='select_package')throw new DomainException('Unsupported action.');
  $id=filter_var($_POST['package_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if(!$id)throw new DomainException('Invalid package.');
  select_customer_package($pdo,(int)$user['id'],$id);
  flash('success','Package selected. Checkout is not yet available; no charge or license was issued.');
  redirect('/account.php');
 } catch(DomainException $e){$error=$e->getMessage();}
 catch(Throwable $e){error_log('Package selection failed: '.$e->getMessage());$error='Unable to select that package.';}
}
$ready=package_schema_ready($pdo);
$packages=$ready?list_packages($pdo,true):[];
page_start('GameRoot packages',$user);
?>
<main class="dashboard">
<div class="dashboard-header"><div><span class="eyebrow">CHOOSE YOUR GAME NIGHT</span><h1>Packages</h1><p>Own a local HomeServer, add optional hosting, or choose a managed Cloud installation.</p></div><a class="button button-outline" href="<?=$user?'/account.php':'/index.php'?>">← Back</a></div>
<?php form_error($error); ?>
<div class="notice"><strong>Package selection is available; checkout is coming next.</strong><p>Selecting a package adds it to your account for future checkout. No payment, recurring charge, download license or hosting installation is created now.</p></div>
<?php if(!$ready):empty_notice('Package catalog upgrade pending','An administrator must enable the package catalog before plans are available.');?>
<?php elseif(!$packages):empty_notice('Packages coming soon','The owner has not activated any packages yet.');?>
<?php else:?>
<div class="plan-grid">
<?php foreach($packages as $item):
$features=json_decode((string)$item['features_json'],true);
if(!is_array($features))$features=[];
$cadence=$item['billing_type']==='subscription'?($item['billing_interval']==='annual'?'/year':'/month'):'';
$kind=$item['delivery_type']==='homeserver'?'Local HomeServer':($item['delivery_type']==='hosting_addon'?'Optional Subdomain Hosting':'Cloud Game Hosting');
?>
<article class="plan-card">
<span class="eyebrow"><?=e($kind)?></span><h3><?=e($item['name'])?></h3>
<p><?=e($item['description'])?></p>
<h2>$<?=e(number_format((int)$item['price_cents']/100,2))?><small><?=e($cadence)?></small></h2>
<span class="plan-status"><?=e($item['billing_type']==='one_time'?'One-time purchase — own your software':($item['billing_type']==='free'?'Free package':'Recurring subscription'))?></span>
<?php if($features):?><ul><?php foreach($features as $feature):?><li><?=e((string)$feature)?></li><?php endforeach;?></ul><?php endif;?>
<?php if($user):?>
<form method="post"><?=csrf_input()?><input type="hidden" name="action" value="select_package"><input type="hidden" name="package_id" value="<?=e($item['id'])?>"><button class="button button-gold" type="submit">Select package →</button></form>
<?php else:?>
<a class="button button-gold" href="/register.php">Create account to select →</a>
<?php endif;?>
</article>
<?php endforeach;?></div>
<?php endif;?></main><?php page_end();?>
