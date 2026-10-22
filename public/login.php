<?php
declare(strict_types=1);
require __DIR__ . '/_init.php'; require_installed();
if(current_user())redirect('/account.php');
$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    portal_require_https();verify_csrf();
    try { $id=try_login((string)($_POST['email']??''),(string)($_POST['password']??''));authenticate_user($id);redirect('/account.php'); }
    catch(DomainException $e){$error=$e->getMessage();}
    catch(Throwable $e){error_log('Login problem: '.$e->getMessage());$error='Unable to sign in at this time.';}
}
page_start('Log in');
?><main class="auth-main"><section class="auth-card"><div class="eyebrow">WELCOME BACK</div><h1>Game night awaits.</h1><p>Sign in to manage your installations, account and downloads.</p><?php form_error($error); ?>
<form method="post" class="form-stack"><?=csrf_input()?><label>Email<input required type="email" name="email" autocomplete="email" maxlength="190" value="<?=e($_POST['email']??'')?>"></label><label>Password<input required type="password" name="password" autocomplete="current-password"></label><button class="button button-gold" type="submit">Log in →</button></form>
<p class="form-footer">New here? <a href="/register.php">Create an account</a></p></section></main><?php page_end(); ?>
