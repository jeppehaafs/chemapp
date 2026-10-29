'use strict';
document.addEventListener('DOMContentLoaded', function () {
  const latitude = document.getElementById('show-latitude');
  const longitude = document.getElementById('show-longitude');
  const status = document.getElementById('admin-map-status');
  if (!latitude || !longitude) return;
  let map;
  let marker;
  function coordinates() {
    if (!latitude.value.trim() || !longitude.value.trim()) return null;
    const lat = Number(latitude.value);
    const lng = Number(longitude.value);
    return Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180 ? [lat, lng] : null;
  }
  function sync(pan) {
    const point = coordinates();
    if (!map) return;
    if (!point) {
      if (marker) map.removeLayer(marker);
      marker = null;
      return;
    }
    if (!marker) {
      marker = L.marker(point, {draggable: true}).addTo(map);
      marker.on('dragend', function () { choose(marker.getLatLng()); });
    } else {
      marker.setLatLng(point);
    }
    if (pan) map.panTo(point);
  }
  function choose(point) {
    // Leaflet wraps tiles horizontally. Store longitude in the database range.
    latitude.value = Math.max(-90, Math.min(90, point.lat)).toFixed(7);
    longitude.value = (((point.lng + 180) % 360 + 360) % 360 - 180).toFixed(7);
    sync(false);
    status.textContent = 'Location selected. Save the show to publish it.';
  }
  document.getElementById('clear-location').addEventListener('click', function () {
    latitude.value = '';
    longitude.value = '';
    sync(false);
    status.textContent = 'Location cleared. Save the show to publish this change.';
  });
  latitude.addEventListener('change', function () { sync(true); });
  longitude.addEventListener('change', function () { sync(true); });
  if (typeof L === 'undefined') {
    status.textContent = 'The map could not load. You can still enter coordinates or leave both fields empty.';
    return;
  }
  const initial = coordinates();
  map = L.map('admin-map').setView(initial || [52.1, 5.3], initial ? 12 : 7);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map).on('tileerror', function () {
    status.textContent = 'Some map tiles could not load. You can still enter coordinates below.';
  });
  map.on('click', function (event) { choose(event.latlng); });
  sync(false);
});
