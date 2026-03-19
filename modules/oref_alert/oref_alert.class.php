<?php
/**
 * OrefAlert — Мониторинг тревог гражданской обороны Израиля (Пикуд ха-Орэф)
 *
 * Заменяет: AlertSecond + класс Warning + объект Alert
 * Источник логики: amitfin/oref_alert (HA) — 3 API, 1452 района, полигоны
 *
 * @package project
 * @author  Wizard <sergejey@gmail.com>
 * @copyright http://majordomo.smartliving.ru/ (c)
 * @version 5.0
 */

require_once(DIR_MODULES . 'oref_alert/lib/AreaData.php');
require_once(DIR_MODULES . 'oref_alert/lib/CityDb.php');

// ─── Категории (из categories.py amitfin/oref_alert) ─────────────────────────
// realtime cat => [history_cat, is_alert]
define('OREF_RT_TO_HIST', [
    1 => 1, 3 => 7, 4 => 9, 5 => 11, 6 => 2, 7 => 12, 13 => 10,
]);
define('OREF_ALERT_CATS',    [1,2,3,4,7,8,9,10,11,12]);   // history cats = настоящая тревога
define('OREF_PRE_ALERT_CAT', 14);
define('OREF_END_CAT',       13);
define('OREF_FIRST_DRILL',   15);

// Настройки категорий по умолчанию (русский, как в AlertSecond)
define('OREF_DEFAULT_CATS', [
    1  => ['name'=>'Ракетный обстрел',              'img'=>'/cms/icons/missiles.png',               'instructions'=>'Войдите в защищённое пространство и оставайтесь в нём 10 минут'],
    2  => ['name'=>'Нарушение воздушного пространства','img'=>'/cms/icons/hostile_aircraft.png',    'instructions'=>'Пройдите в укрытие и оставайтесь в нём 10 минут'],
    3  => ['name'=>'Нестандартное оружие',          'img'=>'/cms/icons/hazardous_materials.png',    'instructions'=>'Закройте окна, двери и следуйте указаниям властей'],
    4  => ['name'=>'Общее предупреждение',          'img'=>'/cms/icons/default.png',                'instructions'=>'Следуйте указаниям властей'],
    7  => ['name'=>'Землетрясение',                 'img'=>'/cms/icons/earthqwake.png',             'instructions'=>'Немедленно выйдите на открытое пространство'],
    8  => ['name'=>'Землетрясение (вторичная волна)','img'=>'/cms/icons/earthqwake.png',            'instructions'=>'Немедленно выйдите на открытое пространство'],
    9  => ['name'=>'Радиологическое событие',       'img'=>'/cms/icons/hazardous_materials.png',    'instructions'=>'Закройте окна и двери, выключите кондиционер'],
    10 => ['name'=>'Теракт / инфильтрация',         'img'=>'/cms/icons/terrorist_infiltration.png', 'instructions'=>'Войдите в здание, закройте двери и окна'],
    11 => ['name'=>'Угроза цунами',                 'img'=>'/cms/icons/tsunami.png',                'instructions'=>'Уйдите на возвышенность немедленно'],
    12 => ['name'=>'Утечка опасных веществ',        'img'=>'/cms/icons/hazardous_materials.png',    'instructions'=>'Закройте окна и двери'],
    13 => ['name'=>'Отбой тревоги',                 'img'=>'/cms/icons/exit.png',                   'instructions'=>'Вы можете покинуть защищённое пространство'],
    14 => ['name'=>'Предварительное оповещение',    'img'=>'/cms/icons/enter.png',                  'instructions'=>'В ближайшие минуты ожидается тревога'],
    0  => ['name'=>'Тревога',                       'img'=>'/cms/icons/default.png',                'instructions'=>'Следуйте указаниям'],
]);

class oref_alert extends module
{
    function __construct()
    {
        $this->name            = 'oref_alert';
        $this->title           = 'Oref Alert (Служба тыла)';
        $this->module_category = '<#LANG_SECTION_DEVICES#>';
        $this->checkInstalled();
    }

    function saveParams($data = 1)
    {
        $p = [];
        if (isset($this->id))        $p['id']        = $this->id;
        if (isset($this->view_mode)) $p['view_mode']  = $this->view_mode;
        if (isset($this->edit_mode)) $p['edit_mode']  = $this->edit_mode;
        if (isset($this->tab))       $p['tab']        = $this->tab;
        return parent::saveParams($p);
    }

