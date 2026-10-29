<div class="tour-tools">
  <nav class="tour-periods" aria-label="Choose tour dates">
    <a class="tour-period<?= $tourPeriod === 'upcoming' ? ' selected' : '' ?>" href="<?= e(page_url('map', ['period' => 'upcoming'])) ?>"<?= $tourPeriod === 'upcoming' ? ' aria-current="page"' : '' ?>>Upcoming shows</a>
    <a class="tour-period<?= $tourPeriod === 'past' ? ' selected' : '' ?>" href="<?= e(page_url('map', ['period' => 'past'])) ?>"<?= $tourPeriod === 'past' ? ' aria-current="page"' : '' ?>>Past shows</a>
  </nav>
  <a href="<?= e(page_url('manage')) ?>">Manage shows</a>
</div>
<?php if ($databaseError): ?>
  <p class="page-message" role="alert">Tour dates are temporarily unavailable. Please try again later.</p>
<?php endif; ?>
<div class="tour_box">
  <div class="list_box" id="list">
    <?php if (!$databaseError && $shows === []): ?>
      <p class="page-message"><?= $tourPeriod === 'past' ? 'No past shows to display yet.' : 'No upcoming shows have been announced yet. Check back soon.' ?></p>
    <?php endif; ?>
    <ul class="list-group">
      <?php foreach ($shows as $entry): ?>
        <li class="list-group-item show-item" data-lat="<?= e($entry['latitude'] ?? '') ?>" data-lng="<?= e($entry['longitude'] ?? '') ?>" data-id="<?= e($entry['id']) ?>" tabindex="0" role="button" aria-label="<?= e($entry['name']) ?>">
          <div class="show-info"><div class="show-details">
            <p class="date-display<?= $entry['date'] < $tourToday ? ' due-date' : '' ?>"><time datetime="<?= e($entry['date']) ?>"><?= e(date('d-m-Y', strtotime($entry['date']))) ?></time></p>
            <h4><?= e($entry['name']) ?></h4>
            <a href="<?= e(page_url('info', ['id' => $entry['id']])) ?>">Show details</a>
          </div></div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div id="map" class="map_box" data-marker-icon="<?= e(asset('guitar-pick-marker.png')) ?>" aria-label="Tour locations"></div>
</div>
<script type="application/json" id="shows-data"><?= json_encode($shows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<script src="<?= e(asset('map.js')) ?>" defer></script>
