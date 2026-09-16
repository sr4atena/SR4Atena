<?php
/** @var string $appName @var string $gameName @var string $csrfToken @var bool $needsTotp @var string|null $error */
?>
<main class="login">
  <section class="card" aria-labelledby="login-title">
    <header class="card-header">
      <h1 id="login-title"><?= e($appName) ?></h1>
      <p class="subtitle">Analisi economica di <?= e($gameName) ?></p>
    </header>
<?php if ($error !== null): ?>
    <p class="alert" role="alert"><?= e($error) ?></p>
<?php endif ?>
    <form method="post" action="/login" class="login-form">
      <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?php if ($needsTotp): ?>
      <p class="hint">Inserisci il codice a sei cifre generato dalla tua app di autenticazione.</p>
      <label for="totp">Codice di verifica</label>
      <input id="totp" name="totp" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
             autocomplete="one-time-code" autocapitalize="off" spellcheck="false" required>
      <button type="submit">Verifica</button>
      <p class="hint"><a href="/login">Torna all'accesso</a></p>
<?php else: ?>
      <label for="username">Nome utente</label>
      <input id="username" name="username" type="text" maxlength="32"
             autocomplete="username" autocapitalize="off" spellcheck="false" required>
      <label for="password">Password</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required>
      <button type="submit">Accedi</button>
<?php endif ?>
    </form>
  </section>
</main>
