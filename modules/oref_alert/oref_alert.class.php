<?php
/**
 * OrefAlert — Мониторинг тревог гражданской обороны Израиля (Пикуд ха-Орэф)
 *
 * @package project
 * @author  Wizard <sergejey@gmail.com>
 * @version 5.2 Final (Fixed)
 */

require_once(DIR_MODULES . 'oref_alert/lib/AreaData.php');
require_once(DIR_MODULES . 'oref_alert/lib/CityDb.php');

define('OREF_RT_TO_HIST',[
    1 => 1, 3 => 7, 4 => 9, 5 => 11, 6 => 2, 7 => 12, 13 => 10,
]);
define('OREF_ALERT_CATS',[1,2,3,4,7,8,9,10,11,12]);
define('OREF_PRE_ALERT_CAT', 14);
define('OREF_END_CAT',       13);
define('OREF_FIRST_DRILL',   15);

define('OREF_DEFAULT_CATS', [
    1  =>['name'=>'Ракетный обстрел',              'img'=>'/cms/icons/missiles.png',               'instructions'=>'Войдите в защищённое пространство и оставайтесь в нём 10 минут'],
    2  =>['name'=>'Нарушение воздушного пространства','img'=>'/cms/icons/hostile_aircraft.png',    'instructions'=>'Пройдите в укрытие и оставайтесь в нём 10 минут'],
    3  =>['name'=>'Нестандартное оружие',          'img'=>'/cms/icons/hazardous_materials.png',    'instructions'=>'Закройте окна, двери и следуйте указаниям властей'],
    4  =>['name'=>'Общее предупреждение',          'img'=>'/cms/icons/default.png',                'instructions'=>'Следуйте указаниям властей'],
    7  =>['name'=>'Землетрясение',                 'img'=>'/cms/icons/earthqwake.png',             'instructions'=>'Немедленно выйдите на открытое пространство'],
    8  =>['name'=>'Землетрясение (вторичная волна)','img'=>'/cms/icons/earthqwake.png',            'instructions'=>'Немедленно выйдите на открытое пространство'],
    9  =>['name'=>'Радиологическое событие',       'img'=>'/cms/icons/hazardous_materials.png',    'instructions'=>'Закройте окна и двери, выключите кондиционер'],
    10 =>['name'=>'Теракт / инфильтрация',         'img'=>'/cms/icons/terrorist_infiltration.png', 'instructions'=>'Войдите в здание, закройте двери и окна'],
    11 =>['name'=>'Угроза цунами',                 'img'=>'/cms/icons/tsunami.png',                'instructions'=>'Уйдите на возвышенность немедленно'],
    12 =>['name'=>'Утечка опасных веществ',        'img'=>'/cms/icons/hazardous_materials.png',    'instructions'=>'Закройте окна и двери'],
    13 =>['name'=>'Отбой тревоги',                 'img'=>'/cms/icons/exit.png',                   'instructions'=>'Вы можете покинуть защищённое пространство'],
    14 =>['name'=>'Предварительное оповещение',    'img'=>'/cms/icons/enter.png',                  'instructions'=>'В ближайшие минуты ожидается тревога'],
    0  =>['name'=>'Тревога',                       'img'=>'/cms/icons/default.png',                'instructions'=>'Следуйте указаниям'],
]);

