'use strict';
document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('map');
    if (!container) return;
    if (typeof L === 'undefined' || typeof L.markerClusterGroup !== 'function') {
        const message = document.createElement('p');
        message.className = 'page-message';
        message.textContent = 'The map could not load. You can still browse the tour dates.';
        container.appendChild(message);
        return;
    }
    const map = L.map(container).setView([52.022728, 6.421853], 6);
    window.chemappMap = map;
    const dark = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
        subdomains: 'abcd', maxZoom: 20
    });
    const streets = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors', maxZoom: 19
    });
    const markers = L.markerClusterGroup();
    const markerById = new Map();
    const showIcon = L.icon({
        iconUrl: container.dataset.markerIcon,
        iconSize: [36, 42],
        // Anchor the visible pick tip; the image has a small transparent margin.
        iconAnchor: [18, 40],
        popupAnchor: [0, -38]
    });
    const shows = JSON.parse(document.getElementById('shows-data').textContent);
    function safeUrl(value) {
        try {
            const url = new URL(value);
            return ['http:', 'https:'].includes(url.protocol) ? url.href : '';
        } catch (_) { return ''; }
    }
    shows.forEach(show => {
        if (show.latitude === null || show.longitude === null) return;
        if (!Number.isFinite(show.latitude) || !Number.isFinite(show.longitude)) return;
        const popup = document.createElement('div');
        const name = document.createElement('strong');
        name.textContent = show.name;
        const date = document.createElement('p');
        date.textContent = show.date;
        popup.append(name, date);
        const ticket = safeUrl(show.link_ticket);
        if (ticket) {
            const link = document.createElement('a');
            link.href = ticket;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = 'Buy tickets';
            popup.append(link);
        }
        const marker = L.marker([show.latitude, show.longitude], {icon: showIcon, title: show.name, alt: show.name}).bindPopup(popup);
        markerById.set(String(show.id), marker);
        markers.addLayer(marker);
    });
    let active = null;
    document.querySelectorAll('.show-item').forEach(item => {
        function activate() {
            if (active) active.classList.remove('active');
            item.classList.add('active');
            active = item;
            const marker = markerById.get(item.dataset.id);
            if (!marker) return;
            markers.zoomToShowLayer(marker, () => {
                map.setView(marker.getLatLng(), 17);
                marker.openPopup();
            });
        }
        item.addEventListener('click', event => {
            if (!event.target.closest('a')) activate();
        });
        item.addEventListener('keydown', event => {
            if (event.target === item && ['Enter', ' '].includes(event.key)) {
                event.preventDefault();
                activate();
            }
        });
    });
    L.control.layers({CartoDB_DarkMatter: dark, OpenStreetMap: streets}, {Markers: markers}).addTo(map);
    dark.addTo(map);
    markers.addTo(map);
    map.on('zoomend', () => {
        if (map.getZoom() > 15 && map.hasLayer(dark)) {
            map.removeLayer(dark);
            streets.addTo(map);
        }
    });
});
