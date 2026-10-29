<link rel="stylesheet" href="<?= e(asset('admin.css')) ?>">
<section class="show-manager" aria-labelledby="manager-title">
  <div class="manager-heading">
    <div><p class="manager-eyebrow">Chemical Clouds · Admin</p><h1 id="manager-title">Manage shows</h1></div>
    <div class="manager-actions">
      <a href="<?= e(page_url('map')) ?>">View Tour Dates</a>
      <?php if ($authenticated): ?>
        <form method="post" action="<?= e(page_url('manage')) ?>">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
          <button type="submit" name="action" value="logout" class="manager-button secondary">Log out</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($error !== ''): ?><p class="manager-alert error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <?php if ($success !== ''): ?><p class="manager-alert success" role="status"><?= e($success) ?></p><?php endif; ?>

  <?php if (!$authenticated): ?>
    <div class="manager-card manager-login">
      <?php if ($setup && !$setupAllowed): ?>
        <h2>Admin setup required</h2>
        <p>Set the admin password from a private terminal with <code>php bin/set-admin-password.php</code>, or set it up while running the site locally. See the setup guide in the README.</p>
      <?php else: ?>
        <h2><?= $setup ? 'Choose your admin password' : 'Log in' ?></h2>
        <p><?= $setup ? 'Use this password to add and edit tour dates. It is separate from your MySQL password.' : 'Enter your show manager password to continue.' ?></p>
        <form method="post" action="<?= e(page_url('manage')) ?>">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
          <input type="hidden" name="action" value="<?= $setup ? 'setup' : 'login' ?>">
          <label for="admin-password">Password</label>
          <input id="admin-password" type="password" name="password" required <?= $setup ? 'minlength="12"' : '' ?> autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>" autofocus>
          <?php if ($setup): ?>
            <p class="manager-hint">Use 12–72 bytes (for example, a long passphrase).</p>
            <label for="admin-confirm">Confirm password</label>
            <input id="admin-confirm" type="password" name="password_confirm" required minlength="12" autocomplete="new-password">
          <?php endif; ?>
          <button type="submit" class="manager-button"><?= $setup ? 'Set password and continue' : 'Log in' ?></button>
        </form>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="manager-grid">
      <aside class="manager-card manager-shows" aria-label="Existing shows">
        <div class="manager-list-heading"><h2>Your shows</h2><a class="manager-button secondary" href="<?= e(page_url('manage')) ?>">+ Add show</a></div>
        <?php if ($shows === []): ?><p>No shows yet. Add your first one using the form.</p><?php endif; ?>
        <ul>
          <?php foreach ($shows as $entry): ?>
            <li>
              <a href="<?= e(page_url('manage', ['id' => $entry['id']])) ?>" <?= (string) $form['id'] === (string) $entry['id'] ? 'aria-current="true"' : '' ?>>
                <time datetime="<?= e($entry['date']) ?>"><?= e(date('d M Y', strtotime($entry['date']))) ?></time>
                <strong><?= e($entry['name']) ?></strong><span>Edit show →</span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </aside>
      <div class="manager-card">
        <?php if ($deleteConfirmation): ?>
        <h2>Delete this show?</h2>
        <p><strong><?= e($form['name']) ?></strong> · <time datetime="<?= e($form['date']) ?>"><?= e(date('d M Y', strtotime($form['date']))) ?></time></p>
        <p>This permanently removes the show from Tour Dates and the map. This cannot be undone.</p>
        <form method="post" action="<?= e(page_url('manage')) ?>" id="delete-show-form">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= e($form['id']) ?>">
          <label class="manager-delete-confirm"><input type="checkbox" name="confirm_delete" value="<?= e($form['id']) ?>" required> I want to permanently delete this show.</label>
          <div class="manager-save"><button type="submit" class="manager-button danger">Delete show permanently</button><a href="<?= e(page_url('manage', ['id' => $form['id']])) ?>">Cancel</a></div>
        </form>
        <?php else: ?>
        <h2><?= $form['id'] === '' ? 'Add a show' : 'Edit show' ?></h2>
        <form method="post" action="<?= e(page_url('manage')) ?>" id="show-form">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= e($form['id']) ?>">
          <label for="show-name">Show or venue name <span class="manager-hint">(required)</span></label>
          <input id="show-name" name="name" required maxlength="100" value="<?= e($form['name']) ?>" placeholder="For example: Summer Festival — Amsterdam">
          <label for="show-date">Date <span class="manager-hint">(required)</span></label>
          <input id="show-date" type="date" name="date" required min="1000-01-01" max="9999-12-31" value="<?= e($form['date']) ?>">
          <label for="show-ticket">Ticket link <span class="manager-hint">(optional)</span></label>
          <input id="show-ticket" type="url" name="link_ticket" maxlength="255" placeholder="https://…" value="<?= e($form['link_ticket']) ?>">
          <label for="show-poster">Poster image link <span class="manager-hint">(optional)</span></label>
          <input id="show-poster" type="url" name="link_img" maxlength="255" placeholder="https://…/poster.jpg" value="<?= e($form['link_img']) ?>">
          <h3>Map location <span class="manager-hint">(optional)</span></h3>
          <p id="location-help">Click the map to place the show, or enter latitude and longitude below. Drag the marker to adjust it. Leave both fields empty for a show without a map marker.</p>
          <div id="admin-map" aria-label="Choose a show location" aria-describedby="location-help"></div>
          <p id="admin-map-status" class="manager-hint" role="status"></p>
          <div class="manager-coordinates">
            <div><label for="show-latitude">Latitude</label><input id="show-latitude" name="latitude" type="number" min="-90" max="90" step="any" value="<?= e($form['latitude']) ?>"></div>
            <div><label for="show-longitude">Longitude</label><input id="show-longitude" name="longitude" type="number" min="-180" max="180" step="any" value="<?= e($form['longitude']) ?>"></div>
          </div>
          <button type="button" id="clear-location" class="manager-button secondary">Clear location</button>
          <div class="manager-save"><button type="submit" name="action" value="save" class="manager-button"><?= $form['id'] === '' ? 'Add show' : 'Save changes' ?></button><a href="<?= e(page_url('map')) ?>">Back to Tour Dates</a></div>
        </form>
        <?php if ($form['id'] !== ''): ?>
          <div class="manager-delete"><a class="manager-button danger" href="<?= e(page_url('manage', ['id' => $form['id'], 'delete' => 1])) ?>">Delete show…</a></div>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <script src="<?= e(asset('admin-map.js')) ?>" defer></script>
  <?php endif; ?>
</section>
