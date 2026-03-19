<?php
/**
 * install.php — OrefAlert v21, выполняется при установке пакета
 * @package project
 * @author  Wizard <sergejey@gmail.com>
 * @copyright http://majordomo.smartliving.ru/ (c)
 * @version 5.0
 */

// ─── 1. Директория данных ─────────────────────────────────────────────────────
$dataDir = DIR_MODULES . 'oref_alert/data/';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
    echo "✅ Создана директория data/\n";
}
@chmod($dataDir, 0777);

// ─── 2. Конфиг модуля (только если ещё не задан) ─────────────────────────────
$defaults = array(
    'oref_alert.object_name'      => 'Alert',
    'oref_alert.trigger_method'   => 'Trigger',
    'oref_alert.poll_interval'    => '8',
    'oref_alert.history_interval' => '300',
    'oref_alert.enabled'          => '1',
    'oref_alert.notify_flag'      => '0',
    'oref_alert.tts_flag'         => '0',
    'oref_alert.auto_close_flag'  => '1',
    'oref_alert.debug_log'        => '1',
    'oref_alert.filter_words'     => '',
    'oref_alert.proxy'            => '',
);
foreach ($defaults as $key => $val) {
    if (getGlobal($key) === '' || getGlobal($key) === null) {
        setGlobal($key, $val);
    }
}
echo "✅ Конфиг модуля инициализирован\n";

// ─── 3. Объект Alert — создаём правильно через SQL ───────────────────────────
$objName = 'Alert';

// Ищем класс 'Warning' (создан через import/classes при установке пакета)
$class = SQLSelectOne("SELECT ID FROM classes WHERE TITLE='Warning'");
$classId = !empty($class['ID']) ? intval($class['ID']) : 0;

// Проверяем существует ли объект
$existingObj = SQLSelectOne("SELECT ID, CLASS_ID FROM objects WHERE TITLE='" . DBSafe($objName) . "'");

if (empty($existingObj['ID'])) {
    // Объект не существует — создаём с правильным CLASS_ID
    $newId = SQLInsert('objects', array(
        'TITLE'       => $objName,
        'DESCRIPTION' => 'Мониторинг тревог Пикуд ха-Орэф (OrefAlert)',
        'CLASS_ID'    => $classId,
        'LOCATION_ID' => 0,
        'SYSTEM'      => 0,
    ));
    echo "✅ Объект '{$objName}' создан (ID={$newId}, CLASS_ID={$classId})\n";
} else {
    $objId = intval($existingObj['ID']);
    // Объект существует — обновляем CLASS_ID если он 0 (скрытый)
    if (intval($existingObj['CLASS_ID']) == 0 && $classId > 0) {
        SQLUpdate('objects', array(
            'ID'          => $objId,
            'CLASS_ID'    => $classId,
            'DESCRIPTION' => 'Мониторинг тревог Пикуд ха-Орэф (OrefAlert)',
        ));
        echo "✅ Объект '{$objName}' (ID={$objId}) — CLASS_ID обновлён на {$classId} (теперь виден в браузере)\n";
    } else {
        echo "ℹ️  Объект '{$objName}' уже существует (ID={$objId}, CLASS_ID={$existingObj['CLASS_ID']})\n";
    }
}

// ─── 4. Свойства объекта через setGlobal ─────────────────────────────────────
// setGlobal автоматически создаёт свойство если его нет
$initProps = array(
    'Status'              => 'No Alert',
    'Name'                => '',
    'Instructions'        => 'Оповещений нет',
    'Img'                 => '/img/modules/oref_alert.png',
    'Category'            => '',
    'City'                => '',
    'CityRu'              => '',
    'ZoneRu'              => '',
    'CityID'              => '',
    'MapData'             => '',
    'History'             => '',
    'LastAlarmTime'       => '',
    'LastAlertTS'         => '0',
    'ShelterTime'         => '0',
    'MyActiveAreas'       => '',
    'Count'               => '0',
    'UpTime'              => '',
    'Notify'              => '0',
    'Last14Sounded'       => '0',
    'state'               => 'ok',
    'LastDataHash'        => '',
    'LastHistoryUpdateTS' => '0',
);

$created = 0;
foreach ($initProps as $prop => $val) {
    $cur = getGlobal($objName . '.' . $prop);
    if ($cur === '' || $cur === null) {
        setGlobal($objName . '.' . $prop, $val);
        $created++;
    }
}
echo "✅ Установлено {$created} начальных значений свойств объекта '{$objName}'\n";

// ─── 5. Инструкция ───────────────────────────────────────────────────────────
echo "\n";
echo "═══════════════════════════════════════════════════\n";
echo "  OrefAlert v5 установлен!\n";
echo "═══════════════════════════════════════════════════\n";
echo "Следующие шаги:\n";
echo "1. Объекты → найдите объект 'Alert' (класс Warning)\n";
echo "   Метод Trigger уже создан — добавьте свои действия\n";
echo "2. OrefAlert → Настройки → укажите ваши города\n";
echo "3. Система → Циклы → cycle_oref_alert → Запустить\n";
echo "4. Карта: http://[ip]/cms/oref_map.php\n";
echo "═══════════════════════════════════════════════════\n";
