<?php
/* Change the signed-in admin's password, or add another admin. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/admin.php';

$admin = require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'password') {
        $stmt = db()->prepare('SELECT password_hash FROM admins WHERE id = ?');
        $stmt->execute([$admin['id']]);
        $hash = (string) $stmt->fetchColumn();
        $new = (string) ($_POST['new_password'] ?? '');
        if (!password_verify((string) ($_POST['current_password'] ?? ''), $hash)) {
            flash('Your current password is wrong.', 'error');
        } elseif (strlen($new) < 10) {
            flash('Use a new password of at least 10 characters.', 'error');
        } elseif ($new !== (string) ($_POST['confirm_password'] ?? '')) {
            flash('The new passwords don\'t match.', 'error');
        } else {
            db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
            sign_in((int) $admin['id']);
            flash('Password changed.');
        }
        redirect('account.php');
    }

    if ($action === 'add_admin') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) {
            flash('Use 3 to 40 letters, numbers, dots, dashes or underscores for the username.', 'error');
        } elseif (strlen($password) < 10) {
            flash('Use a password of at least 10 characters.', 'error');
        } else {
            try {
                db()->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
                flash("Added admin $username.");
            } catch (PDOException $ex) {
                flash('That username is already taken.', 'error');
            }
        }
        redirect('account.php');
    }

    if ($action === 'remove_admin') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === (int) $admin['id']) {
            flash('You can\'t remove your own account.', 'error');
        } else {
            db()->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]);
            flash('Admin removed.');
        }
        redirect('account.php');
    }
}

$admins = db()->query('SELECT id, username, created_at FROM admins ORDER BY username COLLATE NOCASE')->fetchAll();

admin_header('Account', $admin, 'account');
?>
<div class="admin-head"><h1>Account</h1></div>
<div class="row row--gap">
  <div class="col-6 col-t-12">
    <form class="card" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <h2 class="admin-card-title">Change your password</h2>
      <input type="hidden" name="username" value="<?= e($admin['username']) ?>" autocomplete="username">
      <div class="form__group"><label class="form__label" for="current_password">Current password</label><input class="form__input" id="current_password" name="current_password" type="password" required autocomplete="current-password"></div>
      <div class="form__group"><label class="form__label" for="new_password">New password <small>(at least 10 characters)</small></label><input class="form__input" id="new_password" name="new_password" type="password" minlength="10" required autocomplete="new-password"></div>
      <div class="form__group"><label class="form__label" for="confirm_password">Repeat new password</label><input class="form__input" id="confirm_password" name="confirm_password" type="password" minlength="10" required autocomplete="new-password"></div>
      <button class="site-btn site-btn--accent" type="submit">Change password</button>
    </form>
  </div>
  <div class="col-6 col-t-12">
    <div class="card card--flat">
      <h2 class="admin-card-title">Admins</h2>
      <ul class="admin-list">
        <?php foreach ($admins as $a): ?>
          <li class="admin-list__row"><span><?= e($a['username']) ?><?= (int) $a['id'] === (int) $admin['id'] ? ' <small>(you)</small>' : '' ?></span>
            <?php if ((int) $a['id'] !== (int) $admin['id']): ?>
              <form method="post" data-confirm="Remove admin <?= e($a['username']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="remove_admin"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="link admin-link-btn admin-link-btn--danger" type="submit">Remove</button></form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <form method="post" style="margin-top:20px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_admin">
        <h3 class="admin-card-subtitle">Add an admin</h3>
        <div class="form__group"><label class="form__label" for="new_username">Username</label><input class="form__input" id="new_username" name="username" required autocomplete="off" pattern="[A-Za-z0-9._\-]{3,40}"></div>
        <div class="form__group"><label class="form__label" for="new_admin_password">Password <small>(at least 10 characters)</small></label><input class="form__input" id="new_admin_password" name="password" type="password" minlength="10" required autocomplete="new-password"></div>
        <button class="site-btn site-btn--light site-btn--small" type="submit">Add admin</button>
      </form>
    </div>
  </div>
</div>
<script src="../assets/js/admin.js"></script>
<?php
admin_footer();
