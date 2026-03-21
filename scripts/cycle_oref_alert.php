<?php
/**
 * cycle_oref_alert.php
 */
chdir(dirname(__FILE__) . '/../');
include_once('./config.php');
include_once('./lib/loader.php');
include_once('./lib/threads.php');
set_time_limit(0);

register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'],[E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $msg = 'FATAL: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line'];
        DebMes($msg, 'oref_alert_crash');
    }
});

$db = new mysql(DB_HOST, '', DB_USER, DB_PASSWORD, DB_NAME);
include_once('./load_settings.php');
include_once(DIR_MODULES . 'oref_alert/oref_alert.class.php');

function oaLog($msg, $level = 'INFO') {
    $line = date('[Y-m-d H:i:s]') . " [{$level}] {$msg}";
    $debug = intval(getGlobal('oref_alert.debug_log'));
    if ($debug || in_array($level, ['ERROR', 'FATAL', 'WARN'])) { DebMes("OrefAlert | {$msg}", 'oref_alert'); }
    @file_put_contents(DIR_MODULES . 'oref_alert/data/cycle.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

set_time_limit(0);
$CYCLE   = str_replace('.php', '', basename(__FILE__)); 
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

    $obj = $cfg['OBJ'];

    try {
        // ── 1. ИСТОРИЯ ───────────────────────────────────
        if ((time() - $lastHistoryUpdate) > $cfg['HIST_INT']) {
            $histData = oaFetchHistory($oref, $cfg);
            $myZones  = array_filter(array_map('trim', explode(',', $cfg['FILTER'])));
            $monitor_all = empty($myZones); // ИСПРАВЛЕНО: Флаг глобального мониторинга
            
            $lastHistTS = intval(getGlobal('oref_alert.LastHistoryItemTS') ?: 0);
            $maxTS      = $lastHistTS;
            $groups =[];
            
            foreach ($histData as $item) {
                if ($item['ts'] <= $lastHistTS) continue;  
                if ($item['ts'] > $maxTS) $maxTS = $item['ts'];

                $key = $item['title'] . '_' . $item['cat'];
                if (!isset($groups[$key])) {
                    $groups[$key] = ['date'=>$item['date'], 'ts'=>$item['ts'], 'cat'=>$item['cat'], 'title'=>$item['title'], 'state'=>$item['state'], 'all_areas'=>[], 'my_areas'=>[]];
                }
                if ($item['ts'] < $groups[$key]['ts']) {
                    $groups[$key]['ts'] = $item['ts'];
                    $groups[$key]['date'] = $item['date'];
                }
                $groups[$key]['all_areas'][] = $item['area'];
                
                // ИСПРАВЛЕНО: Сохраняем как свои зоны, если включен мониторинг всей страны
                if ($monitor_all || in_array($item['area'], $myZones)) {
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
                setGlobal('oref_alert.LastHistoryItemTS', $maxTS);
                oaLog("[#{$iteration}] История: +".count($groups)." новых групп (TS до ".date('H:i:s',$maxTS).")");
            }

            $myActive =[];
            foreach ($histData as $item) {
                if ($item['state'] === 'alert' && ($monitor_all || in_array($item['area'], $myZones))) {
                    $myActive[] = $item['area'];
                }
            }
            $histString = implode(', ', array_unique($myActive));
            setGlobal($obj . '.History', mb_substr($histString, 0, 250, 'UTF-8'));
            $lastHistoryUpdate = time();
        }

        // ── 2. REALTIME ───────────────────────────────────────────────────
        $realtimeRaw = $oref->httpGet('https://www.oref.org.il/warningMessages/alert/Alerts.json', $cfg['PROXY']);

        $currentHash = md5($realtimeRaw ?: '');
        $lastHash    = getGlobal($obj . '.LastDataHash');
        $dataChanged = ($currentHash !== $lastHash);
        if ($dataChanged) setGlobal($obj . '.LastDataHash', $currentHash);

        setGlobal($obj . '.UpTime', date('Y-m-d H:i:s'));

        // ── 3. РАЗБОР REALTIME ────────────────────────────────────────────
        $alertFound = false;
        $foundData  =[];

        if ($realtimeRaw) {
            $json = json_decode($realtimeRaw, true);
            if (is_array($json) && !empty($json['data'])) {
                $rtCat = intval($json['cat'] ?? 1);
                $title = $json['title'] ?? '';
                $histCat = $oref->rtToHistCat($rtCat, $title);
                if ($histCat === null) continue;  
                $state   = $oref->histCatToState($histCat);

                if ($state !== null) {  
                    $filterWords = array_filter(array_map('trim', explode(',', $cfg['FILTER'])));
                    $monitor_all = empty($filterWords);
                    $all_cities_in_alert = $json['data']; // ИСПРАВЛЕНО: Берем все города для логов

                    foreach ($json['data'] as $city) {
                        if ($monitor_all) {
                            $alertFound = true;
                            $foundData =['histCat'=>$histCat, 'state'=>$state, 'city'=>$city, 'title'=>$title, 'all_cities'=>$all_cities_in_alert];
                            break;
                        }
                        foreach ($filterWords as $word) {
                            if ($word && mb_stripos($city, $word, 0, 'UTF-8') !== false) {
                                $alertFound = true;
                                $foundData =['histCat'=>$histCat, 'state'=>$state, 'city'=>$city, 'title'=>$title, 'all_cities'=>$all_cities_in_alert];
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        // ── 3b. FALLBACK: История ────────────────
        if (!$alertFound && isset($histData)) {
            $filterWords = array_filter(array_map('trim', explode(',', $cfg['FILTER'])));
            $monitor_all = empty($filterWords);
            foreach ($histData as $item) {
                if (!$item['isActive'] || $item['state'] !== 'alert') continue;
                $city = $item['area'];
                if ($monitor_all || in_array($city, $filterWords)) {
                    $alertFound = true;
                    $foundData =[
                        'histCat' => $item['cat'],
                        'state'   => $item['state'],
                        'city'    => $city,
                        'title'   => $item['title'],
                        'all_cities' => [$city]
                    ];
                    break;
                }
            }
        }

        // ── 4. ДЕЙСТВИЯ ───────────────────────────────────────────────────
        if ($alertFound) {
            $hc      = $foundData['histCat'];
            $state   = $foundData['state'];
            $city    = $foundData['city'];
            $all_cities = $foundData['all_cities'] ?? [$city]; // Все города тревоги для истории
            $catSet  = $oref->getCatSettings($hc);

            $cityInfo = $oref->getCityInfo($city);
            $cityId   = $cityInfo ? $cityInfo['id']      : '';
            $cityRu   = $cityInfo ? $cityInfo['name_ru'] : $city;
            $zoneRu   = $cityInfo ? $cityInfo['zone_ru'] : '';
            $mapData  = $oref->getMapData($city);  

            setGlobal($obj . '.Category',     $hc);
            setGlobal($obj . '.Img',          $catSet['img']);
            setGlobal($obj . '.Name',         $catSet['name']);
            setGlobal($obj . '.Instructions', $catSet['instr']);
            setGlobal($obj . '.City',         $city);         
            setGlobal($obj . '.CityRu',       $cityRu);       
            setGlobal($obj . '.ZoneRu',       $zoneRu);       
            setGlobal($obj . '.CityID',       $cityId);       
            setGlobal($obj . '.MapData',      $mapData ?: ''); 
            setGlobal($obj . '.ShelterTime',  $cityInfo ? $cityInfo['countdown'] : AreaData::getMigunTime($city));
            setGlobal($obj . '.MyActiveAreas', $city);

            if ($state === 'pre_alert') {
                setGlobal($obj . '.Status', 'No Alert');
                callMethod($obj . '.' . $cfg['TRIGGER']);
                oaRunCustomCode('pre_alert', $cfg, $obj, $city, $hc);

            } elseif ($state === 'no_alert') {
                setGlobal($obj . '.Status', 'No Alert');
                callMethod($obj . '.' . $cfg['TRIGGER']);
                oaRunCustomCode('clear', $cfg, $obj, $city, $hc);

            } else {
                $prevStatus = getGlobal($obj . '.Status');
                if ($prevStatus !== 'Alert') {
                    setGlobal($obj . '.Count',        intval(getGlobal($obj . '.Count')) + 1);
                    setGlobal($obj . '.LastAlarmTime', date('Y-m-d H:i:s'));
                    setGlobal($obj . '.LastAlertTS',   time());
                    
                    // ИСПРАВЛЕНО: Пишем в историю все пострадавшие города из массива!
                    $oref->appendHistory('alert', $hc, $all_cities, [$city]);
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
                $autoCloseMin = max(1, intval(getGlobal('oref_alert.auto_close_time') ?: 10));
                if ($timePassed > ($autoCloseMin * 60)) {
                    oaLog("[#{$iteration}] Автоотбой через {$autoCloseMin} мин");
                    setGlobal($obj . '.Status', 'No Alert');
                    $oref->appendHistory('auto_clear', 0, [], []);
                    callMethod($obj . '.' . $cfg['TRIGGER']);
                    oaRunCustomCode('clear', $cfg, $obj, '', 0);
                }
            }
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

        $st = getGlobal($obj . '.Status');
        setGlobal($CYCLE . 'Status', "ok #{$iteration} " . date('H:i:s') . " {$st}");
        $errCount = 0;

    } catch (Throwable $e) {
        $errCount++;
        oaLog("[#{$iteration}] ИСКЛЮЧЕНИЕ: " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine(), 'ERROR');
        setGlobal($CYCLE . 'Status', "error: " . $e->getMessage());
        if ($errCount >= 10) { oaLog("10 ошибок — выход.", 'FATAL'); break; }
        sleep(8);
        continue;
    }

    $currentStatus = getGlobal($obj . '.Status');
    if ($currentStatus === 'Alert') { sleep(2); } 
    elseif ($dataChanged) { sleep(2); } 
    else { sleep(max(2, $cfg['POLL'])); }
}

oaLog("=== ВЫХОД pid=" . getmypid() . " iter={$iteration} uptime=" . (time()-$STARTED) . "s ===");
setGlobal($CYCLE . 'Status', 'stopped');

function oaFetchHistory($oref, $cfg) {
    $urls =['https://www.oref.org.il/warningMessages/alert/History/AlertsHistory.json', 'https://alerts-history.oref.org.il/Shared/Ajax/GetAlarmsHistory.aspx?lang=he&mode=1'];
    $histPeriod  = max(1, intval(getGlobal('oref_alert.hist_period') ?: 24));
    $alertWindow = 120;   
    $result      =[];
    $seen        =[];
    $now         = time();

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

            if (!$area || !$ts) continue;

            $state = $oref->histCatToState($cat);
            if ($state === null) continue;  

            $key = $area . '_' . $cat . '_' . substr($dateStr, 0, 16);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] =[
                    'area'     => $area,
                    'cat'      => $cat,
                    'title'    => trim($rec['title'] ?? ''),
                    'date'     => $dateStr,
                    'ts'       => $ts,
                    'state'    => $state,
                    'isActive' => ($now - $ts) <= $alertWindow,
                ];
            }
        }
    }
    return $result;
}

function oaRunCustomCode($event, $cfg, $obj, $city, $histCat) {
    $codeMap =[
        'alert'     => getGlobal('oref_alert.code_alert'),
        'pre_alert' => getGlobal('oref_alert.code_pre_alert'),
        'clear'     => getGlobal('oref_alert.code_clear'),
        'drill'     => getGlobal('oref_alert.code_drill'),
    ];
    $code = trim($codeMap[$event] ?? '');
    if (!$code) return;
    oaLog("Выполняем custom code для event={$event}");
    try { eval($code); } catch (Throwable $e) { oaLog("Ошибка в custom code ({$event}): " . $e->getMessage(), 'ERROR'); }
}
?>