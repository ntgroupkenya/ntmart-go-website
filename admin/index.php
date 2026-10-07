<?php
/* Admin home: sign-in, first-time setup, sign-out and the client list. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/admin.php';

$adminCount = (int) db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
$setupKey = (string) config('setup_key');
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'signout') {
        sign_out();
        flash('You have signed out.');
        redirect('index.php');
    }

    if ($action === 'setup' && $adminCount === 0) {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (too_many_attempts('setup', 5, 15)) {
            $error = 'Too many attempts. Wait 15 minutes and try again.';
        } elseif ($setupKey === '' || !hash_equals($setupKey, (string) ($_POST['setup_key'] ?? ''))) {
            record_attempt('setup');
            $error = 'The setup key is wrong.';
        } elseif (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) {
            $error = 'Use 3 to 40 letters, numbers, dots, dashes or underscores for the username.';
        } elseif (strlen($password) < 10) {
            $error = 'Use a password of at least 10 characters.';
        } else {
            db()->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            clear_attempts('setup');
            sign_in((int) db()->lastInsertId());
            flash('Admin account created. Now clear setup_key in config.local.php.');
            redirect('index.php');
        }
    }

    if ($action === 'signin') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (too_many_attempts('signin', 5, 15)) {
            $error = 'Too many failed sign-ins. Wait 15 minutes and try again.';
        } else {
            $stmt = db()->prepare('SELECT id, password_hash FROM admins WHERE username = ?');
            $stmt->execute([$username]);
            $row = $stmt->fetch();
            // Verify against a dummy hash when the user doesn't exist, so the
            // response time doesn't reveal which usernames are real.
            $hash = $row['password_hash'] ?? '$2y$10$e9a6IIbaD8RjhxC.1U91neQrBXnyKNmkZ.DiTt0U4FZQKypa5cH3m';
            if (password_verify($password, $hash) && $row !== false) {
                clear_attempts('signin');
                if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                    db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
                }
                sign_in((int) $row['id']);
                redirect('index.php');
            }
            record_attempt('signin');
            $error = 'The username or password is wrong.';
        }
    }
}

$admin = current_admin();

/* ---------- Signed out: setup or sign-in form ---------- */

if ($admin === null) {
    admin_header($adminCount === 0 ? 'Set up' : 'Sign in', null);
    ?>
    <div class="admin-auth card">
      <?php if ($adminCount === 0): ?>
        <h1>Create the admin account</h1>
        <?php if ($setupKey === ''): ?>
          <p class="card__text">Account creation is turned off. Add a <code>setup_key</code> to <code>config.local.php</code> at the site root, then reload this page.</p>
        <?php else: ?>
          <p class="card__text">Enter the setup key from <code>config.local.php</code> and choose your username and password.</p>
          <?php if ($error): ?><p class="form__status form__status--error" role="alert"><?= e($error) ?></p><?php endif; ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="setup">
            <div class="form__group"><label class="form__label" for="setup_key">Setup key</label><input class="form__input" id="setup_key" name="setup_key" type="password" required autocomplete="off"></div>
            <div class="form__group"><label class="form__label" for="username">Username</label><input class="form__input" id="username" name="username" required autocomplete="username" pattern="[A-Za-z0-9._\-]{3,40}"></div>
            <div class="form__group"><label class="form__label" for="password">Password <small>(at least 10 characters)</small></label><input class="form__input" id="password" name="password" type="password" minlength="10" required autocomplete="new-password"></div>
            <button class="site-btn site-btn--accent site-btn--block" type="submit">Create account</button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <h1>Sign in</h1>
        <?php if ($error): ?><p class="form__status form__status--error" role="alert"><?= e($error) ?></p><?php endif; ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="signin">
          <div class="form__group"><label class="form__label" for="username">Username</label><input class="form__input" id="username" name="username" required autocomplete="username" autofocus></div>
          <div class="form__group"><label class="form__label" for="password">Password</label><input class="form__input" id="password" name="password" type="password" required autocomplete="current-password"></div>
          <button class="site-btn site-btn--accent site-btn--block" type="submit">Sign in</button>
        </form>
      <?php endif; ?>
    </div>
    <?php
    admin_footer();
    exit;
}

/* ---------- Signed in: client list ---------- */

$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$due = (string) ($_GET['due'] ?? '');

$where = [];
$args = [];
if ($q !== '') {
    $where[] = '(business LIKE :q OR contact_name LIKE :q OR phone LIKE :q OR email LIKE :q OR location LIKE :q)';
    $args[':q'] = '%' . $q . '%';
}
if (isset(DEPLOYMENT_STATUSES[$status])) {
    $where[] = 'status = :status';
    $args[':status'] = $status;
}
if ($due === 'soon') {
    $where[] = "maintenance_due BETWEEN date('now') AND date('now', '+30 days')";
} elseif ($due === 'overdue') {
    $where[] = "maintenance_due < date('now')";
}
$sql = 'SELECT * FROM clients' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY CASE WHEN maintenance_due IS NULL THEN 1 ELSE 0 END, maintenance_due, business COLLATE NOCASE';
$stmt = db()->prepare($sql);
$stmt->execute($args);
$clients = $stmt->fetchAll();

