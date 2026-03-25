<?php
/**
 * cycle_oref_alert.php
 */

chdir(dirname(__FILE__) . '/../');
include_once('./config.php');
include_once('./lib/loader.php');
include_once('./lib/threads.php');
set_time_limit(0);

$db = new mysql(DB_HOST, '', DB_USER, DB_PASSWORD, DB_NAME);
include_once('./load_settings.php');
include_once(DIR_MODULES . 'oref_alert/oref_alert.class.php');

function oaLog($msg, $level = 'INFO') {
    global $cfg; 
    $debug = isset($cfg['DEBUG_LOG']) ? intval($cfg['DEBUG_LOG']) : 1;
    if ($debug || in_array($level,['ERROR', 'FATAL'])) {
        DebMes("OrefAlert | {$msg}", 'oref_alert');
    }
}

register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'],[E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $msg = 'FATAL: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line'];
        DebMes($msg, 'oref_alert_crash');
    }
});

$CYCLE   = str_replace('.php', '', basename(__FILE__));
$STARTED = time();
$MAX_RT  = 2 * 3600;

try {
    $oref = new oref_alert();
    $cfg  = $oref->getConfig();
} catch (Throwable $e) {
    setGlobal($CYCLE . 'Status', 'crashed_init');
    exit(1);
}

oaLog("=== СТАРТ pid=" . getmypid() . " PHP=" . PHP_VERSION . " ===");
setGlobal($CYCLE . 'Run', $STARTED, 1);
setGlobal($CYCLE . 'Status', 'running');

$watchdog     = time();
$iteration    = 0;
$failCounter  = 0; 
$lastHistoryUpdate = 0;

