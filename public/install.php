<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
if(installed())redirect('/index.php');
$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
 portal_require_https(); verify_csrf();
 $lockFile=dirname(__DIR__).'/var/.install.lock';
 $lock=fopen($lockFile,'c');
 if($lock===false)throw new RuntimeException('Installer storage is not writable.');
 try{
  if(!flock($lock,LOCK_EX))throw new RuntimeException('Installer lock failed.');
  if(installed())redirect('/index.php');
  $dbHost=trim((string)($_POST['db_host']??'localhost'));
  $dbPort=(int)($_POST['db_port']??3306);
  $dbName=trim((string)($_POST['db_name']??''));$dbUser=trim((string)($_POST['db_user']??''));$dbPass=(string)($_POST['db_password']??'');
  $email=normalize_email((string)($_POST['email']??''));$name=trim((string)($_POST['owner_name']??''));$password=(string)($_POST['owner_password']??'');
  if(!preg_match('/^[a-zA-Z0-9.-]{1,253}$/D',$dbHost)||$dbPort<1||$dbPort>65535||!preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$dbName)||!preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$dbUser))throw new DomainException('Check database host, name and username.');
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190||strlen($name)<2||strlen($name)>100||strlen($password)<12||strlen($password)>200)throw new DomainException('Enter a valid owner name, email and password of at least 12 characters.');
  if($password!==(string)($_POST['owner_confirm']??''))throw new DomainException('Owner passwords do not match.');
  $config=['db'=>['host'=>$dbHost,'port'=>$dbPort,'name'=>$dbName,'user'=>$dbUser,'pass'=>$dbPass],'secret_key'=>base64_encode(random_bytes(32))];
  $pdo=portal_pdo($config);install_schema($pdo);
  $q=$pdo->query('SELECT COUNT(*) FROM users');
  if((int)$q->fetchColumn()>0)throw new DomainException('This database already contains accounts. Use an empty portal database.');
  // Prepare the config before any owner row is committed to avoid stranding a DB on unwritable hosts.
  $filename=dirname(__DIR__).'/var/config.php';
  $body="<?php\ndeclare(strict_types=1);\nreturn ".var_export($config,true).";\n";
  $tmp=tempnam(dirname(__DIR__).'/var','.cfg-');
  if(!$tmp||file_put_contents($tmp,$body,LOCK_EX)===false)throw new RuntimeException('Could not write configuration.');
  chmod($tmp,0600);
  $pdo->beginTransaction();
  try {
   $stmt=$pdo->prepare('INSERT INTO users (email,display_name,password_hash,role,status) VALUES (?,?,?,?,?)');
   $stmt->execute([$email,$name,password_hash($password,PASSWORD_DEFAULT),'owner','active']);
   $ownerId=(int)$pdo->lastInsertId();
   // Keep ownership setup and on-disk config consistent as far as the filesystem/DB allow.
   if(!rename($tmp,$filename))throw new RuntimeException('Could not finish configuration.');
   try {$pdo->commit();} catch(Throwable $ex){@unlink($filename);throw $ex;}
  } catch(Throwable $ex) {
   if($pdo->inTransaction())$pdo->rollBack();
   @unlink($tmp);
   if(is_file($filename))@unlink($filename);
   throw $ex;
  }
  authenticate_user($ownerId);flash('success','Portal installed. Your owner account is ready.');redirect('/admin.php');
 }catch(DomainException $e){$error=$e->getMessage();}
 catch(Throwable $e){error_log('Installer failed: '.$e->getMessage());$error='Installation failed. Confirm database access and server PHP extensions, then retry.';}
 finally{flock($lock,LOCK_UN);fclose($lock);}
}
page_start('First-time setup');
?><main class="auth-main"><section class="auth-card wide"><span class="eyebrow">ONE-TIME INSTALLER</span><h1>Set up GameRoot.</h1><p>Use a new, empty MySQL/MariaDB database created in your hosting control panel. Your first account becomes the owner. No API keys are needed for installation.</p>
<div class="notice"><strong>Requirements</strong><p>PHP 8.1+, PDO MySQL, sodium, OpenSSL, writable <code>var/</code>, and HTTPS. The domain's document root must point at the <code>public/</code> directory.</p></div><?php form_error($error); ?>
<form method="post" class="form-stack"><?=csrf_input()?><h3>Database</h3><div class="form-two"><label>Database host<input name="db_host" required value="<?=e($_POST['db_host']??'localhost')?>" maxlength="253"></label><label>Database port<input type="number" name="db_port" required min="1" max="65535" value="<?=e($_POST['db_port']??3306)?>"></label></div><div class="form-two"><label>Database name<input name="db_name" required maxlength="64" value="<?=e($_POST['db_name']??'')?>"></label><label>Database username<input name="db_user" required maxlength="64" value="<?=e($_POST['db_user']??'')?>"></label></div><label>Database password<input type="password" name="db_password" autocomplete="new-password"></label><h3>First owner account</h3><label>Name<input name="owner_name" required minlength="2" maxlength="100" autocomplete="name" value="<?=e($_POST['owner_name']??'')?>"></label><label>Email<input type="email" name="email" required autocomplete="email" maxlength="190" value="<?=e($_POST['email']??'')?>"></label><div class="form-two"><label>Owner password<input type="password" name="owner_password" required minlength="12" maxlength="200" autocomplete="new-password"></label><label>Confirm password<input type="password" name="owner_confirm" required minlength="12" maxlength="200" autocomplete="new-password"></label></div><button class="button button-gold" type="submit">Create portal &amp; owner account →</button></form></section></main><?php page_end(); ?>