$stats = db()->query("SELECT
    COUNT(*) AS total,
    SUM(status = 'deployed') AS deployed,
    SUM(maintenance_due BETWEEN date('now') AND date('now', '+30 days')) AS due_soon,
    SUM(maintenance_due < date('now')) AS overdue
    FROM clients")->fetch();
$current = db()->query('SELECT version FROM releases WHERE is_current = 1')->fetchColumn();

function maintenance_badge(?string $date): string
{
    if (!$date) {
        return '<span class="badge badge--muted">Not set</span>';
    }
    $days = (int) floor((strtotime($date) - strtotime(date('Y-m-d'))) / 86400);
    $label = date('j M Y', strtotime($date));
    if ($days < 0) {
        return '<span class="badge badge--danger">' . e($label) . ' &middot; overdue</span>';
    }
    if ($days <= 30) {
        return '<span class="badge badge--warn">' . e($label) . ' &middot; ' . $days . 'd</span>';
    }
    return '<span class="badge badge--muted">' . e($label) . '</span>';
}

admin_header('Clients', $admin, 'clients');
?>
<div class="admin-head">
  <h1>Clients</h1>
  <a class="site-btn site-btn--accent site-btn--small" href="client.php"><i class="mdi mdi-plus" aria-hidden="true"></i> Add client</a>
</div>

<div class="admin-stats">
  <a class="admin-stat" href="index.php"><span class="admin-stat__value"><?= (int) $stats['total'] ?></span><span class="admin-stat__label">Clients</span></a>
  <a class="admin-stat" href="index.php?status=deployed"><span class="admin-stat__value"><?= (int) $stats['deployed'] ?></span><span class="admin-stat__label">Deployed</span></a>
  <a class="admin-stat admin-stat--warn" href="index.php?due=soon"><span class="admin-stat__value"><?= (int) $stats['due_soon'] ?></span><span class="admin-stat__label">Maintenance due in 30 days</span></a>
  <a class="admin-stat admin-stat--danger" href="index.php?due=overdue"><span class="admin-stat__value"><?= (int) $stats['overdue'] ?></span><span class="admin-stat__label">Maintenance overdue</span></a>
</div>

<?php if (!$current): ?>
  <p class="notice" style="margin-bottom:24px"><i class="mdi mdi-information-outline" aria-hidden="true"></i><span>No installer is published yet, so clients can't download anything. <a class="link link--accent-bold" href="releases.php">Add an installer</a> when a build is ready to give to clients.</span></p>
<?php endif; ?>

<form class="admin-filters" method="get">
  <label class="visually-hidden" for="q">Search</label>
  <input class="form__input" id="q" name="q" type="search" placeholder="Search business, contact, phone, email or location" value="<?= e($q) ?>">
  <label class="visually-hidden" for="status">Status</label>
  <select class="form__input" id="status" name="status">
    <option value="">All statuses</option>
    <?php foreach (DEPLOYMENT_STATUSES as $key => $label): ?>
      <option value="<?= e($key) ?>"<?= $status === $key ? ' selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <label class="visually-hidden" for="due">Maintenance</label>
  <select class="form__input" id="due" name="due">
    <option value="">Any maintenance date</option>
    <option value="soon"<?= $due === 'soon' ? ' selected' : '' ?>>Due in 30 days</option>
    <option value="overdue"<?= $due === 'overdue' ? ' selected' : '' ?>>Overdue</option>
  </select>
  <button class="site-btn site-btn--dark site-btn--small" type="submit">Filter</button>
  <?php if ($q !== '' || $status !== '' || $due !== ''): ?><a class="link link--gray" href="index.php">Clear</a><?php endif; ?>
</form>

<?php if (!$clients): ?>
  <div class="card card--flat admin-empty">
    <?php if ($stats['total'] == 0): ?>
      <h2>No clients yet</h2>
      <p class="card__text">Add your first client to track their plan, deployment, maintenance renewal and download access.</p>
      <a class="site-btn site-btn--accent" href="client.php">Add client</a>
    <?php else: ?>
      <p class="card__text">No clients match this search.</p>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="site-table admin-table">
    <table>
      <thead>
        <tr><th scope="col">Business</th><th scope="col">Contact</th><th scope="col">Plan</th><th scope="col">Status</th><th scope="col">Maintenance due</th><th scope="col">Download</th></tr>
      </thead>
      <tbody>
      <?php foreach ($clients as $c): ?>
        <tr>
          <th scope="row"><a class="link link--accent-bold" href="client.php?id=<?= (int) $c['id'] ?>"><?= e($c['business']) ?></a><?php if ($c['location'] !== ''): ?><br><small><?= e($c['location']) ?></small><?php endif; ?></th>
          <td><?= e($c['contact_name']) ?><?php if ($c['phone'] !== ''): ?><br><small><?= e($c['phone']) ?></small><?php endif; ?></td>
          <td><?= e(PLANS[$c['plan']]['label'] ?? $c['plan']) ?></td>
          <td><?= e(DEPLOYMENT_STATUSES[$c['status']] ?? $c['status']) ?></td>
          <td><?= maintenance_badge($c['maintenance_due']) ?></td>
          <td><?= $c['download_enabled'] && $c['download_code_hash'] ? '<span class="badge">On</span>' : '<span class="badge badge--muted">Off</span>' ?><?php if ($c['download_count']): ?> <small><?= (int) $c['download_count'] ?>&times;</small><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?php
admin_footer();