    function getParams()
    {
        global $id, $mode, $view_mode, $edit_mode, $tab;
        if (isset($id))        $this->id        = $id;
        if (isset($mode))      $this->mode      = $mode;
        if (isset($view_mode)) $this->view_mode  = $view_mode;
        if (isset($edit_mode)) $this->edit_mode  = $edit_mode;
        if (isset($tab))       $this->tab        = $tab;
    }

    function run()
    {
        global $session, $ajax, $op, $q, $lat, $lng;

        // Компактный виджет для меню: [#module name="oref_alert" mode="menu"#]
        if ($this->mode == 'menu') {
            $this->usual($out);
            $this->data   = $out;
            $p = new parser(DIR_TEMPLATES . $this->name . '/action_menu.html', $this->data, $this);
            $this->result = $p->result;
            return;
        }


        // index.php?module=oref_alert&action=map
        if ($this->action == 'map') {
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
            }
            $cfg = $this->getConfig();
            $obj = $cfg['OBJ'];
            // Отдаём карту как полную HTML-страницу (output buffering обходим через result)
            ob_start();
            include(DIR_TEMPLATES . $this->name . '/map_popup.html');
            $this->result = ob_get_clean();
            // Выводим напрямую — карта занимает весь экран
            echo $this->result;
            exit;
        }

        // AJAX: autocomplete + GPS
        if ($ajax) {
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            if ($op == 'search_city') {
                // CityDb поддерживает поиск на русском, иврит и английском
                echo json_encode(CityDb::search($q ?: ''), JSON_UNESCAPED_UNICODE);
            }
            if ($op == 'gps_lookup') {
                $cfg = $this->getConfig();
                echo json_encode($this->gpsFindArea(floatval($lat), floatval($lng), $cfg), JSON_UNESCAPED_UNICODE);
            }
            exit;
        }

