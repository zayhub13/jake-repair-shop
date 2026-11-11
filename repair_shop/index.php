<?php
require __DIR__ . '/config.php';

$title = 'Home';
$services = require __DIR__ . '/includes/services.php';

require __DIR__ . '/includes/header.php';
?>
<section class="hero">
  <div>
    <h1><?= e(SHOP_TAGLINE) ?></h1>
    <p>Send us the details of your phone problem, get a reference number right away, and follow every step of the repair from your own phone.</p>
    <div class="actions"><a class="btn btn-accent" href="submit.php">Submit a repair request</a><a class="btn btn-light" href="track.php">Track my repair</a></div>
  </div>
  <form class="quick" action="track.php" method="get">
    <label for="q">Already have a service request number?</label>
    <input id="q" name="ref" placeholder="JCRS-..."><button class="btn btn-primary">Check status</button>
  </form>
</section>
<h2 class="sec" id="repair-services">What are you having trouble with?</h2>
<div class="svc-grid">
<?php foreach ($services as $service => $description): ?>
  <a class="svc" href="submit.php?service=<?= urlencode($service) ?>">
    <strong><?= e($service) ?></strong>
    <span><?= e($description) ?></span>
    <small>Choose this repair</small>
  </a>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
