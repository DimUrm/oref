<?php
/**
 * OrefAlert — Мониторинг тревог гражданской обороны Израиля (Пикуд ха-Орэф)
 * @package project
 * @version 1.44
 */

require_once(DIR_MODULES . 'oref_alert/lib/AreaData.php');
require_once(DIR_MODULES . 'oref_alert/lib/CityDb.php');

define('OREF_RT_TO_HIST',[
    1 => 1, 3 => 7, 4 => 9, 5 => 11, 6 => 2, 7 => 12, 13 => 13,
]);
define('OREF_ALERT_CATS',[1,2,3,4,7,8,9,10,11,12]);
define('OREF_PRE_ALERT_CAT', 14);
define('OREF_END_CAT',       13);
define('OREF_FIRST_DRILL',   15);

define('OREF_DEFAULT_CATS',[
    1  =>['name'=>'Ракетный обстрел',              'img'=>'/modules/oref_alert/icons/missiles.png',               'instructions'=>'Войдите в защищённое пространство и оставайтесь в нём 10 минут'],
    2  =>['name'=>'Нарушение воздушного пространства','img'=>'/modules/oref_alert/icons/hostile_aircraft.png',    'instructions'=>'Пройдите в укрытие и оставайтесь в нём 10 минут'],
    3  =>['name'=>'Нестандартное оружие',          'img'=>'/modules/oref_alert/icons/hazardous_materials.png',    'instructions'=>'Закройте окна, двери и следуйте указаниям властей'],
    4  =>['name'=>'Общее предупреждение',          'img'=>'/modules/oref_alert/icons/default.png',                'instructions'=>'Следуйте указаниям властей'],
    7  =>['name'=>'Землетрясение',                 'img'=>'/modules/oref_alert/icons/earthqwake.png',             'instructions'=>'Немедленно выйдите на открытое пространство'],
    8  =>['name'=>'Землетрясение (вторичная волна)','img'=>'/modules/oref_alert/icons/earthqwake.png',            'instructions'=>'Немедленно выйдите на открытое пространство'],
    9  =>['name'=>'Радиологическое событие',       'img'=>'/modules/oref_alert/icons/hazardous_materials.png',    'instructions'=>'Закройте окна и двери, выключите кондиционер'],
    10 =>['name'=>'Проникновение террористов',     'img'=>'/modules/oref_alert/icons/terrorist_infiltration.png', 'instructions'=>'Войдите в здание, заприте двери, выключите свет и не выходите до уведомления армии. Не стойте напротив окон.'],
    11 =>['name'=>'Угроза цунами',                 'img'=>'/modules/oref_alert/icons/tsunami.png',                'instructions'=>'Уйдите на возвышенность немедленно'],
    12 =>['name'=>'Утечка опасных веществ',        'img'=>'/modules/oref_alert/icons/hazardous_materials.png',    'instructions'=>'Закройте окна и двери'],
    13 =>['name'=>'Отбой тревоги',                 'img'=>'/modules/oref_alert/icons/exit.png',                   'instructions'=>'Вы можете покинуть защищённое пространство'],
    14 =>['name'=>'Предварительное оповещение',    'img'=>'/modules/oref_alert/icons/enter.png',                  'instructions'=>'В ближайшие минуты ожидается тревога'],
    0  =>['name'=>'Тревога',                       'img'=>'/modules/oref_alert/icons/default.png',                'instructions'=>'Следуйте указаниям'],
]);

define('OREF_HIST_NAMES', OREF_DEFAULT_CATS);
define('OREF_HIST_ALERT_CATS',[1,2,3,4,6,7,8,9,10,11,12]);
define('OREF_HIST_END_CATS',[13]);
define('OREF_HIST_DRILL_CATS', range(15, 29));

class oref_alert extends module {
    
    function __construct() {
        $this->name            = 'oref_alert';
        $this->title           = 'Oref Alert (Служба тыла)';
        $this->module_category = '<#LANG_SECTION_DEVICES#>';
        $this->checkInstalled();
    }

    function saveParams($data = 1) {
        $p =[];
        if (isset($this->id))        $p['id']        = $this->id;
        if (isset($this->view_mode)) $p['view_mode']  = $this->view_mode;
        if (isset($this->edit_mode)) $p['edit_mode']  = $this->edit_mode;
        if (isset($this->tab))       $p['tab']        = $this->tab;
        return parent::saveParams($p);
    }

