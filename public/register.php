<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';require_installed();
if(current_user())redirect('/account.php');
$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    portal_require_https();verify_csrf();
    try {
        registration_rate_limit();
        if((string)($_POST['password']??'')!==(string)($_POST['password_confirm']??''))throw new DomainException('Passwords do not match.');
        $id=register_customer((string)($_POST['name']??''),(string)($_POST['email']??''),(string)($_POST['password']??''));
        authenticate_user($id);flash('success','Account created. Welcome to Lucky Game Hub!');redirect('/account.php');
    }catch(DomainException $e){$error=$e->getMessage();}
    catch(Throwable $e){error_log('Registration error: '.$e->getMessage());$error='Unable to create an account right now.';}
}
page_start('Create account');
?><main class="auth-main"><section class="auth-card"><div class="eyebrow">JOIN THE FUN</div><h1>Get your game on.</h1><p>One account for hosted installation requests and your future purchases and downloads.</p><?php form_error($error); ?>
<form method="post" class="form-stack"><?=csrf_input()?><label>Your name<input required name="name" autocomplete="name" minlength="2" maxlength="100" value="<?=e($_POST['name']??'')?>"></label><label>Email<input type="email" required name="email" autocomplete="email" maxlength="190" value="<?=e($_POST['email']??'')?>"></label><label>Password<input required type="password" name="password" minlength="12" maxlength="200" autocomplete="new-password"><small>Use at least 12 characters.</small></label><label>Confirm password<input required type="password" name="password_confirm" minlength="12" maxlength="200" autocomplete="new-password"></label><button class="button button-gold" type="submit">Create my account →</button></form><p class="form-footer">Already have an account? <a href="/login.php">Log in</a></p></section></main><?php page_end(); ?>
