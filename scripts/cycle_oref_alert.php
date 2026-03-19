<?php
/**
 * cycle_oref_alert.php
 *
 * Заменяет скрипт AlertSecond.
 * Логика: 3 источника API (как amitfin/oref_alert HA) + логика 2/8 сек (как AlertSecond).
 * Заполняет свойства объекта Alert.* (совместимо с виджетом Warning).
 * Вызывает Alert.Trigger (или настроенный метод).
 * Выполняет кастомный PHP-код из настроек модуля.
 */

chdir(dirname(__FILE__) . '/../');
include_once('./config.php');
include_once('./lib/loader.php');
include_once('./lib/threads.php');
set_time_limit(0);
// connecting to database (required for setGlobal/getGlobal)
$db = new mysql(DB_HOST, '', DB_USER, DB_PASSWORD, DB_NAME);
include_once('./load_settings.php');
include_once(DIR_MODULES . 'oref_alert/oref_alert.class.php');

// ─── Логирование ──────────────────────────────────────────────────────────────
function oaLog($msg, $level = 'INFO')
{
    $line = date('[Y-m-d H:i:s]') . " [{$level}] {$msg}";
    // DebMes только для ERROR/FATAL или если включено логирование
    $debug = intval(getGlobal('oref_alert.debug_log'));
    if ($debug || in_array($level, ['ERROR', 'FATAL', 'WARN'])) {
        DebMes("OrefAlert | {$msg}", 'oref_alert');
    }
    // Файловый лог — всегда (для диагностики через data/cycle.log)
    @file_put_contents(DIR_MODULES . 'oref_alert/data/cycle.log',
        $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// ─── Инициализация ────────────────────────────────────────────────────────────
set_time_limit(0);
$CYCLE   = str_replace('.php', '', basename(__FILE__)); // = 'cycle_oref_alert'
$STARTED = time();
$MAX_RT  = 2 * 3600;

oaLog("=== СТАРТ pid=" . getmypid() . " PHP=" . PHP_VERSION . " ===");
setGlobal($CYCLE . 'Run', $STARTED, 1);
setGlobal($CYCLE . 'Status', 'starting');

try {
    $oref = new oref_alert();
    $cfg  = $oref->getConfig();
    oaLog("Инициализация OK. obj={$cfg['OBJ']} trigger={$cfg['OBJ']}.{$cfg['TRIGGER']}");
} catch (Throwable $e) {
    oaLog("КРАШ init: " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine(), 'FATAL');
    setGlobal($CYCLE . 'Status', 'crashed_init');
    exit(1);
}

$watchdog     = time();
$iteration    = 0;
$errCount     = 0;
$lastHistoryUpdate = 0;

setGlobal($CYCLE . 'Status', 'running');

// ─── Главный цикл ─────────────────────────────────────────────────────────────
while (true) {
    $iteration++;

    // Watchdog — обновляем ключ Run чтобы xray видел цикл живым
    if (time() - $watchdog >= 5) {
        setGlobal($CYCLE . 'Run', time(), 1);
        $watchdog = time();
        $cfg = $oref->getConfig();  // Перечитываем конфиг без перезапуска цикла
    }

    $cmd = getGlobal($CYCLE . 'Control');
    if ($cmd === 'stop' || $cmd === 'restart') {
        setGlobal($CYCLE . 'Control', '');
        oaLog("CMD={$cmd} — выходим.");
        break;
    }
    if (time() - $STARTED > $MAX_RT) {
        oaLog("MAX_RUNTIME — выходим для перезапуска.");
        break;
    }
    if (!$cfg['ENABLED']) {
        if ($iteration % 60 === 1) oaLog("Модуль отключён.");
        sleep(10);
        continue;
    }

    $obj = $cfg['OBJ'];

    try {
        // ── 1. ИСТОРИЯ (раз в N секунд) ───────────────────────────────────
        if ((time() - $lastHistoryUpdate) > $cfg['HIST_INT']) {
            $histData = oaFetchHistory($oref, $cfg);
            $histString = implode(', ', array_unique(array_column($histData, 'area')));
            setGlobal($obj . '.History', mb_substr($histString, 0, 250, 'UTF-8'));
            $lastHistoryUpdate = time();
            oaLog("[#{$iteration}] История обновлена: " . count($histData) . " записей за час");
        }

        // ── 2. REALTIME ───────────────────────────────────────────────────
        $realtimeRaw = $oref->httpGet('https://www.oref.org.il/warningMessages/alert/Alerts.json', $cfg['PROXY']);

        // Логика 2/8: если данные изменились → 2 сек, иначе → 8 сек
        $currentHash = md5($realtimeRaw ?: '');
        $lastHash    = getGlobal($obj . '.LastDataHash');
        $dataChanged = ($currentHash !== $lastHash);
        if ($dataChanged) setGlobal($obj . '.LastDataHash', $currentHash);

        setGlobal($obj . '.UpTime', date('Y-m-d H:i:s'));

        // ── 3. РАЗБОР REALTIME ────────────────────────────────────────────
        $alertFound = false;
        $foundData  = [];

        if ($realtimeRaw) {
            $json = json_decode($realtimeRaw, true);
            if (is_array($json) && !empty($json['data'])) {
                $rtCat = intval($json['cat'] ?? 1);
                $title = $json['title'] ?? '';
                $histCat = $oref->rtToHistCat($rtCat, $title);
                $state   = $oref->histCatToState($histCat);

                if ($state !== null) {  // не учения
                    $filterWords = array_filter(array_map('trim', explode(',', $cfg['FILTER'])));
                    foreach ($json['data'] as $city) {
                        if (empty($filterWords)) {
                            // GPS-режим
                            $alertFound = true;
                            $foundData = ['histCat'=>$histCat, 'state'=>$state, 'city'=>$city, 'title'=>$title];
                            break;
                        }
                        foreach ($filterWords as $word) {
                            if ($word && mb_stripos($city, $word, 0, 'UTF-8') !== false) {
                                $alertFound = true;
                                $foundData = ['histCat'=>$histCat, 'state'=>$state, 'city'=>$city, 'title'=>$title];
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        oaLog("[#{$iteration}] realtime=" . ($realtimeRaw ? 'OK' : 'empty') .
              " changed=" . ($dataChanged?'Y':'N') .
              " found=" . ($alertFound?$foundData['city']:'no'));

        // ── 4. ДЕЙСТВИЯ ───────────────────────────────────────────────────
        if ($alertFound) {
            $hc      = $foundData['histCat'];
            $state   = $foundData['state'];
            $city    = $foundData['city'];
            $catSet  = $oref->getCatSettings($hc);

            // CityID, русское название и данные для карты из cities.json
            $cityInfo = $oref->getCityInfo($city);
            $cityId   = $cityInfo ? $cityInfo['id']      : '';
            $cityRu   = $cityInfo ? $cityInfo['name_ru'] : $city;
            $zoneRu   = $cityInfo ? $cityInfo['zone_ru'] : '';
            $mapData  = $oref->getMapData($city);  // JSON с полигоном для Leaflet

            // Заполняем свойства объекта (совместимо с виджетом Warning)
            setGlobal($obj . '.Category',     $hc);
            setGlobal($obj . '.Img',          $catSet['img']);
            setGlobal($obj . '.Name',         $catSet['name']);
            setGlobal($obj . '.Instructions', $catSet['instr']);
            setGlobal($obj . '.City',         $city);         // иврит (для API)
            setGlobal($obj . '.CityRu',       $cityRu);       // русское название
            setGlobal($obj . '.ZoneRu',       $zoneRu);       // русский округ
            setGlobal($obj . '.CityID',       $cityId);       // для карты/полигона
            setGlobal($obj . '.MapData',      $mapData ?: ''); // JSON для Leaflet popup
            setGlobal($obj . '.ShelterTime',  $cityInfo ? $cityInfo['countdown'] : AreaData::getMigunTime($city));
            setGlobal($obj . '.MyActiveAreas', $city);

            oaLog("[#{$iteration}] " . strtoupper($state) . " | cat={$hc} | city={$city} | shelter=" . AreaData::getMigunTime($city) . "s");

            if ($state === 'pre_alert') {
                // Предварительное или отбой с "בדקות"
                setGlobal($obj . '.Status', 'No Alert');
                callMethod($obj . '.' . $cfg['TRIGGER']);
                oaRunCustomCode('pre_alert', $cfg, $obj, $city, $hc);

            } elseif ($state === 'no_alert') {
                // Отбой (cat=13)
                setGlobal($obj . '.Status', 'No Alert');
                callMethod($obj . '.' . $cfg['TRIGGER']);
                oaRunCustomCode('clear', $cfg, $obj, $city, $hc);

            } else {
                // Боевая тревога — только новая (счётчик)
                $prevStatus = getGlobal($obj . '.Status');
                if ($prevStatus !== 'Alert') {
                    setGlobal($obj . '.Count',        intval(getGlobal($obj . '.Count')) + 1);
                    setGlobal($obj . '.LastAlarmTime', date('Y-m-d H:i:s'));
                    setGlobal($obj . '.LastAlertTS',   time());
                    $oref->appendHistory('alert', $hc, [$city], [$city]);
                    oaLog("[#{$iteration}] НОВАЯ ТРЕВОГА — вызываем " . $obj . '.' . $cfg['TRIGGER']);
                }
                setGlobal($obj . '.Status', 'Alert');
                callMethod($obj . '.' . $cfg['TRIGGER']);
                oaRunCustomCode('alert', $cfg, $obj, $city, $hc);
            }

        } else {
            // ── ТИШИНА ────────────────────────────────────────────────────
            $lastAlarmStr = getGlobal($obj . '.LastAlarmTime');
            $lastAlarmTS  = $lastAlarmStr ? strtotime($lastAlarmStr) : 0;
            $timePassed   = $lastAlarmTS ? (time() - $lastAlarmTS) : PHP_INT_MAX;

            if (getGlobal($obj . '.Status') === 'Alert') {
                // Правило 10 минут: нет данных, но статус Alert → переводим в No Alert
                if ($timePassed > 600) {
                    oaLog("[#{$iteration}] 10 мин прошло — автоотбой");
                    setGlobal($obj . '.Status', 'No Alert');
                    $oref->appendHistory('auto_clear', 0, [], []);
                    callMethod($obj . '.' . $cfg['TRIGGER']);
                    oaRunCustomCode('clear', $cfg, $obj, '', 0);
                }
            }

            // Правило 1 час: очищаем интерфейс
            if ($timePassed > 3600 && getGlobal($obj . '.City') !== '') {
                setGlobal($obj . '.Category',      '');
                setGlobal($obj . '.Img',           '/cms/icons/default.png');
                setGlobal($obj . '.Name',          '');
                setGlobal($obj . '.Instructions',  'Оповещений нет');
                setGlobal($obj . '.City',          '');
                setGlobal($obj . '.CityID',        '');
                setGlobal($obj . '.ShelterTime',   0);
                setGlobal($obj . '.MyActiveAreas', '');
                setGlobal($obj . '.Last14Sounded', 0);
            }
        }

        // Статус цикла
        $st = getGlobal($obj . '.Status');
        setGlobal($CYCLE . 'Status', "ok #{$iteration} " . date('H:i:s') . " {$st}");
        $errCount = 0;

    } catch (Throwable $e) {
        $errCount++;
        oaLog("[#{$iteration}] ИСКЛЮЧЕНИЕ: " . $e->getMessage()
            . " @ " . $e->getFile() . ":" . $e->getLine(), 'ERROR');
        setGlobal($CYCLE . 'Status', "error: " . $e->getMessage());
        if ($errCount >= 10) { oaLog("10 ошибок — выход.", 'FATAL'); break; }
        sleep(8);
        continue;
    }

    // ── Интервал: логика 2/8 (как AlertSecond) ────────────────────────────
    $currentStatus = getGlobal($obj . '.Status');
    if ($currentStatus === 'Alert') {
        sleep(2);        // При активной тревоге — всегда 2 сек
    } elseif ($dataChanged) {
        sleep(2);        // Данные изменились — быстро
    } else {
        sleep(max(2, $cfg['POLL']));  // Данные те же — стандартный интервал
    }
}

oaLog("=== ВЫХОД pid=" . getmypid() . " iter={$iteration} uptime=" . (time()-$STARTED) . "s ===");
setGlobal($CYCLE . 'Status', 'stopped');

// ═══════════════════════════════════════════════════════════════════════════════
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Загрузить историю из двух History URL
 * Возвращает массив ['area'=>, 'cat'=>, 'date'=>] за последний час
 */
function oaFetchHistory($oref, $cfg)
{
    $urls = [
        'https://www.oref.org.il/warningMessages/alert/History/AlertsHistory.json',
        'https://alerts-history.oref.org.il/Shared/Ajax/GetAlarmsHistory.aspx?lang=he&mode=1',
    ];
    $result = [];
    $seen   = [];
    $now    = time();

    foreach ($urls as $url) {
        $raw = $oref->httpGet($url, $cfg['PROXY']);
        if (!$raw) continue;
        $data = json_decode($raw, true);
        if (!is_array($data)) continue;

        foreach ($data as $rec) {
            $area    = trim($rec['data'] ?? '');
            $cat     = intval($rec['category'] ?? 1);
            $dateStr = str_replace('T', ' ', $rec['alertDate'] ?? $rec['date'] ?? '');
            $ts      = $dateStr ? strtotime($dateStr) : 0;

            if (!$area || ($now - $ts) > 3600) continue;  // только за последний час

            $state = $oref->histCatToState($cat);
            if ($state === null || $state === 'no_alert') continue;  // пропускаем учения и отбой

            $key = $area . '_' . $cat;
            if (!isset($seen[$key])) {
                $seen[$key]  = true;
                $result[]    = ['area'=>$area, 'cat'=>$cat, 'date'=>$dateStr];
            }
        }
    }
    return $result;
}

/**
 * Выполнить кастомный PHP-код из настроек модуля
 * Переменные доступны внутри кода: $obj, $city, $histCat, $cfg
 */
function oaRunCustomCode($event, $cfg, $obj, $city, $histCat)
{
    $codeMap = [
        'alert'     => getGlobal('oref_alert.code_alert'),
        'pre_alert' => getGlobal('oref_alert.code_pre_alert'),
        'clear'     => getGlobal('oref_alert.code_clear'),
        'drill'     => getGlobal('oref_alert.code_drill'),
    ];
    $code = trim($codeMap[$event] ?? '');
    if (!$code) return;
    oaLog("Выполняем custom code для event={$event}");
    try {
        eval($code);
    } catch (Throwable $e) {
        oaLog("Ошибка в custom code ({$event}): " . $e->getMessage(), 'ERROR');
    }
}
