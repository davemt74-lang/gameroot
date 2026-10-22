<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
$user=current_user();
page_start('Your game night starts here',$user);
?>
<main>
<section class="hero"><div class="hero-glow"></div><div class="hero-inner"><div class="hero-copy"><div class="eyebrow"><span class="tiny-star">✦</span> YOUR GAME NIGHT. YOUR RULES.</div>
<h1>Turn any night into <em>game night.</em></h1>
<p class="hero-subtitle">Big-screen games. Phones as controllers. Real prizes, camera memories, and a highlight reel worth keeping. All in your own private space.</p>
<div class="hero-buttons"><a class="button button-gold" href="<?= $user ? '/account.php' : '/register.php' ?>"><?= $user?'Open my dashboard':'Get started free' ?> <span aria-hidden="true">↗</span></a><a class="button button-outline" href="#how-it-works"><span aria-hidden="true">▶</span> See how it works</a></div>
<div class="hero-trust"><span><b>✓</b> Private game sessions</span><span><b>✓</b> Phone controllers</span><span><b>✓</b> Your media stays yours</span></div>
</div>
<div class="hero-art" aria-hidden="true"><div class="room-glow"></div><div class="tv-frame"><div class="tv-top"><span>✦ LUCKY GAME HUB</span><span class="live-dot">● LIVE GAME</span></div><div class="tv-wheel"><i class="wheel-center">SPIN<br><small>TO WIN</small></i></div><div class="tv-bottom"><span>GAME NIGHT #01</span><b>PRIZES AWAIT!</b></div></div><div class="phone-preview"><div class="phone-preview-status">● CONNECTED</div><span class="mini-heading">YOUR CONTROLLER</span><div class="mini-prize">🎁</div><strong>YOU WON!</strong><small>Game Night Prize</small><div class="mini-action">ACCEPT PRIZE ✓</div></div><div class="orbit-star">✦</div></div></div>
<div class="hero-foot">ONE SYSTEM. ENDLESS GAME NIGHTS. <span aria-hidden="true">↓</span></div></section>
<section class="section" id="features"><div class="section-heading"><span class="eyebrow">BUILT FOR THE FUN OF IT</span><h2>Everything your game night needs.</h2><p>One game platform, connected across the room—without public lobbies or complicated setup.</p></div>
<div class="feature-grid"><article class="feature-card"><span class="feature-icon">🎮</span><h3>Games on the big screen</h3><p>Run a growing collection of living-room games from your computer or private hosted installation.</p></article>
<article class="feature-card"><span class="feature-icon">📱</span><h3>Phones are the controllers</h3><p>Everyone joins from their own phone—no extra controllers or downloads required.</p></article>
<article class="feature-card"><span class="feature-icon">🎁</span><h3>Make prizes part of the fun</h3><p>Set prize packages, collect claims, and capture memorable proof moments.</p></article>
<article class="feature-card"><span class="feature-icon">🎬</span><h3>Keep the highlight reel</h3><p>Record game-night moments and assemble a movie in the integrated video editor.</p></article>
<article class="feature-card"><span class="feature-icon">🔒</span><h3>Private by design</h3><p>Your game sessions are not public. HomeServer mode can operate without a cloud connection.</p></article>
<article class="feature-card"><span class="feature-icon">✦</span><h3>Make it your own</h3><p>Customize game splash screens, music, cameras, and themes for your group.</p></article></div></section>
<section class="section how" id="how-it-works"><div class="section-heading"><span class="eyebrow">THREE SIMPLE STEPS</span><h2>Bring the room together.</h2></div><div class="steps"><div><span class="step-num">01</span><h3>Set up your game</h3><p>Choose a private hosted installation or plan for a HomeServer setup.</p></div><div><span class="step-num">02</span><h3>Connect the room</h3><p>Open the game on your TV. Players join using their phones.</p></div><div><span class="step-num">03</span><h3>Play, capture, celebrate</h3><p>Play games, award prizes, and save your favorite moments.</p></div></div></section>
<section class="section plans" id="pricing"><div class="section-heading"><span class="eyebrow">PICK YOUR WAY TO PLAY</span><h2>One platform. Your choice.</h2><p>Create an account to track your installations and future purchases. Paid checkout is not yet enabled.</p></div><div class="plan-grid"><article class="plan-card"><div class="plan-icon">☁</div><span class="eyebrow">CLOUD GAME HOSTING</span><h3>Hosted game space</h3><p>Request a private game subdomain for your group. Each installation will have isolated data.</p><span class="plan-status">Registration available · Provisioning pending</span><a class="button button-outline" href="/plans.php">View hosting packages ↗</a></article><article class="plan-card featured"><div class="plan-icon">⌂</div><span class="eyebrow">PLAY FROM YOUR OWN PC</span><h3>HomeServer</h3><p>One Windows installation, a private LAN game room, and no mandatory connection once installed.</p><span class="plan-status">Download and purchase setup coming soon</span><a class="button button-gold" href="/plans.php">View HomeServer packages ↗</a></article></div></section>
<section class="bottom-cta"><div><span class="eyebrow">YOUR NEXT GAME NIGHT STARTS HERE</span><h2>Make some memories.</h2><p>Create your account now to manage private game installation requests and future downloads.</p></div><a class="button button-gold" href="<?= $user?'/account.php':'/register.php' ?>">Get started <span aria-hidden="true">↗</span></a></section>
</main>
<?php page_end(); ?>
