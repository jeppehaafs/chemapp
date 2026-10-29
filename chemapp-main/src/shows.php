<?php
declare(strict_types=1);

function text_field(array $data, string $key, int $max, bool $required = false): string
{
    $value = $data[$key] ?? '';
    if (!is_string($value)) {
        throw new InvalidArgumentException($key . ' must be a string.');
    }
    $value = trim($value);
    $length = preg_match_all('/./us', $value);
    if ($length === false || $length > $max || ($required && $value === '')) {
        throw new InvalidArgumentException($key . ' is required and/or exceeds its maximum length of ' . $max . ' characters.');
    }
    return $value;
}

function http_url(mixed $value): string
{
    if (!is_string($value) || $value === '' || filter_var($value, FILTER_VALIDATE_URL) === false) {
        return '';
    }
    return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true) ? $value : '';
}

/** Accept legacy GeoJSON Point (longitude first), or explicit latitude/longitude. */
function validate_show(array $data): array
{
    $name = text_field($data, 'name', 100, true);
    $date = text_field($data, 'date', 100, true);
    if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/D', $date, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
        throw new InvalidArgumentException('date must be a real date in YYYY-MM-DD format.');
    }
    $date = sprintf('%04d-%02d-%02d', (int) $parts[1], (int) $parts[2], (int) $parts[3]);
    if ((int) $parts[1] < 1000) {
        throw new InvalidArgumentException('date must be in the MySQL DATE range (1000-9999).');
    }
    $lat = $data['latitude'] ?? null;
    $lon = $data['longitude'] ?? null;
    if (isset($data['geometry'])) {
        $g = $data['geometry'];
        if (!is_array($g) || ($g['type'] ?? '') !== 'Point' || !isset($g['coordinates']) || !is_array($g['coordinates']) || !array_is_list($g['coordinates']) || count($g['coordinates']) !== 2) {
            throw new InvalidArgumentException('geometry must be a GeoJSON Point with [longitude, latitude].');
        }
        [$lon, $lat] = $g['coordinates'];
    }
    $lat = $lat === '' ? null : $lat;
    $lon = $lon === '' ? null : $lon;
    if ($lat !== null || $lon !== null) {
        foreach (['latitude' => [$lat, 90], 'longitude' => [$lon, 180]] as $key => [$value, $limit]) {
            if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value) || !is_finite((float) $value) || abs((float) $value) > $limit) {
                throw new InvalidArgumentException($key . ' is missing or outside the valid range.');
            }
        }
        $lat = (float) $lat;
        $lon = (float) $lon;
    }
    $links = [];
    foreach (['link_ticket', 'link_img'] as $key) {
        $value = text_field($data, $key, 255);
        if ($value !== '' && http_url($value) === '') {
            throw new InvalidArgumentException($key . ' must be an http(s) URL or empty.');
        }
        $links[$key] = $value;
    }
    return ['name' => $name, 'date' => $date, 'latitude' => $lat, 'longitude' => $lon] + $links;
}

function public_show(array $row): array
{
    $row['id'] = (int) $row['id'];
    $row['latitude'] = $row['latitude'] === null ? null : (float) $row['latitude'];
    $row['longitude'] = $row['longitude'] === null ? null : (float) $row['longitude'];
    $row['geometry'] = $row['latitude'] === null ? null : ['type' => 'Point', 'coordinates' => [$row['longitude'], $row['latitude']]];
    return $row;
}

function all_shows(PDO $pdo): array
{
    return array_map('public_show', $pdo->query('SELECT id, name, date, latitude, longitude, link_ticket, link_img FROM shows ORDER BY date DESC, id DESC')->fetchAll());
}

function find_show(PDO $pdo, int $id): ?array
{
    $query = $pdo->prepare('SELECT id, name, date, latitude, longitude, link_ticket, link_img FROM shows WHERE id = ?');
    $query->execute([$id]);
    $row = $query->fetch();
    return $row === false ? null : public_show($row);
}

function insert_show(PDO $pdo, array $show, ?int $id = null): int
{
    $columns = ['name', 'date', 'latitude', 'longitude', 'link_ticket', 'link_img'];
    $values = array_map(fn ($key) => $show[$key], $columns);
    if ($id !== null) {
        array_unshift($columns, 'id');
        array_unshift($values, $id);
    }
    $query = $pdo->prepare('INSERT INTO shows (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
    $query->execute($values);
    return $id ?? (int) $pdo->lastInsertId();
}

function update_show(PDO $pdo, int $id, array $show): void
{
    $query = $pdo->prepare('UPDATE shows SET name = ?, date = ?, latitude = ?, longitude = ?, link_ticket = ?, link_img = ? WHERE id = ?');
    $query->execute([$show['name'], $show['date'], $show['latitude'], $show['longitude'], $show['link_ticket'], $show['link_img'], $id]);
}

function delete_show(PDO $pdo, int $id): bool
{
    $query = $pdo->prepare('DELETE FROM shows WHERE id = ?');
    $query->execute([$id]);
    return $query->rowCount() === 1;
}
