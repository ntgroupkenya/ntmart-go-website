<?php
/* Add or edit one client: details, plan, deployment, maintenance and download access. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/admin.php';

$admin = require_admin();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

function load_client(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

$client = $id ? load_client($id) : null;
if ($id && !$client) {
    http_response_code(404);
    admin_header('Client not found', $admin, 'clients');
    echo '<h1>Client not found</h1><p><a class="link link--accent-bold" href="index.php">Back to clients</a></p>';
    admin_footer();
    exit;
}

$errors = [];
$form = $client ?? [
    'business' => '', 'contact_name' => '', 'phone' => '', 'email' => '', 'location' => '',
    'plan' => 'standard', 'status' => 'lead', 'licence_price' => PLANS['standard']['price'],
    'deployment_quote' => 0, 'purchase_date' => '', 'maintenance_due' => '', 'notes' => '',
    'download_enabled' => 0,
];

function valid_date(string $value): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return $d !== false && $d->format('Y-m-d') === $value;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_csrf();
    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'delete' && $client) {
        db()->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        flash('Deleted ' . $client['business'] . '.');
        redirect('index.php');
    }

    if ($action === 'renew' && $client) {
        // Extend by a year from the later of today and the current due date.
        $base = max(date('Y-m-d'), (string) $client['maintenance_due']);
        $next = date('Y-m-d', strtotime($base . ' +1 year'));
        db()->prepare("UPDATE clients SET maintenance_due = ?, updated_at = datetime('now') WHERE id = ?")->execute([$next, $id]);
        flash('Maintenance renewed until ' . date('j M Y', strtotime($next)) . '.');
        redirect('client.php?id=' . $id);
    }

    if ($action === 'new_code' && $client) {
        if (!filter_var($client['email'], FILTER_VALIDATE_EMAIL)) {
            flash('Add the client\'s email address first. They sign in to the download page with it.', 'error');
            redirect('client.php?id=' . $id);
        }
        $code = new_download_code();
        db()->prepare("UPDATE clients SET download_code_hash = ?, download_enabled = 1, updated_at = datetime('now') WHERE id = ?")
            ->execute([password_hash($code, PASSWORD_DEFAULT), $id]);
        start_session();
        $_SESSION['new_code'] = ['id' => $id, 'code' => $code];
        redirect('client.php?id=' . $id);
    }

    if ($action === 'revoke' && $client) {
        db()->prepare("UPDATE clients SET download_code_hash = NULL, download_enabled = 0, updated_at = datetime('now') WHERE id = ?")->execute([$id]);
        db()->prepare('DELETE FROM download_tokens WHERE client_id = ?')->execute([$id]);
        flash('Download code removed. The old code no longer works.');
        redirect('client.php?id=' . $id);
    }

    if ($action === 'save') {
        $form = [
            'business' => trim((string) ($_POST['business'] ?? '')),
            'contact_name' => trim((string) ($_POST['contact_name'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'location' => trim((string) ($_POST['location'] ?? '')),
            'plan' => (string) ($_POST['plan'] ?? 'standard'),
            'status' => (string) ($_POST['status'] ?? 'lead'),
            'licence_price' => trim((string) ($_POST['licence_price'] ?? '')),
            'deployment_quote' => trim((string) ($_POST['deployment_quote'] ?? '')),
            'purchase_date' => trim((string) ($_POST['purchase_date'] ?? '')),
            'maintenance_due' => trim((string) ($_POST['maintenance_due'] ?? '')),
            'notes' => trim((string) ($_POST['notes'] ?? '')),
            'download_enabled' => isset($_POST['download_enabled']) ? 1 : 0,
        ];

        if ($form['business'] === '' || mb_strlen($form['business']) > 150) {
            $errors['business'] = 'Enter the business name (up to 150 characters).';
        }
        if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address, or leave it empty.';
        }
        if ($form['phone'] !== '' && !preg_match('/^[0-9+()\s-]{7,20}$/', $form['phone'])) {
            $errors['phone'] = 'Enter a valid phone number.';
        }
        if (!isset(PLANS[$form['plan']])) {
            $errors['plan'] = 'Choose a plan.';
        }
        if (!isset(DEPLOYMENT_STATUSES[$form['status']])) {
            $errors['status'] = 'Choose a status.';
        }
        // If the plan changed but the price is still the old plan's list price
        // (for example the page script didn't run), use the new plan's price.
        $oldPlan = $client['plan'] ?? 'standard';
        if (isset(PLANS[$form['plan']], PLANS[$oldPlan]) && $form['plan'] !== $oldPlan
            && str_replace([',', ' '], '', $form['licence_price']) === (string) PLANS[$oldPlan]['price']) {
            $form['licence_price'] = (string) PLANS[$form['plan']]['price'];
        }
        foreach (['licence_price', 'deployment_quote'] as $field) {
            $value = str_replace([',', ' '], '', $form[$field]);
            if ($value === '') {
                $value = $field === 'licence_price' ? (string) (PLANS[$form['plan']]['price'] ?? 0) : '0';
            }
            if (!ctype_digit($value) || strlen($value) > 9) {
                $errors[$field] = 'Enter an amount in whole shillings.';
            }
            $form[$field] = $value;
        }
        foreach (['purchase_date', 'maintenance_due'] as $field) {
            if ($form[$field] !== '' && !valid_date($form[$field])) {
                $errors[$field] = 'Enter a valid date.';
            }
        }
        // A year of maintenance starts from the purchase date unless set by hand.
        if (!$errors && $form['maintenance_due'] === '' && $form['purchase_date'] !== '') {
            $form['maintenance_due'] = date('Y-m-d', strtotime($form['purchase_date'] . ' +1 year'));
        }
        foreach (['contact_name', 'location'] as $field) {
            if (mb_strlen($form[$field]) > 150) {
                $errors[$field] = 'Keep this under 150 characters.';
            }
        }
        if (mb_strlen($form['notes']) > 5000) {
            $errors['notes'] = 'Keep notes under 5,000 characters.';
        }

        if (!$errors) {
            $values = [
                $form['business'], $form['contact_name'], $form['phone'], $form['email'], $form['location'],
                $form['plan'], $form['status'], (int) $form['licence_price'], (int) $form['deployment_quote'],
                $form['purchase_date'] ?: null, $form['maintenance_due'] ?: null, $form['notes'], $form['download_enabled'],
            ];
            if ($client) {
                $values[] = $id;
                db()->prepare("UPDATE clients SET business = ?, contact_name = ?, phone = ?, email = ?, location = ?,
                    plan = ?, status = ?, licence_price = ?, deployment_quote = ?, purchase_date = ?, maintenance_due = ?,
                    notes = ?, download_enabled = ?, updated_at = datetime('now') WHERE id = ?")->execute($values);
                flash('Saved ' . $form['business'] . '.');
            } else {
                db()->prepare('INSERT INTO clients (business, contact_name, phone, email, location, plan, status,
                    licence_price, deployment_quote, purchase_date, maintenance_due, notes, download_enabled)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute($values);
                $id = (int) db()->lastInsertId();
                flash('Added ' . $form['business'] . '.');
            }
            redirect('client.php?id=' . $id);
        }
    }
}

start_session();
$newCode = null;
if (!empty($_SESSION['new_code']) && $_SESSION['new_code']['id'] === $id) {
    $newCode = $_SESSION['new_code']['code'];
    unset($_SESSION['new_code']);
}

$downloads = [];
if ($client) {
    $stmt = db()->prepare('SELECT d.downloaded_at, d.ip, r.version FROM downloads d LEFT JOIN releases r ON r.id = d.release_id
        WHERE d.client_id = ? ORDER BY d.downloaded_at DESC LIMIT 10');
    $stmt->execute([$id]);
    $downloads = $stmt->fetchAll();
}

function field_error(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<p class="form__error" style="display:block">' . e($errors[$field]) . '</p>' : '';
}

$title = $client ? $client['business'] : 'Add client';
admin_header($title, $admin, 'clients');
?>
<p class="admin-crumb"><a class="link link--gray" href="index.php"><i class="mdi mdi-arrow-left" aria-hidden="true"></i> Clients</a></p>
<div class="admin-head"><h1><?= e($title) ?></h1></div>

<?php if ($errors): ?><p class="form__status form__status--error" role="alert">Fix the highlighted fields and save again.</p><?php endif; ?>

<div class="row row--gap">
  <div class="col-8 col-t-12">
    <form class="card" method="post" action="client.php<?= $client ? '?id=' . (int) $id : '' ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <h2 class="admin-card-title">Details</h2>
      <div class="row">
        <div class="col-6 col-l-12 form__group<?= isset($errors['business']) ? ' has-error' : '' ?>">
          <label class="form__label" for="business">Business name</label>
          <input class="form__input" id="business" name="business" value="<?= e($form['business']) ?>" required maxlength="150">
          <?= field_error($errors, 'business') ?>
        </div>
        <div class="col-6 col-l-12 form__group<?= isset($errors['contact_name']) ? ' has-error' : '' ?>">
          <label class="form__label" for="contact_name">Contact person</label>
          <input class="form__input" id="contact_name" name="contact_name" value="<?= e($form['contact_name']) ?>" maxlength="150">
          <?= field_error($errors, 'contact_name') ?>
        </div>
        <div class="col-6 col-l-12 form__group<?= isset($errors['phone']) ? ' has-error' : '' ?>">
          <label class="form__label" for="phone">Phone</label>
          <input class="form__input" id="phone" name="phone" type="tel" value="<?= e($form['phone']) ?>" maxlength="20">
          <?= field_error($errors, 'phone') ?>
        </div>
        <div class="col-6 col-l-12 form__group<?= isset($errors['email']) ? ' has-error' : '' ?>">
          <label class="form__label" for="email">Email <small>(used to sign in to downloads)</small></label>
          <input class="form__input" id="email" name="email" type="email" value="<?= e($form['email']) ?>" maxlength="150">
          <?= field_error($errors, 'email') ?>
        </div>
        <div class="col-12 form__group<?= isset($errors['location']) ? ' has-error' : '' ?>">
          <label class="form__label" for="location">Location</label>
          <input class="form__input" id="location" name="location" value="<?= e($form['location']) ?>" maxlength="150" placeholder="Town or area">
          <?= field_error($errors, 'location') ?>
        </div>
      </div>

      <h2 class="admin-card-title">Plan and deployment</h2>
      <div class="row">
        <div class="col-6 col-l-12 form__group<?= isset($errors['plan']) ? ' has-error' : '' ?>">
          <label class="form__label" for="plan">Plan</label>
          <select class="form__input" id="plan" name="plan" data-plan-prices='<?= e(json_encode(array_map(fn($p) => $p['price'], PLANS))) ?>'>
            <?php foreach (PLANS as $key => $plan): ?>
              <option value="<?= e($key) ?>"<?= $form['plan'] === $key ? ' selected' : '' ?>><?= e($plan['label']) ?> (<?= e(kes($plan['price'])) ?>)</option>
            <?php endforeach; ?>
          </select>
          <?= field_error($errors, 'plan') ?>
        </div>
        <div class="col-6 col-l-12 form__group<?= isset($errors['status']) ? ' has-error' : '' ?>">
          <label class="form__label" for="status">Status</label>
          <select class="form__input" id="status" name="status">
            <?php foreach (DEPLOYMENT_STATUSES as $key => $label): ?>
              <option value="<?= e($key) ?>"<?= $form['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?= field_error($errors, 'status') ?>
        </div>
        <div class="col-6 col-l-12 form__group<?= isset($errors['licence_price']) ? ' has-error' : '' ?>">
          <label class="form__label" for="licence_price">Licence price (KES)</label>
          <input class="form__input" id="licence_price" name="licence_price" inputmode="numeric" value="<?= e($form['licence_price']) ?>">
          <?= field_error($errors, 'licence_price') ?>
        </div>
        <div class="col-6 col-l-12 form__group<?= isset($errors['deployment_quote']) ? ' has-error' : '' ?>">
          <label class="form__label" for="deployment_quote">Deployment quote (KES)</label>
          <input class="form__input" id="deployment_quote" name="deployment_quote" inputmode="numeric" value="<?= e($form['deployment_quote']) ?>">
          <?= field_error($errors, 'deployment_quote') ?>
        </div>
        <div class="col-6 col-l-12 form__group<?= isset($errors['purchase_date']) ? ' has-error' : '' ?>">
          <label class="form__label" for="purchase_date">Purchase date</label>
          <input class="form__input" id="purchase_date" name="purchase_date" type="date" value="<?= e($form['purchase_date']) ?>">
          <?= field_error($errors, 'purchase_date') ?>
        </div>
        <div class="col-6 col-l-12 form__group<?= isset($errors['maintenance_due']) ? ' has-error' : '' ?>">
          <label class="form__label" for="maintenance_due">Maintenance due <small>(empty = a year after purchase)</small></label>
          <input class="form__input" id="maintenance_due" name="maintenance_due" type="date" value="<?= e($form['maintenance_due']) ?>">
          <?= field_error($errors, 'maintenance_due') ?>
        </div>
        <div class="col-12 form__group<?= isset($errors['notes']) ? ' has-error' : '' ?>">
          <label class="form__label" for="notes">Notes</label>
          <textarea class="form__input" id="notes" name="notes" maxlength="5000" placeholder="Hardware, PC preparation, training dates..."><?= e($form['notes']) ?></textarea>
          <?= field_error($errors, 'notes') ?>
        </div>
        <div class="col-12 form__group">
          <label class="checkbox-btn">
            <input class="checkbox-btn__checkbox" type="checkbox" name="download_enabled"<?= $form['download_enabled'] ? ' checked' : '' ?>>
            <span class="checkbox-btn__checkbox-custom"><i class="mdi mdi-check" aria-hidden="true"></i></span>
            <span class="checkbox-btn__label">Allow this client to download the installer</span>
          </label>
        </div>
      </div>
      <button class="site-btn site-btn--accent" type="submit"><?= $client ? 'Save changes' : 'Add client' ?></button>
    </form>
  </div>

  <div class="col-4 col-t-12">
    <?php if ($client): ?>
      <div class="card card--flat admin-side">
        <h2 class="admin-card-title">Money</h2>
        <dl class="admin-dl">
          <dt>Licence</dt><dd><?= e(kes((int) $client['licence_price'])) ?></dd>
          <dt>Deployment quote</dt><dd><?= $client['deployment_quote'] ? e(kes((int) $client['deployment_quote'])) : 'Not quoted' ?></dd>
          <dt>Maintenance (33%)</dt><dd><?= e(kes(maintenance_fee((int) $client['licence_price']))) ?> / year</dd>
          <dt>Maintenance due</dt><dd><?= $client['maintenance_due'] ? e(date('j M Y', strtotime($client['maintenance_due']))) : 'Not set' ?></dd>
        </dl>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="renew">
          <button class="site-btn site-btn--light site-btn--small site-btn--block" type="submit"><i class="mdi mdi-calendar-refresh" aria-hidden="true"></i> Renew maintenance for a year</button>
        </form>
      </div>

      <div class="card card--flat admin-side">
        <h2 class="admin-card-title">Download access</h2>
        <?php if ($newCode): ?>
          <div class="admin-code" role="status">
            <p>New download code. Copy it now; it won't be shown again.</p>
            <code id="new-code"><?= e($newCode) ?></code>
            <p class="card__text">The client enters <strong><?= e($client['email']) ?></strong> and this code on the <a class="link link--accent-bold" href="../download.php" target="_blank">download page</a>.</p>
          </div>
        <?php endif; ?>
        <p class="card__text">
          <?php if ($client['download_code_hash'] && $client['download_enabled']): ?>
            Access is <strong>on</strong>. <?= (int) $client['download_count'] ?> download<?= $client['download_count'] == 1 ? '' : 's' ?><?= $client['last_download_at'] ? ', last on ' . e(date('j M Y', strtotime($client['last_download_at']))) : '' ?>.
          <?php elseif ($client['download_code_hash']): ?>
            The client has a code, but access is <strong>off</strong>. Tick "Allow this client to download" and save to turn it on.
          <?php else: ?>
            No download code yet.
          <?php endif; ?>
        </p>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="new_code">
          <button class="site-btn site-btn--accent site-btn--small site-btn--block" type="submit"><i class="mdi mdi-key-plus" aria-hidden="true"></i> <?= $client['download_code_hash'] ? 'Replace download code' : 'Create download code' ?></button>
        </form>
        <?php if ($client['download_code_hash']): ?>
          <form method="post" data-confirm="Remove this client's download code? Their current code will stop working.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="revoke">
            <button class="link link--gray admin-link-btn" type="submit">Remove download code</button>
          </form>
        <?php endif; ?>
        <?php if ($downloads): ?>
          <h3 class="admin-card-subtitle">Recent downloads</h3>
          <ul class="admin-list">
            <?php foreach ($downloads as $d): ?>
              <li><?= e(date('j M Y, H:i', strtotime($d['downloaded_at']))) ?> &middot; <?= e($d['version'] ?? 'deleted installer') ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <form class="admin-danger" method="post" data-confirm="Delete <?= e($client['business']) ?> and its download history? This can't be undone.">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <button class="link admin-link-btn admin-link-btn--danger" type="submit"><i class="mdi mdi-delete-outline" aria-hidden="true"></i> Delete client</button>
      </form>
    <?php else: ?>
      <div class="card card--flat admin-side">
        <h2 class="admin-card-title">After you add the client</h2>
        <p class="card__text">You can renew their maintenance and create the download code they use on the download page.</p>
      </div>
    <?php endif; ?>
  </div>
</div>
<script src="../assets/js/admin.js"></script>
<?php
admin_footer();