define('OREF_HIST_NAMES', OREF_DEFAULT_CATS);
define('OREF_HIST_ALERT_CATS',[1,2,3,4,5,6,7,8,9,10,11,12]);
define('OREF_HIST_END_CATS',   [13,14]);
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
        global $id, $mode, $view_mode, $edit_mode, $tab;
        if (isset($id))        $this->id        = $id;
        if (isset($mode))      $this->mode      = $mode;
        if (isset($view_mode)) $this->view_mode  = $view_mode;
        if (isset($edit_mode)) $this->edit_mode  = $edit_mode;
        if (isset($tab))       $this->tab        = $tab;
    }

    function run() {
        global $session, $ajax, $op, $q, $lat, $lng;

        if ($this->mode == 'menu') {
            $this->usual($out);
            $this->data   = $out;
            $p = new parser(DIR_TEMPLATES . $this->name . '/action_menu.html', $this->data, $this);
            $this->result = $p->result;
            return;
        }

        if ($this->action == 'map') {
            if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
            $cfg = $this->getConfig();
            $obj = $cfg['OBJ'];
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

    // ═══════════════════════════════════════════════════════════════════════
    // ADMIN (Изолированное сохранение настроек)
    // ═══════════════════════════════════════════════════════════════════════
    function admin(&$out) {
        
        // ФЕЙЛСЕЙФ
        $checkObj = SQLSelectOne("SELECT ID FROM objects WHERE TITLE='oref_alert'");
        if (!$checkObj) {
            $this->install_classes();
        }

        $cfg = $this->getConfig();
        $obj = $cfg['OBJ'];

        // ── СОХРАНЕНИЕ ──
        if ($this->mode == 'update' || in_array($this->view_mode,['save_zones', 'save_object', 'save_intervals', 'save_options', 'save_categories', 'save_code'])) {
            
            if ($this->view_mode == 'save_zones') {
                if (isset($_POST['filter_words'])) setGlobal('oref_alert.filter_words', trim($_POST['filter_words']));
                if (isset($_POST['gps_lat']))      setGlobal('oref_alert.gps_lat',      trim($_POST['gps_lat']));
                if (isset($_POST['gps_lng']))      setGlobal('oref_alert.gps_lng',      trim($_POST['gps_lng']));
                $out['OK_MSG'] = 'Зоны мониторинга сохранены!';
            }
            elseif ($this->view_mode == 'save_object') {
                if (isset($_POST['object_name']))    setGlobal('oref_alert.object_name',    trim($_POST['object_name']) ?: 'Alert');
                if (isset($_POST['trigger_method'])) setGlobal('oref_alert.trigger_method', trim($_POST['trigger_method']) ?: 'Trigger');
                $out['OK_MSG'] = 'Настройки объекта сохранены!';
            }
            elseif ($this->view_mode == 'save_intervals') {
                if (isset($_POST['poll_interval']))    setGlobal('oref_alert.poll_interval',    max(2,  intval($_POST['poll_interval'])));
                if (isset($_POST['history_interval'])) setGlobal('oref_alert.history_interval', max(60, intval($_POST['history_interval'])));
                if (isset($_POST['proxy']))            setGlobal('oref_alert.proxy',            trim($_POST['proxy']));
                $out['OK_MSG'] = 'Интервалы и сеть сохранены!';
            }
            elseif ($this->view_mode == 'save_options') {
                setGlobal('oref_alert.enabled',         isset($_POST['enabled'])         ? 1 : 0);
                setGlobal('oref_alert.notify_flag',     isset($_POST['notify_flag'])     ? 1 : 0);
                setGlobal('oref_alert.tts_flag',        isset($_POST['tts_flag'])        ? 1 : 0);
                setGlobal('oref_alert.auto_close_flag', isset($_POST['auto_close_flag']) ? 1 : 0);
                setGlobal('oref_alert.debug_log',       isset($_POST['debug_log'])       ? 1 : 0);
                
                // === ПРАВКА #2 и #3: Жесткие лимиты для автоотбоя (10-20) и истории (2-24) ===
                if (isset($_POST['auto_close_time']))   setGlobal('oref_alert.auto_close_time', min(20, max(10, intval($_POST['auto_close_time']))));
                if (isset($_POST['hist_period']))       setGlobal('oref_alert.hist_period',     min(24, max(2, intval($_POST['hist_period']))));
                
                $out['OK_MSG'] = 'Опции модуля сохранены!';
            }
            elseif ($this->view_mode == 'save_categories') {
                $catIds =[1,2,3,4,7,8,9,10,11,12,13,14,0];
                foreach ($catIds as $cid) {
                    if (isset($_POST["cat_name_$cid"]))  setGlobal("oref_alert.cat_name_$cid",  trim($_POST["cat_name_$cid"]));
                    if (isset($_POST["cat_img_$cid"]))   setGlobal("oref_alert.cat_img_$cid",   trim($_POST["cat_img_$cid"]));
                    if (isset($_POST["cat_instr_$cid"])) setGlobal("oref_alert.cat_instr_$cid", trim($_POST["cat_instr_$cid"]));
                }
                $out['OK_MSG'] = 'Категории угроз успешно сохранены!';
            }
            elseif ($this->view_mode == 'save_code') {
                if (isset($_POST['code_alert']))     setGlobal('oref_alert.code_alert',     trim($_POST['code_alert']));
                if (isset($_POST['code_pre_alert'])) setGlobal('oref_alert.code_pre_alert', trim($_POST['code_pre_alert']));
                if (isset($_POST['code_clear']))     setGlobal('oref_alert.code_clear',     trim($_POST['code_clear']));
                if (isset($_POST['code_drill']))     setGlobal('oref_alert.code_drill',     trim($_POST['code_drill']));
                $out['OK_MSG'] = 'Пользовательские сценарии сохранены!';
            }

            $cfg = $this->getConfig();
            $obj = $cfg['OBJ'];
            $this->view_mode = ''; 
            $this->mode = ''; 
        }

        if ($this->view_mode == 'selftest') {
            $r = $this->doSelfTest($cfg);
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
            if ($cfg['NOTIFY'] && function_exists('sendTelegram')) sendTelegram($msg);
            $out['IM_SAFE_MSG'] = $msg;
            $this->view_mode = '';
        }

        // --- ДАННЫЕ ДЛЯ ШАБЛОНА ---
        $out['OBJ_STATUS']      = getGlobal($obj . '.Status')       ?: 'No Alert';
        $out['OBJ_STATE']       = getGlobal($obj . '.state')        ?: 'ok';
        $out['OBJ_CATEGORY']    = getGlobal($obj . '.Category')     ?: '';
        $out['OBJ_NAME']        = getGlobal($obj . '.Name')         ?: '';
        $out['OBJ_CITY']        = getGlobal($obj . '.City')         ?: '';
        $out['OBJ_INSTRUCTIONS']= getGlobal($obj . '.Instructions') ?: 'Оповещений нет';
        $out['OBJ_IMG']         = getGlobal($obj . '.Img')          ?: '/cms/icons/default.png';
        $out['OBJ_HISTORY']     = getGlobal($obj . '.History')      ?: '';
        $out['OBJ_UPTIME']      = getGlobal($obj . '.UpTime')       ?: '—';
        $out['OBJ_COUNT']       = intval(getGlobal($obj . '.Count'));
        $out['OBJ_LAST_ALARM']  = getGlobal($obj . '.LastAlarmTime') ?: '—';
        $out['OBJ_COUNTDOWN']   = $this->calcCountdown($obj);
        $out['OBJ_SHELTER']     = intval(getGlobal($obj . '.ShelterTime'));
        $out['OBJ_MY_AREAS']    = getGlobal('oref_alert.filter_words') ?: '';
        $out['OBJ_ALL_AREAS']   = getGlobal($obj . '.AllActiveAreas') ?: '';
        $out['IS_ALERT']        = ($out['OBJ_STATUS'] === 'Alert')   ? '1' : '';
        $out['LAST_SAFE_DATE']  = intval(getGlobal($obj . '.i_am_safe_ts')) ? date('d.m H:i', intval(getGlobal($obj . '.i_am_safe_ts'))) : '';

        // Конфиг
        $out['CFG_FILTER_WORDS']    = $cfg['FILTER'];
        $out['CFG_OBJECT_NAME']     = $cfg['OBJ'];
        $out['CFG_TRIGGER_METHOD']  = $cfg['TRIGGER'];
        $out['CFG_POLL']            = $cfg['POLL'];
        $out['CFG_HIST_INTERVAL']   = $cfg['HIST_INT'];
        $out['CFG_PROXY']           = $cfg['PROXY'];
        $out['CFG_ENABLED']         = $cfg['ENABLED']    ? '1' : '';
        $out['CFG_NOTIFY']          = $cfg['NOTIFY']     ? '1' : '';
        $out['CFG_TTS']             = $cfg['TTS']        ? '1' : '';
        $out['CFG_AUTO_CLOSE']      = $cfg['AUTO_CLOSE'] ? '1' : '';
        $out['CFG_DEBUG']           = $cfg['DEBUG']      ? '1' : '';
        $out['CFG_HIST_PERIOD']     = $cfg['HIST_PERIOD'];
        $out['CFG_AUTO_CLOSE_TIME'] = $cfg['AUTO_CLOSE_TIME'];

        $out['CODE_ALERT']     = getGlobal('oref_alert.code_alert');
        $out['CODE_PRE_ALERT'] = getGlobal('oref_alert.code_pre_alert');
        $out['CODE_CLEAR']     = getGlobal('oref_alert.code_clear');
        $out['CODE_DRILL']     = getGlobal('oref_alert.code_drill');

        // Категории
        $catIds =[1,2,3,4,7,8,9,10,11,12,13,14,0];
        $catRows =[];
        foreach ($catIds as $cid) {
            $def = OREF_DEFAULT_CATS[$cid] ?? OREF_DEFAULT_CATS[0];
            $catRows[] =[
                'ID'    => $cid,
                'NAME'  => getGlobal("oref_alert.cat_name_$cid")  ?: $def['name'],
                'IMG'   => getGlobal("oref_alert.cat_img_$cid")   ?: $def['img'],
                'INSTR' => getGlobal("oref_alert.cat_instr_$cid") ?: $def['instructions'],
            ];
        }
        $out['CATEGORIES'] = $catRows;

        // История
        $history = $this->loadHistory($cfg['HIST_PERIOD']);
        $histOut =[];
        foreach ($history as $h) {
            $histOut[] =[
                'DATE'     => $h['date']     ?? '',
                'EVENT'    => $h['event']    ?? '',
                'CAT_NAME' => $h['cat_name'] ?? '',
                'MY_AREAS' => $h['my_areas'] ?? '',
                'ALL_AREAS'=> $h['all_areas'] ?? '',
            ];
        }
        $out['HISTORY'] = $histOut;

        $out['CYCLE_STATUS'] = getGlobal('cycle_oref_alertRun') ? 'OK' : 'STOPPED';
    }

    function usual(&$out) {
        $cfg = $this->getConfig();
        $obj = $cfg['OBJ'];
        $out['OBJ_STATUS']      = getGlobal($obj . '.Status')       ?: 'No Alert';
        $out['OBJ_NAME']        = getGlobal($obj . '.Name')         ?: '';
        $out['OBJ_CITY']        = getGlobal($obj . '.City')         ?: '';
        $out['OBJ_CITY_RU']     = getGlobal($obj . '.CityRu')       ?: '';
        $out['OBJ_IMG']         = getGlobal($obj . '.Img')          ?: '/cms/icons/default.png';
        $out['OBJ_INSTRUCTIONS']= getGlobal($obj . '.Instructions') ?: 'Оповещений нет';
        $out['OBJ_HISTORY']     = getGlobal($obj . '.History')      ?: '';
        $out['OBJ_LAST_ALARM']  = getGlobal($obj . '.LastAlarmTime') ?: '—';
        $out['OBJ_COUNTDOWN']   = $this->calcCountdown($obj);
        $out['IS_ALERT']        = (getGlobal($obj . '.Status') === 'Alert') ? '1' : '';
        $out['CFG_OBJECT_NAME'] = $cfg['OBJ'];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // МЕТОДЫ УСТАНОВКИ
    // ═══════════════════════════════════════════════════════════════════════
    function install_classes() {
        $cfgClass = SQLSelectOne("SELECT ID FROM classes WHERE TITLE='OrefConfig'");
        if (!$cfgClass) {
            $cfgClass = array('TITLE' => 'OrefConfig', 'DESCRIPTION' => 'Конфигурация модулей');
            $cfgClass['ID'] = SQLInsert('classes', $cfgClass);
        }
        
        $cfgObj = SQLSelectOne("SELECT ID FROM objects WHERE TITLE='oref_alert'");
        if (!$cfgObj) {
            SQLInsert('objects', array('TITLE' => 'oref_alert', 'CLASS_ID' => $cfgClass['ID'], 'DESCRIPTION' => 'Настройки OrefAlert'));
        }

        $warnClass = SQLSelectOne("SELECT ID FROM classes WHERE TITLE='Warning'");
        if (!$warnClass) {
            $warnClass = array('TITLE' => 'Warning', 'DESCRIPTION' => 'Система оповещений');
            $warnClass['ID'] = SQLInsert('classes', $warnClass);
        }

        $props =['Status', 'state', 'Category', 'Name', 'Instructions', 'Img', 'City', 'CityRu', 'ZoneRu', 'CityID', 'MapData', 'History', 'LastAlarmTime', 'LastAlertTS', 'ShelterTime', 'MyActiveAreas', 'AllActiveAreas', 'Count', 'UpTime', 'Notify', 'Last14Sounded', 'LastDataHash', 'LastHistoryItemTS', 'i_am_safe_ts'];
        foreach ($props as $p) {
            $propRec = SQLSelectOne("SELECT ID FROM properties WHERE TITLE='" . DBSafe($p) . "' AND CLASS_ID=" . $warnClass['ID']);
            if (!$propRec) {
                SQLInsert('properties', array('TITLE' => $p, 'CLASS_ID' => $warnClass['ID'], 'KEEP_HISTORY' => 0));
            }
        }

        $methodRec = SQLSelectOne("SELECT ID FROM methods WHERE TITLE='Trigger' AND CLASS_ID=" . $warnClass['ID']);
        if (!$methodRec) {
            SQLInsert('methods', array('TITLE' => 'Trigger', 'CLASS_ID' => $warnClass['ID'], 'CODE' => '// Метод вызывается при тревоге'));
        }

        $alertObj = SQLSelectOne("SELECT ID, CLASS_ID FROM objects WHERE TITLE='Alert'");
        if (!$alertObj) {
            SQLInsert('objects', array('TITLE' => 'Alert', 'CLASS_ID' => $warnClass['ID'], 'DESCRIPTION' => 'Основной объект OrefAlert'));
        } elseif ($alertObj['CLASS_ID'] == 0) {
            SQLUpdate('objects', array('ID' => $alertObj['ID'], 'CLASS_ID' => $warnClass['ID']));
        }

        if (getGlobal('oref_alert.object_name') === '') setGlobal('oref_alert.object_name', 'Alert');
        if (getGlobal('oref_alert.trigger_method') === '') setGlobal('oref_alert.trigger_method', 'Trigger');
        if (getGlobal('oref_alert.poll_interval') === '') setGlobal('oref_alert.poll_interval', 8);
        if (getGlobal('oref_alert.history_interval') === '') setGlobal('oref_alert.history_interval', 300);
        if (getGlobal('oref_alert.enabled') === '') setGlobal('oref_alert.enabled', 1);
        if (getGlobal('oref_alert.auto_close_flag') === '') setGlobal('oref_alert.auto_close_flag', 1);
        if (getGlobal('oref_alert.auto_close_time') === '') setGlobal('oref_alert.auto_close_time', 10);
        if (getGlobal('oref_alert.hist_period') === '') setGlobal('oref_alert.hist_period', 24);
        if (getGlobal('oref_alert.debug_log') === '') setGlobal('oref_alert.debug_log', 1);
        if (getGlobal('oref_alert.filter_words') === '') setGlobal('oref_alert.filter_words', '');

        $dataDir = DIR_MODULES . 'oref_alert/data/';
        if (!is_dir($dataDir)) @mkdir($dataDir, 0777, true);
    }

    function install($data = '') {
        parent::install();
        $this->install_classes();
    }

    function uninstall() {
        SQLExec("DELETE FROM project_modules WHERE NAME='oref_alert'");
        SQLExec('DROP TABLE IF EXISTS oref_alert');
        parent::uninstall();
    }

    function dbInstall($data) {
        $data = <<<EOD
 oref_alert: ID int(10) unsigned NOT NULL auto_increment
 oref_alert: TITLE varchar(100) NOT NULL DEFAULT ''
EOD;
        parent::dbInstall($data);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ПУБЛИЧНЫЕ МЕТОДЫ
    // ═══════════════════════════════════════════════════════════════════════

    public function getConfig() {
        $gb = function($key, $default = 0) {
            $v = getGlobal($key);
            return ($v === '' || $v === null) ? $default : intval($v);
        };
        // === ПРАВКА #2 и #3: Строгая выдача лимитов при чтении из базы ===
        return[
            'FILTER'    => getGlobal('oref_alert.filter_words'),
            'OBJ'       => getGlobal('oref_alert.object_name')    ?: 'Alert',
            'TRIGGER'   => getGlobal('oref_alert.trigger_method') ?: 'Trigger',
            'POLL'      => max(2,  $gb('oref_alert.poll_interval',    8)),
            'HIST_INT'  => max(60, $gb('oref_alert.history_interval', 300)),
            'PROXY'     => getGlobal('oref_alert.proxy'),
            'ENABLED'   => $gb('oref_alert.enabled',         1),
            'NOTIFY'    => $gb('oref_alert.notify_flag',     0),
            'TTS'       => $gb('oref_alert.tts_flag',        0),
            'AUTO_CLOSE'=> $gb('oref_alert.auto_close_flag', 1),
            'AUTO_CLOSE_TIME' => min(20, max(10, $gb('oref_alert.auto_close_time', 10))),
            'HIST_PERIOD'     => min(24, max(2,  $gb('oref_alert.hist_period',     24))),
            'DEBUG'     => $gb('oref_alert.debug_log',       1),
        ];
    }

    public function getCatSettings($histCat) {
        $def = OREF_HIST_NAMES[$histCat] ?? OREF_DEFAULT_CATS[0];
        return[
            'name'  => getGlobal("oref_alert.cat_name_$histCat")  ?: $def['name'],
            'img'   => getGlobal("oref_alert.cat_img_$histCat")   ?: $def['img'],
            'instr' => getGlobal("oref_alert.cat_instr_$histCat") ?: $def['instructions'],
        ];
    }

    public function histCatToState($cat) {
        if (in_array($cat, OREF_HIST_DRILL_CATS)) return null;       
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
        if (!file_exists($file)) return['error' => 'cities.json not found'];
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

    public function calcCountdown($obj) {
        $status  = getGlobal($obj . '.Status');
        $shelter = intval(getGlobal($obj . '.ShelterTime'));
        $ts      = intval(getGlobal($obj . '.LastAlertTS'));
        if ($status !== 'Alert' || !$shelter || !$ts) return 0;
        return max(-60, $shelter - (time() - $ts));
    }

    public function appendHistory($event, $histCat, $allAreas, $myAreas, $isHistApi = false, $alertDate = null) {
        if (!is_array($allAreas)) $allAreas = [$allAreas];
        if (!is_array($myAreas))  $myAreas  =[$myAreas];

        $names   = $isHistApi ? OREF_HIST_NAMES : OREF_DEFAULT_CATS;
        $def     = $names[$histCat] ??['name' => 'Тревога'];
        $srcDate = $alertDate ?: date('Y-m-d H:i:s');

        $file = DIR_MODULES . 'oref_alert/data/history.json';
        $hist = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) :[];

        $allStr  = implode(',', array_slice($allAreas, 0, 10));
        $cutoff2h = time() - 7200;
        foreach ($hist as $existing) {
            if (intval($existing['cat'] ?? 0) === $histCat
                && ($existing['all_areas'] ?? '') === $allStr
                && strtotime($existing['date'] ?? '') >= $cutoff2h) {
                return;  
            }
        }

        $entry =[
            'date'     => $srcDate,   
            'event'    => $event,
            'cat_name' => $def['name'],
            'cat'      => $histCat,
            'all_areas'=> $allStr,
            'my_areas' => implode(', ', $myAreas),
        ];

        array_unshift($hist, $entry);
        file_put_contents($file, json_encode(array_slice($hist, 0, 500), JSON_UNESCAPED_UNICODE));
    }

    public function loadHistory($hours = 24) {
        $file = DIR_MODULES . 'oref_alert/data/history.json';
        if (!file_exists($file)) return[];
        $d = json_decode(file_get_contents($file), true);
        if (!is_array($d)) return[];
        $cutoff = time() - ($hours * 3600);
        $result =[];
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
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     =>[ 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/121.0 Safari/537.36', 'Referer: https://www.oref.org.il/', 'X-Requested-With: XMLHttpRequest', 'Content-Type: application/json;charset=UTF-8' ],
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_ENCODING => '',
        ]);
        if ($proxy) curl_setopt($ch, CURLOPT_PROXY, $proxy);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw !== false) { $raw = ltrim($raw, "\xEF\xBB\xBF"); $raw = str_replace("\x00", '', trim($raw)); } else { $raw = ''; }
        $empty = ($raw === '' || $raw === '[]' || $raw === 'null');
        return['code' => $code, 'err' => $err, 'raw' => $raw, 'empty' => $empty];
    }

    public function httpGet($url, $proxy = '') {
        $r = $this->httpGetRaw($url, $proxy);
        if ($r['err'] || $r['code'] !== 200) { DebMes("OrefAlert HTTP {$r['code']} err={$r['err']} url={$url}", 'oref_alert'); return null; }
        return $r['empty'] ? null : $r['raw'];
    }
}
?>