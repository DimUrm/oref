<?php
/**
 * Фоновый цикл Oref Alert
 */
chdir(dirname(__FILE__) . '/../');
include_once("./config.php");
include_once("./lib/loader.php");
set_time_limit(0);
$db = new mysql(DB_HOST, '', DB_USER, DB_PASSWORD, DB_NAME);
include_once("./load_settings.php");

// Подключаем класс для работы с историей
include_once(DIR_MODULES . "oref_alert/oref_alert.class.php");
$oref = new oref_alert();

$cycleName = 'cycle_oref_alert';

function logDebug($message) {
    $debug = getGlobal('oref_alert.debug_log');
    if ($debug == 1 || $debug == '') {
        DebMes($message, 'oref_alert');
        echo date("Y-m-d H:i:s") . " [DEBUG] " . $message . "\n";
    }
}

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR))) {
        $msg = "FATAL ERROR: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line'];
        DebMes($msg, 'oref_alert_crash');
        logDebug($msg);
    }
});

logDebug("Запуск службы Oref Alert...");
$last_history_check = 0;
$history_url = 'https://www.oref.org.il/warningMessages/alert/History/AlertsHistory.json';

while (true) {
    setGlobal($cycleName . 'Run', time(), 1);
    
    $objName = getGlobal('oref_alert.object_name') ?: 'Alert';
    if (!getObject($objName)) { sleep(5); continue; }

    $api_url = getGlobal('oref_alert.api_url') ?: 'https://www.oref.org.il/WarningMessages/alert/alerts.json';
    $polling_interval = max(2, (int)getGlobal('oref_alert.poll_interval'));
    $timeout = max(60, (int)getGlobal('oref_alert.timeout'));
    
    $zones_str = getGlobal('oref_alert.filter_words');
    $my_zones = array_filter(array_map('trim', explode(',', $zones_str)));
    
    if (empty($my_zones)) { sleep(5); continue; }

    $opts = [ "http" =>[ "method" => "GET", "timeout" => 2, "header" => "X-Requested-With: XMLHttpRequest\r\nReferer: https://www.oref.org.il/\r\n" ] ];
    $context = stream_context_create($opts);
    
    // =========================================================
    // 1. REALTIME API
    // =========================================================
    $result = @file_get_contents($api_url, false, $context);

    if ($result !== false) {
        $result = trim($result, "\xEF\xBB\xBF \t\n\r\0\x0B");
        if ($result !== '') {
            $data = json_decode($result, true);
            if (json_last_error() === JSON_ERROR_NONE && !empty($data) && isset($data['data'])) {
                
                $alerts_raw = $data['data'];
                $alerts = is_array($alerts_raw) ? $alerts_raw : [$alerts_raw];
                
                $threat_cat = isset($data['cat']) ? (int)$data['cat'] : 1;
                $threat_title = isset($data['title']) ? $data['title'] : '';
                $intersect = array_intersect($my_zones, $alerts);

                // Уникальный ID текущей тревоги, чтобы не дублировать историю каждую секунду
                $current_id = isset($data['id']) ? $data['id'] : md5(json_encode($alerts));
                
                if ($current_id !== getGlobal('oref_alert.LastRealtimeId')) {
                    setGlobal('oref_alert.LastRealtimeId', $current_id);
                    
                    // ПИШЕМ В ИСТОРИЮ АБСОЛЮТНО ВСЕ ГОРОДА ИЗРАИЛЯ!
                    $oref->appendHistory('Realtime: ' . $threat_title, $threat_cat, $alerts, $intersect);
                }
                
                if (!empty($intersect)) {
                    setGlobal($objName.'.CurrentThreatCategory', $threat_cat);
                    setGlobal($objName.'.CurrentThreatTitle', $threat_title);
                    
                    // Передача списка пострадавших зон для виджетов
                    setGlobal($objName.'.City', implode(', ', $intersect));

                    if ($threat_cat == 10 || $threat_cat == 13) {
                        if (getGlobal($objName.'.Status') === 'Alert') {
                            setGlobal($objName.'.Status', 'No Alert');
                            callMethod($objName.'.Trigger');
                        }
                    } else {
                        setGlobal($objName.'.LastAlarmTime', date('Y-m-d H:i:s')); 
                        setGlobal($objName.'.LastAlertTS', time()); 
                        if (getGlobal($objName.'.Status') !== 'Alert') {
                            setGlobal($objName.'.Status', 'Alert'); 
                            callMethod($objName.'.Trigger');
                        }
                    }
                }
            }
        }
    }
    
    // =========================================================
    // 2. ИСТОРИЯ (Раз в 60 сек)
    // =========================================================
    if (time() - $last_history_check >= 60) {
        $last_history_check = time();
        $hist_res = @file_get_contents($history_url, false, $context);
        
        if ($hist_res) {
            $hist_data = json_decode(trim($hist_res, "\xEF\xBB\xBF \t\n\r\0\x0B"), true);
            
            if (is_array($hist_data)) {
                $last_hist_ts = (int)getGlobal('oref_alert.LastHistoryItemTS');
                $max_ts = $last_hist_ts;
                $local_last_alert = (int)getGlobal($objName.'.LastAlertTS');
                
                // Переворачиваем массив, чтобы обрабатывать от старых к новым
                $hist_data = array_reverse($hist_data);

                foreach ($hist_data as $item) {
                    if (!is_array($item) || !isset($item['data'])) continue;

                    $alertTS = strtotime($item['alertDate']);
                    if ($alertTS <= $last_hist_ts) continue; // Пропускаем старье
                    if ($alertTS > $max_ts) $max_ts = $alertTS;

                    $cat = (int)$item['category'];
                    $title = isset($item['title']) ? $item['title'] : '';

                    $hist_zones_raw = $item['data'];
                    $hist_zones = is_array($hist_zones_raw) ? $hist_zones_raw :[$hist_zones_raw];
                    $hist_intersect = array_intersect($my_zones, $hist_zones);

                    // ПИШЕМ В ИСТОРИЮ ВСЕ НОВЫЕ ГОРОДА ИЗРАИЛЯ!
                    $oref->appendHistory('History: ' . $title, $cat, $hist_zones, $hist_intersect);

                    // ИЩЕМ ПРОПУЩЕННУЮ (Мою) ТРЕВОГУ
                    if (!empty($hist_intersect)) {
                        if ($alertTS > $local_last_alert && (time() - $alertTS) <= $timeout && $cat != 10 && $cat != 13) {
                            setGlobal($objName.'.CurrentThreatCategory', $cat);
                            setGlobal($objName.'.CurrentThreatTitle', $title);
                            setGlobal($objName.'.City', implode(', ', $hist_intersect));
                            setGlobal($objName.'.LastAlarmTime', date('Y-m-d H:i:s', $alertTS));
                            setGlobal($objName.'.LastAlertTS', $alertTS);
                            setGlobal($objName.'.Status', 'Alert');
                            callMethod($objName.'.Trigger');
                        }
                    }
                }
                setGlobal('oref_alert.LastHistoryItemTS', $max_ts);
                
                // Обновляем бегущую строку (History) для виджетов (События за последний час)
                $history_array = $oref->loadHistory(1);
                $marquee_strings =[];
                foreach ($history_array as $h) {
                    if ($h['cat_name'] != 'Отбой тревоги') {
                        $marquee_strings[] = $h['my_areas'] ? $h['my_areas'] : $h['all_areas'];
                    }
                }
                if (!empty($marquee_strings)) {
                    setGlobal($objName.'.History', implode(' • ', array_slice(array_unique($marquee_strings), 0, 15)));
                } else {
                    setGlobal($objName.'.History', '');
                }
            }
        }
    }
    
    // =========================================================
    // 3. СИСТЕМНЫЙ ТАЙМ-АУТ
    // =========================================================
    if (getGlobal($objName.'.Status') === 'Alert') {
        $global_last_alert = (int)getGlobal($objName.'.LastAlertTS');
        if ($global_last_alert == 0) $global_last_alert = time();
        
        if ((time() - $global_last_alert) > $timeout) {
            logDebug("Тайм-аут прошел ($timeout сек). Авто-отбой тревоги.");
            setGlobal($objName.'.Status', 'No Alert'); 
            callMethod($objName.'.Trigger');
        }
    }
    
    if (file_exists('./reboot') || isset($_GET['onetime'])) { $db->Disconnect(); exit; }
    sleep($polling_interval);
}
?>
