<?php
/*
 * Client download page. A client enters the email and download code from the
 * admin area; a correct pair gives a download link that works for a short time.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$error = '';
$ready = null;
$release = db()->query('SELECT * FROM releases WHERE is_current = 1')->fetch() ?: null;

/* ---- A download link: stream the file ---- */
if (isset($_GET['t'])) {
    $hash = hash('sha256', (string) $_GET['t']);
    $stmt = db()->prepare("SELECT t.*, r.stored_name, r.original_name, r.version, c.download_enabled, c.download_code_hash
        FROM download_tokens t JOIN releases r ON r.id = t.release_id JOIN clients c ON c.id = t.client_id
        WHERE t.token_hash = ? AND t.expires_at > datetime('now')");
    $stmt->execute([$hash]);
    $token = $stmt->fetch();
    $path = $token ? releases_dir() . '/' . basename($token['stored_name']) : '';

    if (!$token || !$token['download_enabled'] || !$token['download_code_hash'] || !is_file($path)) {
        $error = 'This download link has expired or is no longer valid. Enter your email and code again to get a new one.';
    } else {
        if (!$token['used']) {
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE download_tokens SET used = 1 WHERE token_hash = ?')->execute([$hash]);
            $pdo->prepare('INSERT INTO downloads (client_id, release_id, ip) VALUES (?, ?, ?)')->execute([$token['client_id'], $token['release_id'], client_ip()]);
            $pdo->prepare("UPDATE clients SET download_count = download_count + 1, last_download_at = datetime('now') WHERE id = ?")->execute([$token['client_id']]);
            $pdo->commit();
        }
        $ext = strtolower(pathinfo($token['original_name'], PATHINFO_EXTENSION));
        $filename = 'NTmartGo-Setup-' . preg_replace('/[^0-9A-Za-z.\-]/', '', $token['version']) . '.' . $ext;
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($path));
        set_time_limit(0);
        readfile($path);
        exit;
    }
}