    function getParams() {
        global $id, $mode, $view_mode, $edit_mode, $tab, $action;
        if (isset($id))        $this->id        = $id;
        if (isset($mode))      $this->mode      = $mode;
        if (isset($view_mode)) $this->view_mode  = $view_mode;
        if (isset($edit_mode)) $this->edit_mode  = $edit_mode;
        if (isset($tab))       $this->tab        = $tab;
		if (isset($action))    $this->action     = $action;
	}

    function run() {
        global $session, $ajax, $op, $q, $lat, $lng;

        if ($this->mode == 'menu') {
			$out = [];
            $this->usual($out);
            $this->data   = $out;
            $p = new parser(DIR_TEMPLATES . $this->name . '/action_menu.html', $this->data, $this);
            $this->result = $p->result;
            return;
        }

        if ($this->action == 'map') {
            if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
            $cfg = $this->getConfig();
            $obj = $cfg['OBJECT_NAME'];
            ob_start();
            include(DIR_TEMPLATES . $this->name . '/map_popup.html');
            $this->result = ob_get_clean();
            echo $this->result;
            exit;
        }

        if ($ajax) {
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            if ($op == 'search_city') echo json_encode(CityDb::search($q ?: ''), JSON_UNESCAPED_UNICODE);
            if ($op == 'gps_lookup') {
                $cfg = $this->getConfig();
                echo json_encode($this->gpsFindArea(floatval($lat), floatval($lng), $cfg), JSON_UNESCAPED_UNICODE);
            }
            exit;
        }

        $out =[];
        if ($this->action == 'admin') { $this->admin($out); } else { $this->usual($out); }
        
        if (isset($this->owner->action)) $out['PARENT_ACTION'] = $this->owner->action;
        if (isset($this->owner->name))   $out['PARENT_NAME']   = $this->owner->name;
        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE']      = $this->mode;
        $out['ACTION']    = $this->action;
        $out['TAB']       = $this->tab ?: 'status';
        
        $this->data   = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . '/' . $this->name . '.html', $this->data, $this);
        $this->result = $p->result;
    }

    function admin(&$out) {
        $checkObj = SQLSelectOne("SELECT ID FROM objects WHERE TITLE='" . DBSafe($this->config['OBJECT_NAME']) . "'");
        if (!$checkObj) { $this->install_classes(); }

        $this->getConfig(); 
        $obj = $this->config['OBJECT_NAME'];

        if ($this->mode == 'update' || in_array($this->view_mode,['save_zones', 'save_object', 'save_intervals', 'save_options', 'save_categories', 'save_code'])) {
            if ($this->view_mode == 'save_zones') {
                if (isset($_POST['filter_words'])) $this->config['FILTER_WORDS'] = trim($_POST['filter_words']);
                if (isset($_POST['gps_lat']))      $this->config['GPS_LAT']      = trim($_POST['gps_lat']);
                if (isset($_POST['gps_lng']))      $this->config['GPS_LNG']      = trim($_POST['gps_lng']);
                $out['OK_MSG'] = 'Зоны мониторинга сохранены!';
            }
            elseif ($this->view_mode == 'save_object') {
                if (isset($_POST['object_name']))    $this->config['OBJECT_NAME']    = trim($_POST['object_name']) ?: 'Alarm';
                if (isset($_POST['trigger_method'])) $this->config['TRIGGER_METHOD'] = trim($_POST['trigger_method']) ?: 'Trigger';
                $out['OK_MSG'] = 'Настройки объекта сохранены!';
            }
            elseif ($this->view_mode == 'save_intervals') {
                if (isset($_POST['poll_interval']))    $this->config['POLL']             = max(2,  intval($_POST['poll_interval']));
                if (isset($_POST['history_interval'])) $this->config['HIST_INTERVAL'] = max(60, intval($_POST['history_interval']));
                if (isset($_POST['proxy']))            $this->config['PROXY']            = trim($_POST['proxy']);
                $out['OK_MSG'] = 'Интервалы и сеть сохранены!';
            }
            elseif ($this->view_mode == 'save_options') {
                $this->config['ENABLED']         = isset($_POST['enabled'])         ? 1 : 0;
                $this->config['NOTIFY']          = isset($_POST['notify_flag'])     ? 1 : 0;
                $this->config['TTS']             = isset($_POST['tts_flag'])        ? 1 : 0;
                $this->config['AUTO_CLOSE']      = isset($_POST['auto_close_flag']) ? 1 : 0;
                $this->config['DEBUG_LOG']       = isset($_POST['debug_log'])       ? 1 : 0;
                if (isset($_POST['auto_close_time'])) $this->config['AUTO_CLOSE_TIME'] = min(20, max(10, intval($_POST['auto_close_time'])));
                if (isset($_POST['hist_period']))     $this->config['HIST_PERIOD']     = min(24, max(2, intval($_POST['hist_period'])));
                $out['OK_MSG'] = 'Опции модуля сохранены!';
            }
            elseif ($this->view_mode == 'save_categories') {
                $catIds =[1,2,3,4,7,8,9,10,11,12,13,14,0];
                foreach ($catIds as $cid) {
                    if (isset($_POST["cat_name_$cid"]))  $this->config["CAT_NAME_$cid"]  = trim($_POST["cat_name_$cid"]);
                    if (isset($_POST["cat_img_$cid"]))   $this->config["CAT_IMG_$cid"]   = trim($_POST["cat_img_$cid"]);
                    if (isset($_POST["cat_instr_$cid"])) $this->config["CAT_INSTR_$cid"] = trim($_POST["cat_instr_$cid"]);
                }
                $out['OK_MSG'] = 'Категории угроз успешно сохранены!';
            }
            elseif ($this->view_mode == 'save_code') {
                if (isset($_POST['code_alert']))     $this->config['CODE_ALERT']     = trim($_POST['code_alert']);
                if (isset($_POST['code_pre_alert'])) $this->config['CODE_PRE_ALERT'] = trim($_POST['code_pre_alert']);
                if (isset($_POST['code_clear']))     $this->config['CODE_CLEAR']     = trim($_POST['code_clear']);
                if (isset($_POST['code_drill']))     $this->config['CODE_DRILL']     = trim($_POST['code_drill']);
                $out['OK_MSG'] = 'Пользовательские сценарии сохранены!';
            }

            $this->saveConfig();
            $this->getConfig(); 
            $obj = $this->config['OBJECT_NAME'];
            $this->view_mode = ''; 
            $this->mode = ''; 
        }

        if ($this->view_mode == 'selftest') {
            $r = $this->doSelfTest($this->config);
            $out['SELFTEST_OK']      = $r['ok'] ? '1' : '0';
            $out['SELFTEST_LATENCY'] = $r['latency'];
            $out['SELFTEST_ERROR']   = $r['error'];
            $this->view_mode = '';
        }

        if ($this->view_mode == 'im_safe') {
            $safe_msg = trim($_POST['safe_msg'] ?? '');
            $msg = '🛡️ Я в безопасности! ' . date('H:i:s');
            if ($safe_msg) $msg .= ' — ' . $safe_msg;
            setGlobal($obj . '.i_am_safe_ts', time());
            if ($this->config['NOTIFY'] && function_exists('sendTelegram')) sendTelegram($msg);
            $out['IM_SAFE_MSG'] = $msg;
            $this->view_mode = '';
        }

        $out['OBJ_STATUS']      = getGlobal($obj . '.Status')       ?: 'No Alert';
        $out['OBJ_STATE']       = getGlobal($obj . '.state')        ?: 'ok';
        $out['OBJ_CATEGORY']    = getGlobal($obj . '.Category')     ?: '';
        $out['OBJ_NAME']        = getGlobal($obj . '.Name')         ?: '';
        $out['OBJ_CITY']        = getGlobal($obj . '.City')         ?: '';
        $out['OBJ_INSTRUCTIONS']= getGlobal($obj . '.Instructions') ?: 'Оповещений нет';
        $out['OBJ_IMG']         = getGlobal($obj . '.Img')          ?: '/modules/oref_alert/icons/default.png';
        $out['OBJ_HISTORY']     = getGlobal($obj . '.History')      ?: '';
        $out['OBJ_UPTIME']      = getGlobal($obj . '.UpTime')       ?: '—';
        $out['OBJ_COUNT']       = intval(getGlobal($obj . '.Count'));
        $out['OBJ_LAST_ALARM']  = getGlobal($obj . '.LastAlarmTime') ?: '—';
        $out['OBJ_DISTANCE']    = getGlobal($obj . '.Distance');
        $out['OBJ_COUNTDOWN']   = $this->calcCountdown($obj);
        $out['OBJ_SHELTER']     = intval(getGlobal($obj . '.ShelterTime'));
        $out['OBJ_MY_AREAS']    = $this->config['FILTER_WORDS'] ?: '';
        $out['OBJ_ALL_AREAS']   = getGlobal($obj . '.AllActiveAreas') ?: '';
        $out['IS_ALERT']        = ($out['OBJ_STATUS'] === 'Alert')   ? '1' : '';
        $out['LAST_SAFE_DATE']  = intval(getGlobal($obj . '.i_am_safe_ts')) ? date('d.m H:i', intval(getGlobal($obj . '.i_am_safe_ts'))) : '';

        foreach ($this->config as $k => $v) { $out['CFG_' . $k] = $v; }
		$out['CODE_ALERT']     = $this->config['CODE_ALERT']     ?? '';
		$out['CODE_PRE_ALERT'] = $this->config['CODE_PRE_ALERT'] ?? '';
		$out['CODE_CLEAR']     = $this->config['CODE_CLEAR']      ?? '';
		$out['CODE_DRILL']     = $this->config['CODE_DRILL']      ?? '';

        $catIds =[1,2,3,4,7,8,9,10,11,12,13,14,0];
        $catRows =[];
        foreach ($catIds as $cid) {
            $def = OREF_DEFAULT_CATS[$cid] ?? OREF_DEFAULT_CATS[0];
            $catRows[] =[
                'ID'    => $cid,
                'NAME'  => $this->config["CAT_NAME_$cid"]  ?? $def['name'],
                'IMG'   => $this->config["CAT_IMG_$cid"]   ?? $def['img'],
                'INSTR' => $this->config["CAT_INSTR_$cid"] ?? $def['instructions'],
            ];
        }
        $out['CATEGORIES'] = $catRows;

        $history = $this->loadHistory($this->config['HIST_PERIOD']);
        $grouped =[];
        foreach ($history as $h) {
            $date  = $h['date'] ?? '';
            $cat   = $h['cat_name'] ?? '';
            $event = $h['event'] ?? '';
            $timeKey = substr($date, 0, 16); 
            $key = $timeKey . '_' . $cat . '_' . $event;
            
            if (!isset($grouped[$key])) {
                $grouped[$key] =[
                    'DATE'          => $timeKey, 
                    'EVENT'         => $event,
                    'CAT_NAME'      => $cat,
                    'MY_AREAS_ARR'  => [],
                    'ALL_AREAS_ARR' =>[]
                ];
            }
            if (!empty($h['my_areas'])) {
                foreach (explode(',', $h['my_areas']) as $m) { 
                    $m = trim($m); if ($m) $grouped[$key]['MY_AREAS_ARR'][] = $m; 
                }
            }
            if (!empty($h['all_areas'])) {
                foreach (explode(',', $h['all_areas']) as $a) { 
                    $a = trim($a); if ($a) $grouped[$key]['ALL_AREAS_ARR'][] = $a; 
                }
            }
        }
        $histOut =[];
        foreach ($grouped as $g) {
            $histOut[] = [
                'DATE'      => $g['DATE'],
                'EVENT'     => $g['EVENT'],
                'CAT_NAME'  => $g['CAT_NAME'],
                'MY_AREAS'  => implode(', ', array_unique($g['MY_AREAS_ARR'])),
                'ALL_AREAS' => implode(', ', array_unique($g['ALL_AREAS_ARR']))
            ];
        }
        $out['HISTORY'] = array_values($histOut);
        $out['CYCLE_STATUS'] = getGlobal('cycle_oref_alertRun') ? 'OK' : 'STOPPED';
    }

    function usual(&$out) {
        $this->getConfig();
        $obj = $this->config['OBJECT_NAME'];
        $out['OBJ_STATUS']      = getGlobal($obj . '.Status')       ?: 'No Alert';
        $out['OBJ_NAME']        = getGlobal($obj . '.Name')         ?: '';
        $out['OBJ_CITY']        = getGlobal($obj . '.City')         ?: '';
        $out['OBJ_CITY_RU']     = getGlobal($obj . '.CityRu')       ?: '';
        $out['OBJ_IMG']         = getGlobal($obj . '.Img')          ?: '/modules/oref_alert/icons/default.png';
        $out['OBJ_INSTRUCTIONS']= getGlobal($obj . '.Instructions') ?: 'Оповещений нет';
        $out['OBJ_HISTORY']     = getGlobal($obj . '.History')      ?: '';
        $out['OBJ_LAST_ALARM']  = getGlobal($obj . '.LastAlarmTime') ?: '—';
        $out['OBJ_UPTIME'] 		= getGlobal($obj . '.UpTime') ?: '—';
		$out['OBJ_COUNTDOWN']   = $this->calcCountdown($obj);
        $out['IS_ALERT']        = (getGlobal($obj . '.Status') === 'Alert') ? '1' : '';
        $out['CFG_OBJECT_NAME'] = $obj;
    }

    function install_classes() {
        $warnClass = SQLSelectOne("SELECT ID FROM classes WHERE TITLE='Warning'");
        if (!$warnClass) {
            $rec = array('TITLE' => 'Warning', 'DESCRIPTION' => 'Система оповещений');
            $warnId = SQLInsert('classes', $rec);
            $warnClass = array('ID' => $warnId);
        }
        $alertObj = SQLSelectOne("SELECT ID, CLASS_ID FROM objects WHERE TITLE='Alarm'");
        if (!$alertObj) {
            $rec = array('TITLE' => 'Alarm', 'CLASS_ID' => $warnClass['ID'], 'DESCRIPTION' => 'Основной объект OrefAlert');
            SQLInsert('objects', $rec);
        } elseif ($alertObj['CLASS_ID'] == 0) {
            $rec = array('ID' => $alertObj['ID'], 'CLASS_ID' => $warnClass['ID']);
            SQLUpdate('objects', $rec);
        }
        $props =['Status', 'state', 'Category', 'Name', 'Instructions', 'Img', 'City', 'CityRu', 'ZoneRu', 'CityID', 'MapData', 'Distance', 'History', 'LastAlarmTime', 'LastAlertTS', 'ShelterTime', 'MyActiveAreas', 'AllActiveAreas', 'Count', 'UpTime', 'Notify', 'Last14Sounded', 'LastDataHash', 'LastHistoryItemTS', 'i_am_safe_ts', 'HistoryData'];
        foreach ($props as $p) {
            $propRec = SQLSelectOne("SELECT ID FROM properties WHERE TITLE='" . DBSafe($p) . "' AND CLASS_ID=" . $warnClass['ID']);
            if (!$propRec) {
                $rec = array('TITLE' => $p, 'CLASS_ID' => $warnClass['ID'], 'KEEP_HISTORY' => 0);
                SQLInsert('properties', $rec);
            }
        }
        $methodRec = SQLSelectOne("SELECT ID FROM methods WHERE TITLE='Trigger' AND CLASS_ID=" . $warnClass['ID']);
        if (!$methodRec) {
            $rec = array('TITLE' => 'Trigger', 'CLASS_ID' => $warnClass['ID'], 'CODE' => '// Метод вызывается при тревоге');
            SQLInsert('methods', $rec);
        }
        $this->getConfig();
        $dataDir = DIR_MODULES . 'oref_alert/data/';
        if (!is_dir($dataDir)) @mkdir($dataDir, 0777, true);
    }

    function install($data = '') {
        parent::install();
        $this->install_classes();
    }

    function uninstall() {
        SQLExec("DELETE FROM project_modules WHERE NAME='oref_alert'");
        parent::uninstall();
    }

    function dbInstall($data) {
        $data = <<<EOD
 oref_alert: ID int(10) unsigned NOT NULL auto_increment
 oref_alert: TITLE varchar(100) NOT NULL DEFAULT ''
EOD;
        parent::dbInstall($data);
    }

    public function getConfig() {
        parent::getConfig();
        $defaults =[
            'FILTER_WORDS' => '', 'GPS_LAT' => '', 'GPS_LNG' => '',
            'OBJECT_NAME' => 'Alarm', 'TRIGGER_METHOD' => 'Trigger',
            'POLL' => 8, 'HIST_INTERVAL' => 300, 'PROXY' => '',
            'ENABLED' => 1, 'NOTIFY' => 0, 'TTS' => 0, 'AUTO_CLOSE' => 1, 'DEBUG_LOG' => 1,
            'AUTO_CLOSE_TIME' => 10, 'HIST_PERIOD' => 24,
            'CODE_ALERT' => '', 'CODE_PRE_ALERT' => '', 'CODE_CLEAR' => '', 'CODE_DRILL' => ''
        ];
        foreach (OREF_DEFAULT_CATS as $cid => $d) {
            $defaults["CAT_NAME_$cid"]  = $d['name'];
            $defaults["CAT_IMG_$cid"]   = $d['img'];
            $defaults["CAT_INSTR_$cid"] = $d['instructions'];
        }
        $save_needed = false;
        if (!is_array($this->config)) $this->config =[];
        foreach ($defaults as $k => $v) {
            if (!isset($this->config[$k])) {
                $this->config[$k] = $v;
                $save_needed = true;
            }
        }
        if ($save_needed) $this->saveConfig();
        return $this->config;
    }

    public function getCatSettings($histCat) {
        $cfg = $this->getConfig();
        $def = OREF_HIST_NAMES[$histCat] ?? OREF_DEFAULT_CATS[0];
        return[
            'name'  => $cfg["CAT_NAME_$histCat"]  ?? $def['name'],
            'img'   => $cfg["CAT_IMG_$histCat"]   ?? $def['img'],
            'instr' => $cfg["CAT_INSTR_$histCat"] ?? $def['instructions'],
        ];
    }

    public function histCatToState($cat) {
        if (in_array($cat, OREF_HIST_DRILL_CATS)) return null;
		if ($cat === 14)                          return 'pre_alert';		
        if (in_array($cat, OREF_HIST_END_CATS))   return 'no_alert'; 
        if (in_array($cat, OREF_HIST_ALERT_CATS)) return 'alert';    
        return null;
    }

    public function rtToHistCat($rtCat, $title = '') {
        if ($rtCat === 10) return (mb_strpos($title, 'בדקות', 0, 'UTF-8') !== false) ? 14 : 13;
        if ($rtCat === 2) return 2;
        if (in_array($rtCat,[8, 9, 11, 12])) return $rtCat;
        if (in_array($rtCat,[5, 6])) return null;
        return OREF_RT_TO_HIST[$rtCat] ?? null;
    }

    public function getCityInfo($cityNameHe) { return CityDb::findByName($cityNameHe); }
    public function getMapData($cityNameHe)  { return CityDb::getMapData($cityNameHe); }

    public function gpsFindArea($lat, $lng, $cfg) {
        $file = DIR_MODULES . 'oref_alert/data/cities.json';
        if (!file_exists($file)) return ['error' => 'cities.json not found'];
        $arr = json_decode(file_get_contents($file), true);
        if (!is_array($arr)) return['error' => 'invalid json'];
        $best = null; $bestDist = PHP_INT_MAX;
        foreach ($arr as $c) {
            $clat = floatval($c['lat'] ?? 0);
            $clng = floatval($c['lng'] ?? 0);
            if (!$clat || !$clng) continue;
            $d = sqrt(pow($lat-$clat,2)+pow($lng-$clng,2));
            if ($d < $bestDist) { $bestDist=$d; $best=$c; }
        }
        if (!$best) return['error'=>'not found'];
        return[
            'he'       => $best['name'],
            'ru'       => $best['name_ru'] ?? $best['name'],
            'countdown'=> intval($best['countdown'] ?? 45),
            'zone_ru'  => $best['zone_ru'] ?? '',
        ];
    }

    public function getDistance($lat1, $lon1, $lat2, $lon2) {
        if (!$lat1 || !$lon1 || !$lat2 || !$lon2) return null;
        $earth_radius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return round($earth_radius * $c, 1);
    }

    public function calcCountdown($obj) {
        $status  = getGlobal($obj . '.Status');
        $shelter = intval(getGlobal($obj . '.ShelterTime'));
        $ts      = intval(getGlobal($obj . '.LastAlertTS'));
        if ($status !== 'Alert' || !$shelter || !$ts) return 0;
        return max(-60, $shelter - (time() - $ts));
    }

    public function appendHistory($event, $histCat, $allAreas, $myAreas, $isHistApi = false, $alertDate = null) {
        if (!is_array($allAreas)) $allAreas = [$allAreas];
        if (!is_array($myAreas))  $myAreas  = [$myAreas];
        $names  = $isHistApi ? OREF_HIST_NAMES : OREF_DEFAULT_CATS;
        $def    = $names[$histCat] ?? ['name' => 'Тревога'];
        $srcDate = $alertDate ?: date('Y-m-d H:i:s');
        $obj    = $this->config['OBJECT_NAME'];
        $raw  = getGlobal($obj . '.HistoryData');
        $hist = $raw ? (json_decode($raw, true) ?: []) : [];
        $allStr   = implode(',', array_slice($allAreas, 0, 10));
        $myStr    = implode(', ', $myAreas);
        $cutoff2h = time() - 7200;
        foreach ($hist as $existing) {
            if (intval($existing['cat'] ?? 0) === $histCat
                && ($existing['all_areas'] ?? '') === $allStr
                && ($existing['event'] ?? '') === $event
                && strtotime($existing['date'] ?? '') >= $cutoff2h) {
                return;
            }
        }
        array_unshift($hist, [
            'date'     => $srcDate,
            'event'    => $event,
            'cat_name' => $def['name'],
            'cat'      => $histCat,
            'all_areas'=> $allStr,
            'my_areas' => $myStr,
        ]);
        setGlobal($obj . '.HistoryData', json_encode(array_slice($hist, 0, 30), JSON_UNESCAPED_UNICODE));
    }

    public function loadHistory($hours = 24) {
        $obj = $this->config['OBJECT_NAME'];
        $raw = getGlobal($obj . '.HistoryData');
        if (!$raw) return [];
        $d = json_decode($raw, true);
        if (!is_array($d)) return [];
        $cutoff = time() - ($hours * 3600);
        $result = [];
        foreach ($d as $item) {
            if (strtotime($item['date'] ?? '') >= $cutoff) {
                $result[] = $item;
            }
        }
        return $result;
    }

    private function doSelfTest($cfg) {
        $t0     = microtime(true);
        $result = $this->httpGetRaw('https://www.oref.org.il/warningMessages/alert/Alerts.json', $cfg['PROXY']);
        $ms = round((microtime(true) - $t0) * 1000);
        $ok  = ($result['code'] === 200 && $result['err'] === '');
        $msg = $ok
            ? ('HTTP 200 — ' . ($result['empty'] ? 'нет активных тревог (норма)' : 'тревоги активны'))
            : ('HTTP ' . $result['code'] . ($result['err'] ? ' / ' . $result['err'] : ''));
        return['ok' => $ok, 'latency' => $ms . 'ms', 'error' => $ok ? '' : $msg];
    }

    private function httpGetRaw($url, $proxy = '') {
        $ch = curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER => true, 
            CURLOPT_TIMEOUT => 10, 
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     =>[ 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/121.0 Safari/537.36', 'Referer: https://www.oref.org.il/11226-he/pakar.aspx', 'X-Requested-With: XMLHttpRequest', 'Content-Type: application/json;charset=UTF-8' ],
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_ENCODING => '',
        ]);
        if ($proxy) curl_setopt($ch, CURLOPT_PROXY, $proxy);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw !== false) { 
            $raw = ltrim($raw, "\xEF\xBB\xBF"); 
            $raw = str_replace("\x00", '', trim($raw)); 
            $raw = str_replace("\x0A\x7B", '', $raw);
        } else { $raw = ''; }
        $empty = ($raw === '' || $raw === '[]' || $raw === 'null');
        return['code' => $code, 'err' => $err, 'raw' => $raw, 'empty' => $empty];
    }

    public function httpGet($url, $proxy = '') {
        $r = $this->httpGetRaw($url, $proxy);
        if ($r['err'] || $r['code'] !== 200) { 
            $cfg = $this->getConfig();
            if (isset($cfg['DEBUG_LOG']) && intval($cfg['DEBUG_LOG'])) {
                DebMes("OrefAlert HTTP {$r['code']} err={$r['err']} url={$url}", 'oref_alert'); 
            }
            return null; 
        }
        if ($r['empty']) return '[]'; 
        return $r['raw']; 
    }
}