while (true) {
    $iteration++;
    if (time() - $watchdog >= 5) {
        setGlobal($CYCLE . 'Run', time(), 1);
        $watchdog = time();
        $cfg = $oref->getConfig();
    }
    $cmd = getGlobal($CYCLE . 'Control');
    if ($cmd === 'stop' || $cmd === 'restart') { setGlobal($CYCLE . 'Control', ''); oaLog("CMD={$cmd} — выходим."); break; }
    if (time() - $STARTED > $MAX_RT) { oaLog("MAX_RUNTIME — выходим для перезапуска."); break; }
    if (!$cfg['ENABLED']) { if ($iteration % 60 === 1) oaLog("Модуль отключён."); sleep(10); continue; }

    $obj = $cfg['OBJECT_NAME'];
    $filterWords = array_filter(array_map('trim', explode(',', $cfg['FILTER_WORDS'])));
    $monitor_all = empty($filterWords);

    try {
        // --- 1. ОБНОВЛЕНИЕ ИСТОРИИ ---
        if ((time() - $lastHistoryUpdate) > $cfg['HIST_INTERVAL']) {
            $histData = oaFetchHistory($oref, $cfg);
            $lastHistTS = intval(getGlobal($obj . '.LastHistoryItemTS') ?: 0);
            $maxTS      = $lastHistTS;
            $groups = [];
            
            $allCountryHourAreas = [];
            $hourAgo = time() - 3600;

            foreach ($histData as $item) {
                if ($item['ts'] > $hourAgo && $item['state'] === 'alert') {
                    $allCountryHourAreas[] = $item['area'];
                }

                if ($item['ts'] <= $lastHistTS) continue;
                if ($item['ts'] > $maxTS) $maxTS = $item['ts'];
                
                $key = $item['title'] . '_' . $item['cat'] . '_' . $item['state'];
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'date'      => $item['date'], 
                        'ts'        => $item['ts'], 
                        'cat'       => $item['cat'], 
                        'title'     => $item['title'], 
                        'state'     => $item['state'], 
                        'all_areas' => [], 
                        'my_areas'  => []
                    ];
                }
                $groups[$key]['all_areas'][] = $item['area'];
                if (!$monitor_all && in_array($item['area'], $filterWords)) {
                    $groups[$key]['my_areas'][] = $item['area'];
                }
            }
            if (!empty($groups)) {
                usort($groups, function($a, $b) { return $a['ts'] <=> $b['ts']; });
                foreach ($groups as $group) {
                    $oref->appendHistory(
                        $group['state'] === 'no_alert' ? 'end' : 'alert',
                        $group['cat'],
                        array_unique($group['all_areas']),
                        array_unique($group['my_areas']),
                        true,             
                        $group['date']     
                    );
                }
                setGlobal($obj . '.LastHistoryItemTS', $maxTS);
            }
            $historyString = implode(', ', array_unique($allCountryHourAreas));
            setGlobal($obj . '.History', mb_substr($historyString, 0, 250, 'UTF-8'));
            $lastHistoryUpdate = time();
        }

        // --- 2. ОПРОС REAL-TIME API ---
        $realtimeRaw = $oref->httpGet('https://www.oref.org.il/warningMessages/alert/Alerts.json', $cfg['PROXY']);
        
        if ($realtimeRaw === null) {
            $failCounter++;
            if ($failCounter >= 3) {
                oaLog("Realtime API недоступен, форсируем опрос истории", 'WARN');
                $lastHistoryUpdate = 0; 
            }
        } else {
            $failCounter = 0; 
        }

        $currentHash = md5($realtimeRaw ?: '');
        $lastHash    = getGlobal($obj . '.LastDataHash');
        $dataChanged = ($currentHash !== $lastHash);
        if ($dataChanged) setGlobal($obj . '.LastDataHash', $currentHash);

        setGlobal($obj . '.UpTime', date('Y-m-d H:i:s'));
        $alertFound = false;
        $foundData  = [];

        if ($realtimeRaw && $realtimeRaw !== '[]') {
            $json = json_decode($realtimeRaw, true);
            if (is_array($json) && !empty($json['data'])) {
                $rtCat = intval($json['cat'] ?? 1);
                $title = $json['title'] ?? '';
                $histCat = $oref->rtToHistCat($rtCat, $title);
                if ($histCat !== null) {
                    $state = $oref->histCatToState($histCat);
                    if ($state !== null) {  
                        $alerts_raw = $json['data'];
                        $alerts = is_array($alerts_raw) ? $alerts_raw : [$alerts_raw];
                        $intersect = $monitor_all ? $alerts : array_intersect($filterWords, $alerts);
                        if (!empty($intersect)) {
                            $alertFound = true;
                            $foundData = [
                                'histCat'   => $histCat, 
                                'state'     => $state, 
                                'city'      => $intersect[0], 
                                'allCities' => implode(', ', $alerts),
                                'myCities'  => $monitor_all ? [] : array_values($intersect),
                                'title'     => $title
                            ];
                        }
                    }
                }
            }
        }

        if (!$alertFound && isset($histData)) {
            foreach ($histData as $item) {
                if (!$item['isActive'] || $item['state'] !== 'alert') continue;
                $city = $item['area'];
                $isMine = (!$monitor_all && in_array($city, $filterWords));
                if ($monitor_all || $isMine) {
                    $alertFound = true;
                    $foundData = [
                        'histCat'   => $item['cat'],
                        'state'     => $item['state'],
                        'city'      => $city,
                        'allCities' => $city,
                        'myCities'  => $isMine ? [$city] : [],
                        'title'     => $item['title'],
                    ];
                    break;
                }
            }
        }

        // --- 3. ОБРАБОТКА РЕЗУЛЬТАТА ---
        if ($alertFound) {
            $hc      = $foundData['histCat'];
            $state   = $foundData['state'];
            $city    = $foundData['city'];
            $catSet  = $oref->getCatSettings($hc);
            $cityInfo = $oref->getCityInfo($city);
            
            if ($cfg['GPS_LAT'] && $cfg['GPS_LNG'] && $cityInfo['lat']) {
                $dist = $oref->getDistance($cfg['GPS_LAT'], $cfg['GPS_LNG'], $cityInfo['lat'], $cityInfo['lng']);
                setGlobal($obj . '.Distance', $dist !== null ? $dist : '');
            } else {
                setGlobal($obj . '.Distance', '');
            }

            setGlobal($obj . '.Category',     $hc);
            setGlobal($obj . '.Img',          $catSet['img']);
            setGlobal($obj . '.Name',         $catSet['name']);
            setGlobal($obj . '.Instructions', $catSet['instr']);
            setGlobal($obj . '.City',         mb_substr($foundData['allCities'], 0, 250, 'UTF-8'));         
            setGlobal($obj . '.CityRu',       $cityInfo ? $cityInfo['name_ru'] : $city);       
            setGlobal($obj . '.ZoneRu',       $cityInfo ? $cityInfo['zone_ru'] : '');       
            setGlobal($obj . '.CityID',       $cityInfo ? $cityInfo['id'] : '');       
            setGlobal($obj . '.MapData',      $oref->getMapData($city) ?: ''); 
            setGlobal($obj . '.ShelterTime',  $cityInfo ? $cityInfo['countdown'] : AreaData::getMigunTime($city));
            setGlobal($obj . '.MyActiveAreas', mb_substr(implode(', ', $foundData['myCities']), 0, 250, 'UTF-8'));

            if ($state === 'pre_alert') {
                setGlobal($obj . '.Status', 'No Alert');
                callMethod($obj . '.' . $cfg['TRIGGER_METHOD']);
                oaRunCustomCode('pre_alert', $cfg, $obj, $city, $hc);
            } elseif ($state === 'no_alert') {
                setGlobal($obj . '.Status', 'No Alert');
                callMethod($obj . '.' . $cfg['TRIGGER_METHOD']);
                oaRunCustomCode('clear', $cfg, $obj, $city, $hc);
            } else {
                $prevStatus = getGlobal($obj . '.Status');
                if ($prevStatus !== 'Alert') {
                    setGlobal($obj . '.Count', intval(getGlobal($obj . '.Count')) + 1);
                    setGlobal($obj . '.LastAlarmTime', date('Y-m-d H:i:s'));
                    setGlobal($obj . '.LastAlertTS',   time());
                    $oref->appendHistory('alert', $hc, array_unique(explode(', ', $foundData['allCities'])), $foundData['myCities']);
                    oaLog("НОВАЯ ТРЕВОГА (В МОЕЙ ЗОНЕ): " . $foundData['allCities']);
                }
                setGlobal($obj . '.Status', 'Alert');
                callMethod($obj . '.' . $cfg['TRIGGER_METHOD']);
                oaRunCustomCode('alert', $cfg, $obj, $city, $hc);
            }
        } else {
            $lastAlarmStr = getGlobal($obj . '.LastAlarmTime');
            $lastAlarmTS  = $lastAlarmStr ? strtotime($lastAlarmStr) : 0;
            $timePassed   = $lastAlarmTS ? (time() - $lastAlarmTS) : PHP_INT_MAX;

            if (getGlobal($obj . '.Status') === 'Alert') {
                if ($timePassed > ($cfg['AUTO_CLOSE_TIME'] * 60)) {
                    oaLog("Автоотбой.");
                    setGlobal($obj . '.Status', 'No Alert');
                    $oref->appendHistory('auto_clear', 0, [], []);
                    callMethod($obj . '.' . $cfg['TRIGGER_METHOD']);
                    oaRunCustomCode('clear', $cfg, $obj, '', 0);
                }
            }
            if ($timePassed > 3600 && getGlobal($obj . '.City') !== '') {
                setGlobal($obj . '.Category',      '');
                setGlobal($obj . '.Img',           '/modules/oref_alert/icons/default.png');
                setGlobal($obj . '.Name',          '');
                setGlobal($obj . '.Instructions',  'Оповещений нет');
                setGlobal($obj . '.City',          '');
                setGlobal($obj . '.CityID',        '');
                setGlobal($obj . '.ShelterTime',   0);
                setGlobal($obj . '.MyActiveAreas', '');
                setGlobal($obj . '.Distance',      '');
            }
        }
        setGlobal($CYCLE . 'Status', "ok #{$iteration} " . date('H:i:s'));
        $errCount = 0;
    } catch (Throwable $e) {
        $errCount++;
        oaLog("ОШИБКА: " . $e->getMessage(), 'ERROR');
        if ($errCount >= 10) break;
        sleep(8);
        continue;
    }
    $st = getGlobal($obj . '.Status');
    sleep(($st === 'Alert' || $dataChanged) ? 2 : max(2, $cfg['POLL']));
}
setGlobal($CYCLE . 'Status', 'stopped');

