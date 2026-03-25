/**
 * alert_watcher.js — OrefAlert автовсплытие карты
 *
 * Вставьте этот скрипт на любую страницу MajorDoMo (виджет, дашборд):
 *
 *   <script src="/templates/oref_alert/alert_watcher.js"></script>
 *
 * При тревоге — автоматически открывает/фокусирует окно с картой.
 * При отбое — закрывает окно.
 */
(function() {
  var OBJ        = 'Alarm';
  var MAP_URL    = '/cms/oref_map.php';
  var POLL_MS    = 2500;
  var WIN_NAME   = 'OrefAlertMap';
  var WIN_OPTS   = 'width=900,height=650,resizable=yes,scrollbars=no';

  var lastStatus = null;
  var mapWindow  = null;

  function openMap() {
    if (!mapWindow || mapWindow.closed) {
      mapWindow = window.open(MAP_URL, WIN_NAME, WIN_OPTS);
    } else {
      mapWindow.focus();
    }
  }

  function closeMap() {
    if (mapWindow && !mapWindow.closed) {
      mapWindow.close();
      mapWindow = null;
    }
  }

  function poll() {
    fetch('/objects/?op=get&object=' + OBJ + '&p=Status', {cache:'no-store'})
      .then(function(r) { return r.text(); })
      .then(function(s) {
        s = s.trim();
        if (s !== lastStatus) {
          lastStatus = s;
          if (s === 'Alert') {
            openMap();
          } else if (s === 'No Alert' && mapWindow && !mapWindow.closed) {
            // Оставляем окно открытым — map_popup сам покажет "Отбой" и закроется
            // Если хотите закрывать сразу — раскомментируйте:
            // setTimeout(closeMap, 10000);
          }
        }
      })
      .catch(function() {});
  }

  setInterval(poll, POLL_MS);
  poll();
})();