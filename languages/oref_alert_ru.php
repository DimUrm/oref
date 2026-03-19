<?php
/**
 * OrefAlert — Russian language file
 * @package project
 */
$dictionary = array(
    'OA_STATUS_ALERT'       => 'Тревога',
    'OA_STATUS_NO_ALERT'    => 'Спокойно',
    'OA_STATUS_PRE_ALERT'   => 'Предупреждение',
    'OA_TAB_STATUS'         => 'Статус',
    'OA_TAB_SETTINGS'       => 'Настройки',
    'OA_TAB_CATEGORIES'     => 'Категории',
    'OA_TAB_CODE'           => 'Действия',
    'OA_SAVE'               => 'Сохранить',
    'OA_SELFTEST'           => 'Проверить API',
    'OA_IM_SAFE'            => 'Я в безопасности',
    'OA_IM_SAFE_BTN'        => 'Отправить',
    'OA_OPEN_MAP'           => 'Открыть карту',
    'OA_COUNTDOWN'          => 'Время до укрытия',
    'OA_LAST_ALARM'         => 'Последняя тревога',
    'OA_HISTORY_LABEL'      => 'История за час',
    'OA_ALL_QUIET'          => 'Всё спокойно',
    'OA_CYCLE_STATUS'       => 'Цикл',
    'OA_FILTER_WORDS'       => 'Мои зоны (иврит, через запятую)',
    'OA_FILTER_HINT'        => 'Поиск работает на русском, иврите и английском. 1450 городов с временем укрытия.',
    'OA_OBJECT_NAME'        => 'Имя объекта MajorDoMo',
    'OA_OBJECT_HINT'        => 'Заполняются свойства Alert.Status, .Name, .City, .MapData и другие.',
    'OA_TRIGGER_METHOD'     => 'Имя метода Trigger',
    'OA_TRIGGER_HINT'       => 'Вызывается как Объект.Метод при каждом изменении статуса.',
    'OA_POLL_INTERVAL'      => 'Интервал опроса (сек)',
    'OA_POLL_HINT'          => 'Логика 2/8: при изменении данных — 2 сек, иначе — это значение. При тревоге всегда 2 сек.',
    'OA_HIST_INTERVAL'      => 'Обновление истории (сек)',
    'OA_PROXY'              => 'HTTP прокси',
    'OA_PROXY_HINT'         => 'Нужен если сервер MajorDoMo находится вне Израиля.',
    'OA_ENABLED'            => 'Модуль включён',
    'OA_NOTIFY_TG'          => 'Telegram уведомления',
    'OA_TTS'                => 'Голосовые оповещения TTS',
    'OA_AUTO_CLOSE'         => 'Автоотбой через 10 минут',
    'OA_BUILTIN_DATA'       => 'Встроенные данные',
    'OA_BUILTIN_HINT'       => 'cities.json и polygons.json уже находятся в modules/oref_alert/data/. Карта с полигоном открывается кнопкой «Открыть карту».',
    'OA_PROPS_TITLE'        => 'Свойства объекта (справочник)',
    'OA_CAT_HINT'           => 'Настройте русские названия, инструкции и иконки для каждой категории тревоги.',
    'OA_CAT_NUM'            => 'Кат.',
    'OA_CAT_NAME'           => 'Название (рус.)',
    'OA_CAT_ICON'           => 'Иконка (путь)',
    'OA_CAT_INSTR'          => 'Инструкция (рус.)',
    'OA_CODE_HINT'          => 'PHP-код выполняется после вызова Alert.Trigger(). Переменные: $obj (имя объекта), $city (район иврит), $histCat (категория), $cfg (массив конфига).',
    'OA_CODE_ALERT'         => 'При тревоге (статус → Alert)',
    'OA_CODE_PRE'           => 'При предупреждении (кат. 14)',
    'OA_CODE_CLEAR'         => 'При отбое (→ No Alert)',
    'OA_CODE_DRILL'         => 'При учениях (кат. 15+)',
    'OA_WATCHER_TITLE'      => 'Автовсплытие карты — настройка',
    'OA_DEBUG' => 'Логирование в журнал событий (DebMes)',
    'OA_TOTAL_ALERTS'       => 'Тревог всего',
    'OA_UPDATED'            => 'Обновлено',
    'OA_HISTORY_TITLE'      => 'История событий',
    'OA_NO_HISTORY'         => 'История пуста',
);

foreach ($dictionary as $k => $v) {
    if (!defined('LANG_' . $k)) {
        define('LANG_' . $k, $v);
    }
}