        $out = [];
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
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
    // ADMIN
    // ═══════════════════════════════════════════════════════════════════════
    function admin(&$out)
    {
        $cfg = $this->getConfig();
        $obj = $cfg['OBJ'];

        // ── Tab: Сохранение основных настроек ─────────────────────────────
        // ── Форма 1: Мои зоны ────────────────────────────────────────────────
        if ($this->view_mode == 'save_zones') {
            setGlobal('oref_alert.filter_words', trim($_POST['filter_words'] ?? ''));
            setGlobal('oref_alert.gps_lat',      trim($_POST['gps_lat']     ?? ''));
            setGlobal('oref_alert.gps_lng',      trim($_POST['gps_lng']     ?? ''));
            $this->redirect('?tab=settings');
            return;
        }

        // ── Форма 2: Объект и метод ───────────────────────────────────────
        if ($this->view_mode == 'save_object') {
            setGlobal('oref_alert.object_name',    trim($_POST['object_name']    ?? '') ?: 'Alert');
            setGlobal('oref_alert.trigger_method', trim($_POST['trigger_method'] ?? '') ?: 'Trigger');
            $this->redirect('?tab=settings');
            return;
        }

        // ── Форма 3: Интервалы и сеть ─────────────────────────────────────
        if ($this->view_mode == 'save_intervals') {
            setGlobal('oref_alert.poll_interval',    max(2,  intval($_POST['poll_interval']    ?? 8)));
            setGlobal('oref_alert.history_interval', max(60, intval($_POST['history_interval'] ?? 300)));
            setGlobal('oref_alert.proxy',            trim($_POST['proxy'] ?? ''));
            $this->redirect('?tab=settings');
            return;
        }

        // ── Форма 4: Опции (чекбоксы) ────────────────────────────────────
        // $_POST не содержит ключ для незатронутого чекбокса → isset = false → 0
        if ($this->view_mode == 'save_options') {
            setGlobal('oref_alert.enabled',         isset($_POST['enabled'])         ? 1 : 0);
            setGlobal('oref_alert.notify_flag',     isset($_POST['notify_flag'])     ? 1 : 0);
            setGlobal('oref_alert.tts_flag',        isset($_POST['tts_flag'])        ? 1 : 0);
            setGlobal('oref_alert.auto_close_flag', isset($_POST['auto_close_flag']) ? 1 : 0);
            setGlobal('oref_alert.debug_log',       isset($_POST['debug_log'])       ? 1 : 0);
            $this->redirect('?tab=settings');
            return;
        }

        // ── Tab: Сохранение категорий ──────────────────────────────────────
        if ($this->view_mode == 'save_categories') {
            $catIds = [1,2,3,4,7,8,9,10,11,12,13,14,0];
            foreach ($catIds as $cid) {
                global ${"cat_name_$cid"}, ${"cat_img_$cid"}, ${"cat_instr_$cid"};
                $n = ${"cat_name_$cid"};
                $i = ${"cat_img_$cid"};
                $ins = ${"cat_instr_$cid"};
                if ($n  !== null) setGlobal("oref_alert.cat_name_$cid",  trim($n));
                if ($i  !== null) setGlobal("oref_alert.cat_img_$cid",   trim($i));
                if ($ins !== null) setGlobal("oref_alert.cat_instr_$cid", trim($ins));
            }
            $this->redirect('?tab=categories');
            return;
        }

        // ── Tab: Сохранение кастомного кода ───────────────────────────────
        if ($this->view_mode == 'save_code') {
            global $code_alert, $code_pre_alert, $code_clear, $code_drill;
            setGlobal('oref_alert.code_alert',     $code_alert     ?: '');
            setGlobal('oref_alert.code_pre_alert', $code_pre_alert ?: '');
            setGlobal('oref_alert.code_clear',     $code_clear     ?: '');
            setGlobal('oref_alert.code_drill',     $code_drill     ?: '');
            $this->redirect('?tab=code');
            return;
        }

        // ── Self-test ──────────────────────────────────────────────────────
        if ($this->view_mode == 'selftest') {
            $r = $this->doSelfTest($cfg);
            $out['SELFTEST_OK']      = $r['ok'] ? '1' : '0';
            $out['SELFTEST_LATENCY'] = $r['latency'];
            $out['SELFTEST_ERROR']   = $r['error'];
        }

        // ── Я в безопасности ──────────────────────────────────────────────
        if ($this->view_mode == 'im_safe') {
            global $safe_msg;
            $msg = '🛡️ Я в безопасности! ' . date('H:i:s');
            if (trim($safe_msg)) $msg .= ' — ' . trim($safe_msg);
            setGlobal($obj . '.i_am_safe_ts', time());
            if ($cfg['NOTIFY'] && function_exists('sendTelegram')) sendTelegram($msg);
            $out['IM_SAFE_MSG'] = $msg;
        }

        // ── Данные для шаблона (все вкладки) ──────────────────────────────

        // Статус объекта
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
        $out['OBJ_MY_AREAS']    = getGlobal($obj . '.MyActiveAreas') ?: '';
        $out['OBJ_ALL_AREAS']   = getGlobal($obj . '.AllActiveAreas') ?: '';
        $out['IS_ALERT']        = ($out['OBJ_STATUS'] === 'Alert')   ? '1' : '';
        $out['LAST_SAFE_DATE']  = intval(getGlobal($obj . '.i_am_safe_ts'))
                                    ? date('d.m H:i', intval(getGlobal($obj . '.i_am_safe_ts'))) : '';

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

        // Кастомный код
        $out['CODE_ALERT']     = getGlobal('oref_alert.code_alert');
        $out['CODE_PRE_ALERT'] = getGlobal('oref_alert.code_pre_alert');
        $out['CODE_CLEAR']     = getGlobal('oref_alert.code_clear');
        $out['CODE_DRILL']     = getGlobal('oref_alert.code_drill');

        // Категории для вкладки
        $catIds = [1,2,3,4,7,8,9,10,11,12,13,14];
        $catRows = [];
        foreach ($catIds as $cid) {
            $def = OREF_DEFAULT_CATS[$cid] ?? OREF_DEFAULT_CATS[0];
            $catRows[] = [
                'ID'    => $cid,
                'NAME'  => getGlobal("oref_alert.cat_name_$cid")  ?: $def['name'],
                'IMG'   => getGlobal("oref_alert.cat_img_$cid")   ?: $def['img'],
                'INSTR' => getGlobal("oref_alert.cat_instr_$cid") ?: $def['instructions'],
            ];
        }
        $out['CATEGORIES'] = $catRows;

        // История
        $history = $this->loadHistory(20);
        $histOut = [];
        foreach ($history as $h) {
            $histOut[] = [
                'DATE'     => $h['date']     ?? '',
                'EVENT'    => $h['event']    ?? '',
                'CAT_NAME' => $h['cat_name'] ?? '',
                'MY_AREAS' => $h['my_areas'] ?? '',
                'ALL_AREAS'=> $h['all_areas'] ?? '',
            ];
        }
        $out['HISTORY'] = $histOut;

        // Цикл
        $out['CYCLE_STATUS'] = getGlobal('cycle_oref_alertStatus') ?: '—';
    }

