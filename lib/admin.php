<?php
/* Admin sign-in, session timeout and the shared admin page layout. */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function current_admin(): ?array
{
    start_session();
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    $idle = (int) config('admin_idle_minutes') * 60;
    if (time() - (int) ($_SESSION['last_seen'] ?? 0) > $idle) {
        sign_out();
        flash('You were signed out after a period of inactivity.', 'error');
        return null;
    }
    $_SESSION['last_seen'] = time();
    $stmt = db()->prepare('SELECT id, username FROM admins WHERE id = ?');
    $stmt->execute([$_SESSION['admin_id']]);
    return $stmt->fetch() ?: null;
}

function require_admin(): array
{
    $admin = current_admin();
    if ($admin === null) {
        redirect('index.php');
    }
    return $admin;
}

function sign_in(int $adminId): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $adminId;
    $_SESSION['last_seen'] = time();
    unset($_SESSION['csrf']);
}

function sign_out(): void
{
    start_session();
    $_SESSION = [];
    session_regenerate_id(true);
}

/* True when the data folder sits inside the web root (Windows paths included). */
function data_dir_is_public(): bool
{
    $norm = function (string $path): string {
        $path = rtrim(str_replace('\\', '/', $path), '/') . '/';
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    };
    $docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $dataDir = realpath(data_dir());
    return $docRoot && $dataDir && str_starts_with($norm($dataDir), $norm($docRoot));
}

function admin_header(string $title, ?array $admin, string $active = ''): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    $nav = [
        'clients' => ['index.php', 'Clients'],
        'releases' => ['releases.php', 'Installers'],
        'account' => ['account.php', 'Account'],
    ];
    ?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title) ?> | NTmart Go admin</title>
  <link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@7.4.47/css/materialdesignicons.min.css">
  <link rel="stylesheet" href="../assets/css/main.css">
  <link rel="stylesheet" href="../assets/css/themes/blue-gradient.css">
  <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin">
<div class="admin-bar">
  <div class="container admin-bar__inner">
    <a class="menu__logo" href="index.php"><svg viewBox="0 0 40 40" aria-hidden="true"><rect width="40" height="40" fill="var(--accent)"/><path d="M11 28V12h3.2l8.6 10.6V12H26v16h-3.2l-8.6-10.6V28z" fill="#fff"/><circle cx="31" cy="27" r="3" fill="#fff"/></svg><span>NTmart<b>Go</b> admin</span></a>
    <?php if ($admin): ?>
    <nav class="admin-bar__nav" aria-label="Admin">
      <?php foreach ($nav as $key => [$href, $label]): ?>
        <a class="link link--gray<?= $key === $active ? ' link--active' : '' ?>" href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
      <form method="post" action="index.php" class="admin-bar__signout">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="signout">
        <button class="link link--gray" type="submit"><i class="mdi mdi-logout" aria-hidden="true"></i> Sign out <?= e($admin['username']) ?></button>
      </form>
    </nav>
    <?php endif; ?>
  </div>
</div>
<main class="admin-main">
  <div class="container">
<?php
    if ($admin && data_dir_is_public()) {
        echo '<p class="notice" style="margin-bottom:24px"><i class="mdi mdi-shield-alert-outline" aria-hidden="true"></i><span>The client database and installers are stored inside the website folder. On Apache (XAMPP, cPanel) <code>data/.htaccess</code> blocks visitors, but for full safety set <code>data_dir</code> in <code>config.local.php</code> to a folder outside the website.</span></p>';
    }
    $flash = flash();
    if ($flash) {
        echo '<p class="form__status form__status--' . ($flash['type'] === 'ok' ? 'ok' : 'error') . '" role="status">' . e($flash['message']) . '</p>';
    }
}

function admin_footer(): void
{
    ?>
  </div>
</main>
</body>
</html>
<?php
}