/* ---- Email and code ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $release) {
    $email = trim((string) ($_POST['email'] ?? ''));
    $code = normalise_code((string) ($_POST['code'] ?? ''));

    if (too_many_attempts('download', 10, 15)) {
        $error = 'Too many attempts. Wait 15 minutes and try again, or contact us for help.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($code) !== 14) {
        $error = 'Enter your email address and the 12-character download code we gave you.';
    } else {
        $stmt = db()->prepare('SELECT id, download_code_hash FROM clients WHERE email = ? AND download_enabled = 1 AND download_code_hash IS NOT NULL');
        $stmt->execute([$email]);
        $match = null;
        foreach ($stmt->fetchAll() as $row) {
            if (password_verify($code, $row['download_code_hash'])) {
                $match = $row;
                break;
            }
        }
        if (!$match) {
            record_attempt('download');
            $error = "That email and code don't match. Check the code we gave you, or contact us for a new one.";
        } else {
            clear_attempts('download');
            $raw = bin2hex(random_bytes(32));
            db()->prepare("INSERT INTO download_tokens (token_hash, client_id, release_id, expires_at) VALUES (?, ?, ?, datetime('now', ?))")
                ->execute([hash('sha256', $raw), $match['id'], $release['id'], '+' . (int) config('download_link_minutes') . ' minutes']);
            db()->exec("DELETE FROM download_tokens WHERE expires_at < datetime('now', '-1 day')");
            $ready = ['url' => 'download.php?t=' . $raw];
        }
    }
}

function download_size(int $bytes): string
{
    return $bytes >= 1 << 20 ? number_format($bytes / (1 << 20), 1) . ' MB' : number_format(max(1, $bytes / 1024)) . ' KB';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Download | NTmart Go</title>
  <meta name="description" content="Download NTmart Go. For NTmart Go clients with a download code.">
  <meta name="author" content="DECODE IT Solutions">
  <meta property="og:title" content="Download | NTmart Go">
  <meta property="og:description" content="Download NTmart Go. For NTmart Go clients with a download code.">
  <meta name="theme-color" content="#00097b">
  <link rel="icon" href="assets/img/favicon.ico" sizes="any">
  <link rel="icon" href="assets/img/favicon-32.png" type="image/png" sizes="32x32">
  <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
  <meta property="og:image" content="assets/img/logo.png">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@7.4.47/css/materialdesignicons.min.css">
  <link rel="stylesheet" href="assets/css/main.css">
  <!-- Colour scheme: ntgo (from the logo), or blue, green, red, violet, each also as -gradient -->
  <link rel="stylesheet" href="assets/css/themes/ntgo.css">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<div class="menu">
  <div class="container menu__inner">
    <a class="menu__logo" href="index.html" aria-label="NTmart Go home"><img src="assets/img/logo-wordmark.png" width="187" height="40" alt="NTmart Go"></a>

    <nav class="menu__center-nav d-t-none" aria-label="Main">
      <ul>
        <li class="menu__dropdown">
          <button class="link link--gray menu__dropdown-btn link--active" type="button" aria-expanded="false">Product <i class="mdi mdi-chevron-down" aria-hidden="true"></i></button>
          <div class="menu__dropdown-content">
            <a class="link link--gray" href="index.html">Overview</a>
            <a class="link link--gray" href="features.html">Features</a>
            <a class="link link--gray" href="get.html">Get NTmart Go</a>
            <a class="link link--gray link--active" href="download.php" aria-current="page">Download (clients)</a>
          </div>
        </li>
          <li><a class="link link--gray" href="pricing.html">Pricing</a></li>
          <li><a class="link link--gray" href="faq.html">FAQ</a></li>
          <li><a class="link link--gray" href="about.html">About</a></li>
      </ul>
    </nav>

    <div class="menu__right-nav d-t-none">
      <a class="link link--gray" href="contact.html">Contact</a>
      <a class="site-btn site-btn--accent site-btn--small" href="pricing.html">Get NTmart Go</a>
    </div>

    <button class="menu__burger d-none d-t-flex" type="button" aria-controls="mobile-menu" aria-expanded="false" aria-label="Open menu"><i class="mdi mdi-menu" aria-hidden="true"></i></button>
  </div>

  <nav class="mobile-menu d-none d-t-block" id="mobile-menu" aria-label="Mobile" hidden>
    <div class="container">
      <ul class="mobile-menu__ul">
        <li class="mobile-menu__li">
          <button class="mobile-menu__collapse link link--dark-gray" type="button" aria-expanded="true" aria-controls="mobile-product">Product <i class="mdi mdi-chevron-down" aria-hidden="true"></i></button>
          <ul class="mobile-menu__ul mobile-menu__ul--collapsed" id="mobile-product">
            <li class="mobile-menu__li"><a class="link link--gray" href="index.html">Overview</a></li>
            <li class="mobile-menu__li"><a class="link link--gray" href="features.html">Features</a></li>
            <li class="mobile-menu__li"><a class="link link--gray" href="get.html">Get NTmart Go</a></li>
            <li class="mobile-menu__li"><a class="link link--gray link--active" href="download.php" aria-current="page">Download (clients)</a></li>
          </ul>
        </li>
        <li class="mobile-menu__li"><a class="link link--dark-gray" href="pricing.html">Pricing</a></li>
        <li class="mobile-menu__li"><a class="link link--dark-gray" href="faq.html">FAQ</a></li>
        <li class="mobile-menu__li"><a class="link link--dark-gray" href="about.html">About</a></li>
        <li class="mobile-menu__li"><a class="link link--dark-gray" href="contact.html">Contact</a></li>
      </ul>
      <div class="mobile-menu__btn"><a class="site-btn site-btn--accent site-btn--block" href="pricing.html">Get NTmart Go</a></div>
    </div>
  </nav>
</div>

<main id="main">
<header class="header-home header-home--center-content header-home--small">
  <div class="background background--wave background--wave-light">
    <div class="container">
      <span class="badge badge--on-color">Clients</span>
      <h1 class="header-home__title">Download NTmart Go</h1>
      <p class="header-home__description">For NTmart Go clients. Enter the email address and download code we gave you.</p>
    </div>
  </div>
</header>

<section class="section section--light">
  <div class="container">
    <div class="row">
      <div class="col-6 col-t-12 col-offset-3">
        <?php if ($ready && $release): ?>
          <div class="card">
            <span class="card__icon"><i class="mdi mdi-check-circle-outline" aria-hidden="true"></i></span>
            <h2 class="card__title">Your download is ready</h2>
            <p class="card__text">NTmart Go <?= e($release['version']) ?> for Windows &middot; <?= e(download_size((int) $release['size'])) ?></p>
            <?php if ($release['notes'] !== ''): ?>
              <div class="download-notes"><strong>What's new</strong><p><?= nl2br(e($release['notes'])) ?></p></div>
            <?php endif; ?>
            <a class="site-btn site-btn--accent site-btn--block" style="margin:24px 0 12px" href="<?= e($ready['url']) ?>"><i class="mdi mdi-download" aria-hidden="true"></i> Download NTmart Go <?= e($release['version']) ?></a>
            <p class="download-meta">This link works for <?= (int) config('download_link_minutes') ?> minutes. SHA-256: <code><?= e($release['sha256']) ?></code></p>
          </div>
        <?php elseif (!$release): ?>
          <div class="card">
            <span class="card__icon"><i class="mdi mdi-clock-outline" aria-hidden="true"></i></span>
            <h2 class="card__title">Not available yet</h2>
            <p class="card__text">There's no installer to download right now. We'll let you know when it's ready, or <a class="link link--accent-bold" href="contact.html?topic=support">contact us</a>.</p>
          </div>
        <?php else: ?>
          <form class="card" method="post" action="download.php">
            <?php if ($error): ?><p class="form__status form__status--error" role="alert" style="margin-top:0"><?= e($error) ?></p><?php endif; ?>
            <div class="form__group">
              <label class="form__label" for="email">Email address</label>
              <input class="form__input" id="email" name="email" type="email" autocomplete="email" required value="<?= e($_POST['email'] ?? '') ?>">
            </div>
            <div class="form__group">
              <label class="form__label" for="code">Download code</label>
              <input class="form__input download-code-input" id="code" name="code" required autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX-XXXX" maxlength="20">
            </div>
            <button class="site-btn site-btn--accent site-btn--block" type="submit">Get my download</button>
            <p class="download-meta" style="margin-top:16px">Don't have a code? Codes are for clients who have bought NTmart Go. <a class="link link--accent-bold" href="get.html">See how to get it</a> or <a class="link link--accent-bold" href="contact.html?topic=support">contact us</a>.</p>
          </form>
        <?php endif; ?>
        <?php if ($error && !$release): ?><p class="form__status form__status--error" role="alert"><?= e($error) ?></p><?php endif; ?>
      </div>
    </div>
  </div>
</section>
</main>

<footer>
  <div class="container footer-menu">
    <div class="footer-menu__inner">
      <a class="menu__logo menu__logo--footer" href="index.html" aria-label="NTmart Go home"><img src="assets/img/logo-wordmark.png" width="187" height="40" alt="NTmart Go"></a>
      <nav class="footer-menu__nav" aria-label="Footer">
        <ul>
          <li><a class="link link--gray" href="features.html">Features</a></li>
          <li><a class="link link--gray" href="pricing.html">Pricing</a></li>
          <li><a class="link link--gray" href="get.html">Get NTmart Go</a></li>
          <li><a class="link link--gray" href="download.php">Download</a></li>
          <li><a class="link link--gray" href="about.html">About</a></li>
          <li><a class="link link--gray" href="faq.html">FAQ</a></li>
          <li><a class="link link--gray" href="privacy.html">Privacy</a></li>
          <li><a class="link link--gray" href="contact.html">Contact</a></li>
        </ul>
      </nav>
      <p class="footer-menu__social">
        <a class="link link--gray" href="https://wa.me/254711294124?text=Hi%2C%20I%27m%20interested%20in%20NTmart%20Go." target="_blank" rel="noopener" aria-label="WhatsApp"><i class="mdi mdi-whatsapp" aria-hidden="true"></i></a>
        <a class="link link--gray" href="mailto:ntmart@ntgroup.co.ke" aria-label="Email"><i class="mdi mdi-email-outline" aria-hidden="true"></i></a>
        <a class="link link--gray" href="https://www.facebook.com/ntmartpos/" target="_blank" rel="noopener" aria-label="Facebook"><i class="mdi mdi-facebook" aria-hidden="true"></i></a>
      </p>
    </div>
  </div>
  <div class="footer">
    <div class="container">
      <p>+254 (0) 711 294 124 &middot; <a class="link link--gray" href="mailto:ntmart@ntgroup.co.ke">ntmart@ntgroup.co.ke</a></p>
      <p>&copy; <span data-year>2026</span> <a class="link link--gray" href="https://www.ntgroup.co.ke/decode/">DECODE IT Solutions</a>, Nairobi. Part of <a class="link link--gray" href="https://www.ntgroup.co.ke/">NT Group</a> by Nemsey Traders Ltd.</p>
    </div>
  </div>
</footer>

<script src="assets/js/main.js"></script>
</body>
</html>
