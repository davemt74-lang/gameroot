<?php
declare(strict_types=1);
require_once __DIR__ . '/core.php';
function page_start(string $title, ?array $user=null, string $active=''): void {
    $flash=flash_take();
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#101323"><meta name="description" content="Lucky Game Hub — private home gaming, connected phones and your own game night.">
<title><?=e($title)?> — Lucky Game Hub</title><link rel="stylesheet" href="/assets/app.css?v=010"></head>
<body><header class="site-header"><a class="brand" href="/index.php" aria-label="Lucky Game Hub home"><span class="brand-mark">✦</span><span>LUCKY<span class="brand-gold">GAME</span><small>HUB</small></span></a>
<nav class="top-nav" aria-label="Primary"><a href="/index.php#features">Features</a><a href="/index.php#how-it-works">How it works</a><a href="/index.php#pricing">Pricing</a></nav>
<div class="top-actions">
<?php if($user): ?><a class="nav-button" href="/account.php">My Account</a><?php if($user['role']==='owner'): ?><a class="nav-button" href="/admin.php">Admin</a><?php endif; ?><form action="/logout.php" method="post" class="inline-form"><?=csrf_input()?><button class="nav-link" type="submit">Log out</button></form>
<?php else: ?><a class="nav-link" href="/login.php">Log in</a><a class="nav-button" href="/register.php">Create account <span aria-hidden="true">↗</span></a><?php endif; ?>
</div></header>
<?php if($flash): ?><div class="flash <?=e($flash[0]==='error'?'flash-error':'flash-success')?>" role="status"><?=e($flash[1])?></div><?php endif;
}
function page_end(): void { ?>
<footer class="site-footer"><div class="footer-top"><a class="brand" href="/index.php"><span class="brand-mark">✦</span><span>LUCKY<span class="brand-gold">GAME</span><small>HUB</small></span></a><span>Made for game nights. Built to be yours.</span></div>
<div class="footer-bottom"><span>© <?=date('Y')?> Lucky Game Hub</span><span>Cloud accounts and purchases are separate from private offline gameplay.</span></div></footer></body></html>
<?php }
function form_error(?string $error): void { if($error): ?><p class="error" role="alert"><?=e($error)?></p><?php endif; }
function empty_notice(string $heading, string $description): void { ?><div class="empty-state"><span aria-hidden="true">✦</span><strong><?=e($heading)?></strong><p><?=e($description)?></p></div><?php }
