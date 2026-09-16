<?php
/** @var int $status @var string $title @var string $message @var string|null $details @var string $appName */
?>
<main class="login">
  <section class="card" aria-labelledby="error-title">
    <header class="card-header">
      <h1 id="error-title"><?= e($status) ?> · <?= e($title) ?></h1>
      <p class="subtitle"><?= e($appName) ?></p>
    </header>
    <p class="alert" role="alert"><?= e($message) ?></p>
<?php if ($details !== null): ?>
    <pre class="details"><?= e($details) ?></pre>
<?php endif ?>
    <p class="hint"><a href="/">Torna alla pagina principale</a></p>
  </section>
</main>