function oaFetchHistory($oref, $cfg) {
    $urls = ['https://www.oref.org.il/warningMessages/alert/History/AlertsHistory.json','https://alerts-history.oref.org.il/Shared/Ajax/GetAlarmsHistory.aspx?lang=he&mode=1'];
    $alertWindow = 120; $result = []; $seen = []; $now = time();
    foreach ($urls as $url) {
        $raw = $oref->httpGet($url, $cfg['PROXY']);
        if (!$raw || $raw === '[]') continue;
        $data = json_decode($raw, true);
        if (!is_array($data)) continue;
        foreach ($data as $rec) {
            $area = trim($rec['data'] ?? ''); 
            $cat  = intval($rec['category'] ?? 1);
            $dateStr = str_replace('T', ' ', $rec['alertDate'] ?? $rec['date'] ?? '');
            $ts = $dateStr ? strtotime($dateStr) : 0;
            if (!$area || !$ts) continue;
            $state = $oref->histCatToState($cat);
            if ($state === null) continue;  
            $key = $area . '_' . $cat . '_' . substr($dateStr, 0, 16);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = ['area' => $area, 'cat' => $cat, 'title' => trim($rec['title'] ?? ''), 'date' => $dateStr, 'ts' => $ts, 'state' => $state, 'isActive' => ($now - $ts) <= $alertWindow];
            }
        }
    }
    return $result;
}

function oaRunCustomCode($event, $cfg, $obj, $city, $histCat) {
    $codeMap = ['alert' => $cfg['CODE_ALERT'] ?? '', 'pre_alert' => $cfg['CODE_PRE_ALERT'] ?? '', 'clear' => $cfg['CODE_CLEAR'] ?? '', 'drill' => $cfg['CODE_DRILL'] ?? ''];
    $code = trim($codeMap[$event] ?? '');
    if (!$code) return;
    try { eval($code); } catch (Throwable $e) { oaLog("Ошибка в кастомном коде: " . $e->getMessage(), 'ERROR'); }
}