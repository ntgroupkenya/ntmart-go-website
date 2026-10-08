<?php
/* Installers: upload a build (or pick one copied in by FTP) and choose which one clients get. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/admin.php';

$admin = require_admin();
const ALLOWED_EXTENSIONS = ['exe', 'msi', 'zip'];

$incomingDir = releases_dir() . '/incoming';
if (!is_dir($incomingDir)) {
    mkdir($incomingDir, 0750, true);
}

function ini_bytes(string $value): int
{
    $value = trim($value);
    $unit = strtolower(substr($value, -1));
    $n = (int) $value;
    return match ($unit) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
}

function human_size(int $bytes): string
{
    if ($bytes >= 1 << 20) {
        return number_format($bytes / (1 << 20), 1) . ' MB';
    }
    return number_format(max(1, $bytes / 1024)) . ' KB';
}

function incoming_files(string $dir): array
{
    $files = [];
    foreach (scandir($dir) ?: [] as $name) {
        $path = $dir . '/' . $name;
        if (is_file($path) && in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ALLOWED_EXTENSIONS, true)) {
            $files[] = $name;
        }
    }
    return $files;
}

function add_release(string $sourcePath, string $originalName, string $version, string $notes, bool $isUpload): void
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $target = releases_dir() . '/' . $stored;
    $moved = $isUpload ? move_uploaded_file($sourcePath, $target) : rename($sourcePath, $target);
    if (!$moved) {
        throw new RuntimeException('Could not save the file. Check the data folder is writable.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec('UPDATE releases SET is_current = 0');
    $pdo->prepare('INSERT INTO releases (version, stored_name, original_name, size, sha256, notes, is_current) VALUES (?, ?, ?, ?, ?, ?, 1)')
        ->execute([$version, $stored, $originalName, filesize($target), hash_file('sha256', $target), $notes]);
    $pdo->commit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $version = trim((string) ($_POST['version'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    try {
        if ($action === 'upload' || $action === 'import') {
            if (!preg_match('/^[0-9A-Za-z.\- ]{1,30}$/', $version)) {
                throw new InvalidArgumentException('Enter a version such as 1.0.0 (letters, numbers, dots and dashes).');
            }
            if (mb_strlen($notes) > 2000) {
                throw new InvalidArgumentException('Keep the notes under 2,000 characters.');
            }
        }

        if ($action === 'upload') {
            $file = $_FILES['installer'] ?? null;
            if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
                throw new InvalidArgumentException('Choose the installer file to upload.');
            }
            if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new InvalidArgumentException('The file is bigger than this server accepts. Copy it into the incoming folder by FTP instead (see below).');
            }
            if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                throw new InvalidArgumentException('The upload failed. Try again.');
            }
            $name = basename((string) $file['name']);
            if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ALLOWED_EXTENSIONS, true)) {
                throw new InvalidArgumentException('Upload an .exe, .msi or .zip file.');
            }
            add_release($file['tmp_name'], $name, $version, $notes, true);
            flash("Version $version is now the installer clients download.");
            redirect('releases.php');
        }

        if ($action === 'import') {
            $name = basename((string) ($_POST['file'] ?? ''));
            if (!in_array($name, incoming_files($incomingDir), true)) {
                throw new InvalidArgumentException('Choose a file from the incoming folder.');
            }
            add_release($incomingDir . '/' . $name, $name, $version, $notes, false);
            flash("Version $version is now the installer clients download.");
            redirect('releases.php');
        }

        if ($action === 'make_current') {
            $id = (int) ($_POST['id'] ?? 0);
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->exec('UPDATE releases SET is_current = 0');
            $pdo->prepare('UPDATE releases SET is_current = 1 WHERE id = ?')->execute([$id]);
            $pdo->commit();
            flash('Clients now download the selected version.');
            redirect('releases.php');
        }

        if ($action === 'unpublish') {
            db()->exec('UPDATE releases SET is_current = 0');
            db()->exec('DELETE FROM download_tokens');
            flash('No installer is published now. Clients can\'t download until you publish one.');
            redirect('releases.php');
        }

        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = db()->prepare('SELECT stored_name FROM releases WHERE id = ?');
            $stmt->execute([$id]);
            $stored = $stmt->fetchColumn();
            if ($stored !== false) {
                db()->prepare('DELETE FROM releases WHERE id = ?')->execute([$id]);
                $path = releases_dir() . '/' . basename((string) $stored);
                if (is_file($path)) {
                    unlink($path);
                }
                flash('Installer deleted.');
            }
            redirect('releases.php');
        }
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'error');
        redirect('releases.php');
    }
}

$releases = db()->query('SELECT r.*, (SELECT COUNT(*) FROM downloads d WHERE d.release_id = r.id) AS downloads
    FROM releases r ORDER BY r.uploaded_at DESC, r.id DESC')->fetchAll();
$incoming = incoming_files($incomingDir);
$maxUpload = min(ini_bytes((string) ini_get('upload_max_filesize')), ini_bytes((string) ini_get('post_max_size')));

admin_header('Installers', $admin, 'releases');
?>
<div class="admin-head"><h1>Installers</h1></div>

<p class="notice" style="margin-bottom:24px"><i class="mdi mdi-alert-outline" aria-hidden="true"></i><span>Only publish a build that is ready for clients. The Go edition's plan says it must not go to clients before licensing (Phase 8) is finished.</span></p>

<div class="row row--gap">
  <div class="col-6 col-t-12">
    <form class="card" method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload">
      <h2 class="admin-card-title">Upload an installer</h2>
      <p class="card__text">This server accepts files up to <?= e(human_size($maxUpload)) ?>. The new version becomes the one clients download.</p>
      <div class="form__group"><label class="form__label" for="installer">Installer (.exe, .msi or .zip)</label><input class="form__input" id="installer" name="installer" type="file" accept=".exe,.msi,.zip" required></div>
      <div class="form__group"><label class="form__label" for="version">Version</label><input class="form__input" id="version" name="version" required maxlength="30" placeholder="1.0.0"></div>
      <div class="form__group"><label class="form__label" for="notes">What's new <small>(optional, shown to clients)</small></label><textarea class="form__input" id="notes" name="notes" maxlength="2000"></textarea></div>
      <button class="site-btn site-btn--accent" type="submit"><i class="mdi mdi-upload" aria-hidden="true"></i> Upload and publish</button>
    </form>
  </div>
  <div class="col-6 col-t-12">
    <form class="card card--flat" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="import">
      <h2 class="admin-card-title">Too big to upload?</h2>
      <p class="card__text">Copy the installer by FTP into <code><?= e($incomingDir) ?></code>, reload this page, then publish it here.</p>
      <?php if ($incoming): ?>
        <div class="form__group"><label class="form__label" for="file">File</label>
          <select class="form__input" id="file" name="file"><?php foreach ($incoming as $name): ?><option><?= e($name) ?></option><?php endforeach; ?></select></div>
        <div class="form__group"><label class="form__label" for="version2">Version</label><input class="form__input" id="version2" name="version" required maxlength="30" placeholder="1.0.0"></div>
        <div class="form__group"><label class="form__label" for="notes2">What's new <small>(optional)</small></label><textarea class="form__input" id="notes2" name="notes" maxlength="2000"></textarea></div>
        <button class="site-btn site-btn--accent" type="submit">Publish this file</button>
      <?php else: ?>
        <p class="card__text"><em>No installer files in the incoming folder.</em></p>
      <?php endif; ?>
    </form>
  </div>
</div>

<h2 style="margin-top:24px">All installers</h2>
<?php if (!$releases): ?>
  <div class="card card--flat admin-empty"><p class="card__text">No installers yet. Clients see "not available yet" on the download page.</p></div>
<?php else: ?>
  <div class="site-table admin-table">
    <table>
      <thead><tr><th scope="col">Version</th><th scope="col">File</th><th scope="col">Uploaded</th><th scope="col">Downloads</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
      <tbody>
      <?php foreach ($releases as $r): ?>
        <tr>
          <th scope="row"><?= e($r['version']) ?> <?= $r['is_current'] ? '<span class="badge">Published</span>' : '' ?></th>
          <td><?= e($r['original_name']) ?><br><small><?= e(human_size((int) $r['size'])) ?> &middot; SHA-256 <?= e(substr($r['sha256'], 0, 12)) ?>&hellip;</small></td>
          <td><?= e(date('j M Y', strtotime($r['uploaded_at']))) ?></td>
          <td><?= (int) $r['downloads'] ?></td>
          <td class="admin-actions">
            <?php if (!$r['is_current']): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="make_current"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="link link--accent-bold admin-link-btn" type="submit">Publish</button></form>
            <?php else: ?>
              <form method="post" data-confirm="Stop offering downloads? Clients will see 'not available yet' until you publish a version."><?= csrf_field() ?><input type="hidden" name="action" value="unpublish"><button class="link link--gray admin-link-btn" type="submit">Unpublish</button></form>
            <?php endif; ?>
            <form method="post" data-confirm="Delete installer <?= e($r['version']) ?>? The file is removed from the server."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="link admin-link-btn admin-link-btn--danger" type="submit">Delete</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<script src="../assets/js/admin.js"></script>
<?php
admin_footer();