    function usual(&$out)
    {
        // Фронтенд: показываем статус виджета (без настроек)
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

    /**
     * install() — вызывается при установке модуля.
     * Создаёт директорию data/ если не существует.
     */
    function install($data = '')
    {
        $dataDir = DIR_MODULES . 'oref_alert/data/';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        parent::install();
    }

    /**
     * uninstall() — вызывается при удалении модуля.
     */
    function uninstall()
    {
        parent::uninstall();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ПУБЛИЧНЫЕ МЕТОДЫ (используются циклом)
    // ═══════════════════════════════════════════════════════════════════════

    public function getConfig()
    {
        // Вспомогательная функция: правильный bool из getGlobal
        // getGlobal возвращает строку "0" или "1"; "0" ?: default = default (баг PHP)
        // Используем === '' для проверки "ещё не установлено"
        $gb = function($key, $default = 0) {
            $v = getGlobal($key);
            return ($v === '' || $v === null) ? $default : intval($v);
        };

        return [
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
            'DEBUG'     => $gb('oref_alert.debug_log',       1),
        ];
    }

    /**
     * Получить настройки категории (с fallback на defaults)
     */
    public function getCatSettings($histCat)
    {
        $def = OREF_DEFAULT_CATS[$histCat] ?? OREF_DEFAULT_CATS[0];
        return [
            'name'  => getGlobal("oref_alert.cat_name_$histCat")  ?: $def['name'],
            'img'   => getGlobal("oref_alert.cat_img_$histCat")   ?: $def['img'],
            'instr' => getGlobal("oref_alert.cat_instr_$histCat") ?: $def['instructions'],
        ];
    }

    /**
     * Конвертировать history category → состояние
     * null = учения (игнорировать)
     */
    public function histCatToState($cat)
    {
        if ($cat >= OREF_FIRST_DRILL)    return null;  // учения
        if ($cat === OREF_END_CAT)       return 'no_alert';
        if ($cat === OREF_PRE_ALERT_CAT) return 'pre_alert';
        if (in_array($cat, OREF_ALERT_CATS)) return 'alert';
        return null;
    }

    /**
     * Конвертировать realtime cat → history cat
     */
    public function rtToHistCat($rtCat, $title = '')
    {
        // cat=10 в real-time — читаем title: если есть "בדקות" → pre_alert (14), иначе end (13)
        if ($rtCat === 10) {
            if (mb_strpos($title, 'בדקות', 0, 'UTF-8') !== false) return 14;
            return 13;
        }
        return OREF_RT_TO_HIST[$rtCat] ?? $rtCat;
    }

    /**
     * Получить полные данные города из cities.json по ивр. названию из API
     * Возвращает: ['id','name_ru','zone_ru','lat','lng','countdown'] или null
     */
    public function getCityInfo($cityNameHe)
    {
        return CityDb::findByName($cityNameHe);
    }

    /**
     * Получить данные для popup-карты (city info + polygon JSON)
     * Записывается в Alert.MapData
     */
    public function getMapData($cityNameHe)
    {
        return CityDb::getMapData($cityNameHe);
    }

    /**
     * Поиск ближайшего города по GPS через cities.json
     */
    public function gpsFindArea($lat, $lng, $cfg)
    {
        // Используем cities.json из data/ если есть
        $file = DIR_MODULES . 'oref_alert/data/cities.json';
        if (!file_exists($file)) return ['error' => 'cities.json not found'];
        $arr = json_decode(file_get_contents($file), true);
        if (!is_array($arr)) return ['error' => 'invalid json'];
        $best = null; $bestDist = PHP_INT_MAX;
        foreach ($arr as $c) {
            $clat = floatval($c['lat'] ?? 0);
            $clng = floatval($c['lng'] ?? 0);
            if (!$clat || !$clng) continue;
            $d = sqrt(pow($lat-$clat,2)+pow($lng-$clng,2));
            if ($d < $bestDist) { $bestDist=$d; $best=$c; }
        }
        if (!$best) return ['error'=>'not found'];
        return [
            'he'       => $best['name'],
            'ru'       => $best['name_ru'] ?? $best['name'],
            'countdown'=> intval($best['countdown'] ?? 45),
            'zone_ru'  => $best['zone_ru'] ?? '',
        ];
    }

    /**
     * Рассчитать обратный отсчёт
     */
    public function calcCountdown($obj)
    {
        $status  = getGlobal($obj . '.Status');
        $shelter = intval(getGlobal($obj . '.ShelterTime'));
        $ts      = intval(getGlobal($obj . '.LastAlertTS'));
        if ($status !== 'Alert' || !$shelter || !$ts) return 0;
        return max(-60, $shelter - (time() - $ts));
    }

    /**
     * Добавить запись в историю
     */
    public function appendHistory($event, $histCat, $allAreas, $myAreas)
    {
        $catSet = $this->getCatSettings($histCat);
        $entry  = [
            'date'     => date('Y-m-d H:i:s'),
            'event'    => $event,
            'cat_name' => $catSet['name'],
            'all_areas'=> implode(', ', array_slice($allAreas, 0, 10)),
            'my_areas' => implode(', ', $myAreas),
        ];
        $file = DIR_MODULES . 'oref_alert/data/history.json';
        $hist = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        array_unshift($hist, $entry);
        file_put_contents($file, json_encode(array_slice($hist,0,100), JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    }

    public function loadHistory($limit = 20)
    {
        $file = DIR_MODULES . 'oref_alert/data/history.json';
        if (!file_exists($file)) return [];
        $d = json_decode(file_get_contents($file), true);
        return is_array($d) ? array_slice($d, 0, $limit) : [];
    }

    private function doSelfTest($cfg)
    {
        $t0     = microtime(true);
        $result = $this->httpGetRaw(
            'https://www.oref.org.il/warningMessages/alert/Alerts.json',
            $cfg['PROXY']
        );
        $ms = round((microtime(true) - $t0) * 1000);
        // Alerts.json возвращает пустое тело [] когда тревог нет — это НОРМА (HTTP 200)
        $ok  = ($result['code'] === 200 && $result['err'] === '');
        $msg = $ok
            ? ('HTTP 200 — ' . ($result['empty'] ? 'нет активных тревог (норма)' : 'тревоги активны'))
            : ('HTTP ' . $result['code'] . ($result['err'] ? ' / ' . $result['err'] : ''));
        return ['ok' => $ok, 'latency' => $ms . 'ms', 'error' => $ok ? '' : $msg];
    }

    /**
     * httpGetRaw — низкоуровневый запрос, возвращает полный результат
     * Используется для self-test и внутренне httpGet()
     */
    private function httpGetRaw($url, $proxy = '')
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/121.0 Safari/537.36',
                'Referer: https://www.oref.org.il/',
                'X-Requested-With: XMLHttpRequest',
                'Content-Type: application/json;charset=UTF-8',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING       => '',
        ]);
        if ($proxy) curl_setopt($ch, CURLOPT_PROXY, $proxy);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw !== false) {
            $raw = ltrim($raw, "\xEF\xBB\xBF");
            $raw = str_replace("\x00", '', trim($raw));
        } else {
            $raw = '';
        }
        $empty = ($raw === '' || $raw === '[]' || $raw === 'null');
        return ['code' => $code, 'err' => $err, 'raw' => $raw, 'empty' => $empty];
    }

    public function httpGet($url, $proxy = '')
    {
        $r = $this->httpGetRaw($url, $proxy);
        if ($r['err'] || $r['code'] !== 200) {
            DebMes("OrefAlert HTTP {$r['code']} err={$r['err']} url={$url}", 'oref_alert');
            return null;
        }
        // Пустой ответ = нет тревог = null (цикл продолжает работу нормально)
        return $r['empty'] ? null : $r['raw'];
    }
}
