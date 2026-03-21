<?php
/**
 * oref_cities.php — публичный AJAX endpoint для поиска городов
 */
chdir(dirname(__FILE__) . '/../');
include_once('./config.php');
include_once('./lib/loader.php');
include_once('./load_settings.php');
include_once(DIR_MODULES . 'oref_alert/lib/CityDb.php');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$q     = trim($_GET['q'] ?? '');
$limit = min(20, intval($_GET['limit'] ?? 15));
$op = trim($_GET['op'] ?? 'search');

if ($op === 'gps') {
    $lat = floatval($_GET['lat'] ?? 0);
    $lng = floatval($_GET['lng'] ?? 0);
    if (!$lat || !$lng) { echo json_encode(['error' => 'invalid coords']); exit; }
    
    $arr = json_decode(file_get_contents(DIR_MODULES . 'oref_alert/data/cities.json'), true) ?:[];
    $best = null; $bestDist = PHP_INT_MAX;
    foreach ($arr as $city) {
        $clat = floatval($city['lat'] ?? 0);
        $clng = floatval($city['lng'] ?? 0);
        if (!$clat || !$clng) continue;
        $d = sqrt(pow($lat-$clat,2) + pow($lng-$clng,2));
        if ($d < $bestDist) { $bestDist = $d; $best = $city; }
    }
    if (!$best) { echo json_encode(['error' => 'not found']); exit; }
    echo json_encode([
        'he'       => $best['name'],
        'ru'       => $best['name_ru'] ?? $best['name'],
        'countdown'=> intval($best['countdown'] ?? 45),
        'zone_ru'  => $best['zone_ru'] ?? '',
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(CityDb::search($q, $limit), JSON_UNESCAPED_UNICODE);
}