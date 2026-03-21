<?php
class AreaData
{
    const MIGUN_TIME =[
    'אבו גוש' => 90, 'אבו סנאן' => 30, 'אבו קרינאת' => 90, 'אבו תלול' => 90, 'אבטליון' => 60,
    // ... здесь огромный массив городов, который был в вашем исходнике ...
    'תעוז' => 90, 'תעשיון צריפין' => 90, 'תפרח' => 45, 'תקומה' => 15, 'תקוע' => 90, 'תרום' => 90,
    ];

    public static function getMigunTime($areaHe) {
        return isset(self::MIGUN_TIME[$areaHe]) ? self::MIGUN_TIME[$areaHe] : 45;
    }

    public static function getMinMigunTime(array $areas) {
        if (empty($areas)) return 45;
        $min = PHP_INT_MAX;
        foreach ($areas as $area) {
            $t = self::getMigunTime($area);
            if ($t < $min) $min = $t;
        }
        return $min === PHP_INT_MAX ? 45 : $min;
    }

    public static function searchAreas($query, $limit = 15) {
        if (mb_strlen($query, 'UTF-8') < 2) return [];
        $results =[];
        foreach (self::MIGUN_TIME as $he => $t) {
            if (mb_stripos($he, $query, 0, 'UTF-8') !== false) {
                $results[] =['he' => $he, 'countdown' => $t];
                if (count($results) >= $limit) break;
            }
        }
        return $results;
    }
}