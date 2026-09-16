<?php
/** Shown until templates/dashboard.php exists. @var array{username: string, role: string} $user @var string $appName @var string $csrfToken */
?>
<main class="login">
  <section class="card">
    <h1><?= e($appName) ?></h1>
    <p class="hint">Dashboard in costruzione — accesso effettuato come <?= e($user['username']) ?> (<?= e($user['role']) ?>).</p>
    <form method="post" action="/logout">
      <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
      <button type="submit">Esci</button>
    </form>
  </section>
</main>
