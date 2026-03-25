<?php
/**
 * oref_map.php — Kiosk-карта тревог OrefAlert
 * @version 1.44
 */
chdir(dirname(__FILE__) . '/../../');
include_once('./config.php');
include_once('./lib/loader.php');
include_once('./load_settings.php');
include_once(DIR_MODULES . 'oref_alert/oref_alert.class.php');
$_oref_tmp = new oref_alert();
$_oref_cfg = $_oref_tmp->getConfig();
$objName = $_oref_cfg['OBJECT_NAME'] ?? 'Alarm';

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>OrefAlert — Монитор управления</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box}
html,body{width:100%;height:100%;overflow:hidden;background:#050a0f;color:#e8edf2;font-family:'Segoe UI',sans-serif}

#main-container { display: flex; width: 100%; height: 100%; position: relative; }

/* Карта */
#map { flex: 1; z-index: 1; transition: filter 1.2s ease, opacity 1.2s ease, transform 1.2s ease; }

/* Боковая панель */
#side-panel {
    width: 320px; background: #0f1923; border-left: 1px solid rgba(255,255,255,0.1);
    display: flex; flex-direction: column; z-index: 5; padding: 15px; transition: all 0.8s ease;
}
.side-title { font-size: 0.7rem; color: #8fafc7; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 15px; border-bottom: 1px solid #1e2d3d; padding-bottom: 5px; }
#recent-list { flex: 1; overflow-y: auto; }

/* Стили группировки в панели */
.side-group { margin-bottom: 20px; animation: slideIn 0.3s ease-out; }
.side-cat-title { font-size: 0.85rem; font-weight: bold; color: #8fafc7; margin-bottom: 8px; border-left: 3px solid #ff4444; padding-left: 8px; text-transform: uppercase; }
.side-cities-list { direction: rtl; font-size: 1.1rem; color: #ffaa44; line-height: 1.4; padding-right: 5px; }
.side-sep { height: 1px; background: rgba(255,255,255,0.05); margin-top: 15px; border-bottom: 1px dashed rgba(255,255,255,0.1); }

/* РЕЖИМ ТИШИНЫ (QUIET) */
body.quiet #map { filter: blur(40px) grayscale(0.8); opacity: 0.3; transform: scale(1.05); }
body.quiet #side-panel { filter: blur(10px); opacity: 0.2; pointer-events: none; }
body.quiet #standby { opacity: 1; pointer-events: auto; }

/* ЭКРАН ОЖИДАНИЯ (STANDBY) */
#standby { 
    position: absolute; inset: 0; z-index: 100; 
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    background: transparent; opacity: 0; pointer-events: none; transition: opacity 1s ease;
}

.floating-box { display: flex; flex-direction: column; align-items: center; animation: moveAround 60s linear infinite alternate; }
@keyframes moveAround { 0% { transform: translate(-15%, -15%); } 33% { transform: translate(15%, -5%); } 66% { transform: translate(-5%, 15%); } 100% { transform: translate(10%, 10%); } }

.sb-clock { font-size: 6rem; font-weight: 200; color: #e8edf2; line-height: 1; margin-bottom: 15px; letter-spacing: -2px; }
.sb-status-box { display: flex; align-items: center; gap: 15px; background: rgba(68,255,136,0.1); border: 1px solid rgba(68,255,136,0.5); border-radius: 50px; padding: 12px 35px; box-shadow: 0 0 30px rgba(68,255,136,0.1); }
.sb-dot { width: 14px; height: 14px; background: #44ff88; border-radius: 50%; animation: pulse 2s infinite; }
@keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(68,255,136,0.7); } 70% { box-shadow: 0 0 0 15px rgba(68,255,136,0); } 100% { box-shadow: 0 0 0 0 rgba(68,255,136,0); } }
.sb-ok-text { color: #44ff88; font-weight: 800; letter-spacing: 0.15em; font-size: 1.3rem; text-transform: uppercase; }
#sHistory { margin-top: 30px; max-width: 700px; text-align: center; color: rgba(143,175,199,0.5); font-size: 1rem; direction: rtl; line-height: 1.5; }

/* МОДАЛЬНОЕ ОКНО (ALERTS) */
.alert-modal-overlay {
    position: fixed; inset: 0; background-color: rgba(0, 0, 0, 0.92);
    z-index: 1000; display: none; justify-content: center; align-items: center; backdrop-filter: blur(15px);
}
.alert-modal-window {
    width: 90%; height: 85%; background: #1a0000; border: 6px solid #ff3333;
    border-radius: 25px; box-shadow: 0 0 100px rgba(255, 0, 0, 0.8);
    position: relative; overflow: hidden; display: flex; flex-direction: column;
}
.modal-header { padding: 30px; background: linear-gradient(90deg, #550000, #1a0000); border-bottom: 4px solid #ff3333; display: flex; align-items: center; gap: 30px; }
.modal-siren { font-size: 5rem; animation: siren-spin 1s linear infinite; }
@keyframes siren-spin { 0% { filter: hue-rotate(0deg); } 100% { filter: hue-rotate(360deg); } }
.modal-main-title { font-size: 3.5rem; font-weight: 900; color: #ff4444; line-height: 1; }
.modal-city-name { font-size: 2.2rem; color: #fff; font-weight: bold; margin-top: 5px; }
.modal-countdown { font-size: 10rem; font-weight: 900; color: #ffaa44; text-align: center; font-variant-numeric: tabular-nums; line-height: 1; }

#flash { position: fixed; inset: 0; z-index: 10000; background: rgba(255,0,0,0.6); pointer-events: none; opacity: 0; }
#flash.on { animation: fa .8s ease-out forwards; }
@keyframes fa { 0% { opacity: 1; } 100% { opacity: 0; } }

@keyframes slideIn { from { transform: translateX(20px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
</style>
</head>
<body class="quiet"> 

<div id="flash"></div>

<div id="standby">
    <div class="floating-box">
        <div style="font-size: 4rem; margin-bottom: 20px;">🇮🇱</div>
        <div class="sb-clock" id="sClock">00:00:00</div>
        <div class="sb-status-box">
            <div class="sb-dot"></div>
            <div class="sb-ok-text">Всё в порядке</div>
        </div>
        <div id="sHistory"></div>
    </div>
</div>

<div id="main-container">
    <div id="map"></div>
    <div id="side-panel">
        <div class="side-title">Тревоги в стране (60 сек)</div>
        <div id="recent-list"></div>
        <div style="margin-top:auto; font-size:0.65rem; color:#4a6a8a; border-top:1px solid #1e2d3d; padding-top:10px; text-align: center;">
            OrefAlert Kiosk v6.9 | OLED Safe
        </div>
    </div>
</div>

<div class="alert-modal-overlay" id="alertModal">
    <div class="alert-modal-window">
        <button style="position:absolute; top:25px; right:25px; background:#ff3333; color:#fff; border:none; padding:12px 30px; font-size: 1.2rem; font-weight:bold; cursor:pointer; border-radius:8px; z-index:1100;" onclick="closeModal()">ЗАКРЫТЬ X</button>
        <div class="modal-header">
            <div class="modal-siren">🚨</div>
            <div>
                <div class="modal-main-title">ТРЕВОГА</div>
                <div class="modal-city-name" id="mCityName">—</div>
            </div>
        </div>
        <div style="flex:1; display:flex; flex-direction:column; justify-content:center; background:#000;">
            <div style="text-align:center; color:#8fafc7; text-transform:uppercase; letter-spacing:0.4em; font-size:1.5rem; margin-bottom: 10px;">До укрытия</div>
            <div class="modal-countdown" id="mCdNum">00</div>
            <div id="mInstr" style="text-align:center; padding:40px; font-size:2.2rem; color:#ffaa44; font-weight:600; line-height: 1.3;"></div>
        </div>
    </div>
</div>

<script>
var OBJ = '<?php echo htmlspecialchars($objName); ?>';
var POLL = 2000;
var ISRAEL_CENTER = [31.5, 34.8];
var ISRAEL_ZOOM = 8;

var map = L.map('map', {zoomControl:true, attributionControl:false});
L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {maxZoom:19}).addTo(map);

var activePolygons = {};
var lastSidePanelHTML = ""; // Для предотвращения мерцания

function addCityToMap(cityName, isMyCity) {
    if (activePolygons[cityName]) return;
    fetch('oref_cities.php?op=get_map_data&q=' + encodeURIComponent(cityName))
    .then(r => r.json())
    .then(d => {
        if (d.polygon && d.polygon.length > 2) {
            var color = isMyCity ? '#ff3333' : '#ff9900';
            var poly = L.polygon(d.polygon.map(p => [p[0], p[1]]), {
                color: color, fillColor: color, fillOpacity: 0.35, weight: isMyCity ? 5 : 2
            }).addTo(map);
            activePolygons[cityName] = poly;
            updateMapFocus(); // Пересчитываем фокус при добавлении
        }
    }).catch(e => {});
}

function syncPolygons(activeCitiesList, myCitiesList) {
    var changed = false;
    for (var name in activePolygons) {
        if (!activeCitiesList.includes(name)) {
            map.removeLayer(activePolygons[name]);
            delete activePolygons[name];
            changed = true;
        }
    }
    activeCitiesList.forEach(name => {
        if (!activePolygons[name]) {
            addCityToMap(name, myCitiesList.includes(name));
            changed = true;
        }
    });
    if (changed && activeCitiesList.length === 0) {
        // Если все полигоны удалены - возвращаемся к общему виду
        map.flyTo(ISRAEL_CENTER, ISRAEL_ZOOM, {duration: 2});
    }
}

function updateMapFocus() {
    var group = new L.featureGroup(Object.values(activePolygons));
    if (Object.keys(activePolygons).length > 0) {
        map.flyToBounds(group.getBounds(), {padding: [100, 100], duration: 1.5});
    }
}

var cdTimer = null;
function startCd(shelter, ts) {
    if (cdTimer) clearInterval(cdTimer);
    function tick() {
        var rem = parseInt(shelter) - (Math.floor(Date.now()/1000) - parseFloat(ts));
        var el = document.getElementById('mCdNum');
        if (rem > -60) { el.textContent = rem; el.style.color = rem <= 10 ? '#ff0000' : '#ffaa44'; }
        else { el.textContent = '00'; clearInterval(cdTimer); }
    }
    tick(); cdTimer = setInterval(tick, 1000);
}

function updateClock() {
    var n = new Date();
    document.getElementById('sClock').textContent = n.toLocaleTimeString('ru-RU', {hour: '2-digit', minute: '2-digit', second: '2-digit'});
}
setInterval(updateClock, 1000);
updateClock();

function closeModal() { document.getElementById('alertModal').style.display = 'none'; }

function poll() {
    var props = ['Status','Instructions','ShelterTime','LastAlertTS','MyActiveAreas','HistoryData','History'];
    Promise.all(props.map(p => 
        fetch('/objects/?op=get&object='+OBJ+'&p='+p, {cache:'no-store'}).then(r => r.text()).then(v => v.trim())
    )).then(v => {
        var d = { status: v[0], instr: v[1], shelter: v[2], ts: v[3], myActiveStr: v[4], histRaw: v[5], countryHist: v[6] };
        var nowTS = Math.floor(Date.now() / 1000);
        var currentActiveCities = [];
        var myCitiesList = d.myActiveStr ? d.myActiveStr.split(',').map(s => s.trim()) : [];
        try {
            var history = JSON.parse(d.histRaw);
            history.forEach(h => {
                var hts = Math.floor(new Date(h.date).getTime() / 1000);
                if ((nowTS - hts) < 120 && h.event === 'alert') {
                    h.all_areas.split(',').forEach(area => {
                        var name = area.trim();
                        if (!currentActiveCities.includes(name)) currentActiveCities.push(name);
                    });
                }
            });
        } catch(e) {}

        if (currentActiveCities.length === 0 && d.status !== 'Alert') { 
            document.body.classList.add('quiet');
        } else { 
            document.body.classList.remove('quiet'); 
        }
        
        document.getElementById('sHistory').textContent = d.countryHist;
        syncPolygons(currentActiveCities, myCitiesList);

        if (d.status === 'Alert' && d.myActiveStr !== "") {
            if (document.getElementById('alertModal').style.display !== 'flex') {
                document.getElementById('flash').classList.add('on');
                setTimeout(() => document.getElementById('flash').classList.remove('on'), 800);
            }
            document.getElementById('alertModal').style.display = 'flex';
            document.getElementById('mCityName').textContent = d.myActiveStr;
            document.getElementById('mInstr').textContent = d.instr;
            startCd(d.shelter, d.ts);
        } else { closeModal(); }
        updateSidePanel(d.histRaw);
    });
}

function updateSidePanel(histJson) {
    if (!histJson) return;
    try {
        var history = JSON.parse(histJson);
        var now = Math.floor(Date.now() / 1000);
        var grouped = {};
        var hasAny = false;
        history.filter(h => {
            var hts = Math.floor(new Date(h.date).getTime() / 1000);
            return (now - hts) < 60 && h.event === 'alert';
        }).forEach(h => {
            if (!grouped[h.cat_name]) grouped[h.cat_name] = new Set();
            h.all_areas.split(',').forEach(area => grouped[h.cat_name].add(area.trim()));
            hasAny = true;
        });

        var recentHtml = "";
        if (hasAny) {
            for (var cat in grouped) {
                recentHtml += `<div class="side-group">
                    <div class="side-cat-title">${cat}:</div>
                    <div class="side-cities-list">${Array.from(grouped[cat]).sort().join('<br>')}</div>
                    <div class="side-sep"></div>
                </div>`;
            }
        } else {
            recentHtml = "<div style='color:#4a6a8a; font-size:0.75rem; text-align:center; margin-top:20px;'>Активных тревог нет</div>";
        }

        // РЕШЕНИЕ ПРОБЛЕМЫ МЕРЦАНИЯ: Обновляем DOM только если HTML изменился
        if (recentHtml !== lastSidePanelHTML) {
            document.getElementById('recent-list').innerHTML = recentHtml;
            lastSidePanelHTML = recentHtml;
        }
    } catch(e) {}
}

map.setView(ISRAEL_CENTER, ISRAEL_ZOOM);
setInterval(poll, POLL);
poll();
</script>
</body>
</html>