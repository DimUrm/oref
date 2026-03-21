<?php
$dictionary = array(
    'OA_STATUS_ALERT'      => 'התרעה',
    'OA_STATUS_NO_ALERT'   => 'שקט',
    'OA_STATUS_PRE_ALERT'  => 'אזהרה מוקדמת',
    'OA_TAB_STATUS'        => 'סטטוס',
    'OA_TAB_SETTINGS'      => 'הגדרות',
    'OA_TAB_CATEGORIES'    => 'קטגוריות',
    'OA_TAB_CODE'          => 'פעולות',
    'OA_SAVE'              => 'שמור',
    'OA_IM_SAFE'           => 'אני בממ"ד',
    'OA_COUNTDOWN'         => 'זמן לממ"ד',
    'OA_ALL_QUIET'         => 'הכל שקט',
);
foreach ($dictionary as $k => $v) { if (!defined('LANG_' . $k)) { define('LANG_' . $k, $v); } }