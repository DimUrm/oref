<?php
class CityDb
{
    private static $cities   = null;
    private static $polygons = null;
    private static $byId     = null;

    private static function load()
    {
        if (self::$cities !== null) return;
        $dir = DIR_MODULES . 'oref_alert/data/';

        $raw = @file_get_contents($dir . 'cities.json');
        $arr = $raw ? json_decode($raw, true) : [];
        self::$cities =[];
        self::$byId   = [];
        foreach ((array)$arr as $c) {
            $he = $c['name'] ?? '';
            if (!$he || $he === 'בחר הכל') continue;
            self::$cities[$he] = $c;
            self::$byId[intval($c['id'] ?? 0)] = $c;
        }

        $raw2 = @file_get_contents($dir . 'polygons.json');
        self::$polygons = $raw2 ? (json_decode($raw2, true) ?: []) :[];
    }

    public static function findByName($nameHe)
    {
        self::load();
        $nameHe = trim($nameHe);
        if (isset(self::$cities[$nameHe])) return self::normalize(self::$cities[$nameHe]);
        foreach (self::$cities as $he => $c) {
            if (mb_strtolower($he, 'UTF-8') === mb_strtolower($nameHe, 'UTF-8')) return self::normalize($c);
        }
        return null;
    }

    public static function getPolygon($cityId)
    {
        self::load();
        $key = (string)intval($cityId);
        return self::$polygons[$key] ?? null;
    }

    public static function getPolygonByName($nameHe)
    {
        $city = self::findByName($nameHe);
        if (!$city) return null;
        return self::getPolygon($city['id']);
    }

    public static function getMapData($nameHe)
    {
        self::load();
        $city = self::findByName($nameHe);
        if (!$city) return null;
        $polygon = self::getPolygon($city['id']);
        return json_encode([
            'id'       => $city['id'],
            'name_he'  => $city['name_he'],
            'name_ru'  => $city['name_ru'],
            'name_en'  => $city['name_en'] ?? '',
            'zone_ru'  => $city['zone_ru'],
            'lat'      => $city['lat'],
            'lng'      => $city['lng'],
            'countdown'=> $city['countdown'],
            'polygon'  => $polygon,
        ], JSON_UNESCAPED_UNICODE);
    }

    public static function search($query, $limit = 15)
    {
        self::load();
        if (mb_strlen($query, 'UTF-8') < 2) return[];
        $q       = mb_strtolower($query, 'UTF-8');
        $results =[];
        foreach (self::$cities as $he => $c) {
            $ru = mb_strtolower($c['name_ru'] ?? '', 'UTF-8');
            $en = mb_strtolower($c['name_en'] ?? '', 'UTF-8');
            if (mb_strpos($he, $q, 0, 'UTF-8') !== false
                || mb_strpos($ru, $q, 0, 'UTF-8') !== false
                || mb_strpos($en, $q, 0, 'UTF-8') !== false) {
                $results[] =[
                    'he'       => $he,
                    'ru'       => $c['name_ru'] ?? $he,
                    'en'       => $c['name_en'] ?? '',
                    'countdown'=> intval($c['countdown'] ?? 45),
                    'zone_ru'  => $c['zone_ru'] ?? '',
                ];
                if (count($results) >= $limit) break;
            }
        }
        return $results;
    }

    private static function normalize($c)
    {
        return [
            'id'       => intval($c['id'] ?? 0),
            'name_he'  => $c['name']    ?? '',
            'name_ru'  => $c['name_ru'] ?? ($c['name_en'] ?? $c['name']),
            'name_en'  => $c['name_en'] ?? '',
            'zone_ru'  => $c['zone_ru'] ?? ($c['zone_en'] ?? $c['zone'] ?? ''),
            'lat'      => floatval($c['lat'] ?? 0),
            'lng'      => floatval($c['lng'] ?? 0),
            'countdown'=> intval($c['countdown'] ?? 45),
            'value'    => $c['value']   ?? $c['name'],
        ];
    }
}