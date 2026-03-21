<?php
/**
 * oref_map.php — Kiosk-карта тревог OrefAlert
 */
chdir(dirname(__FILE__) . '/../');
include_once('./config.php');
include_once('./lib/loader.php');
include_once('./load_settings.php');

$objName = getGlobal('oref_alert.object_name') ?: 'Alert';

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>OrefAlert — Карта тревог</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box}
html,body{width:100%;height:100%;overflow:hidden;background:#0a1018;color:#e8edf2;font-family:'Segoe UI',sans-serif}
#standby{position:absolute;inset:0;z-index:10;background:linear-gradient(160deg,#0a1018,#0f1923);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:20px;transition:opacity .6s}
#standby.hidden{opacity:0;pointer-events:none}
.sb-flag{font-size:5rem;animation:float 3s ease-in-out infinite}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
.sb-dot{width:10px;height:10px;border-radius:50%;background:#44ff88;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(68,255,136,.4)}50%{box-shadow:0 0 0 8px rgba(68,255,136,0)}}
.sb-status{display:flex;align-items:center;gap:10px;background:rgba(68,255,136,.08);border:1px solid rgba(68,255,136,.25);border-radius:24px;padding:8px 20px}
.sb-ok{font-size:1.1rem;font-weight:700;color:#44ff88;letter-spacing:.04em}
.sb-clock{font-size:3.5rem;font-weight:300;color:#8fafc7;letter-spacing:.1em;font-variant-numeric:tabular-nums}
.sb-date{font-size:.9rem;color:#4a6a8a;letter-spacing:.06em}
.sb-hist{max-width:500px;text-align:center;font-size:.8rem;color:#4a6a8a;border-top:1px solid rgba(255,255,255,.05);padding-top:14px;direction:rtl;line-height:1.6}
#alert-screen{position:absolute;inset:0;z-index:20;display:flex;flex-direction:column;opacity:0;pointer-events:none;transition:opacity .4s}
#alert-screen.show{opacity:1;pointer-events:all}
.al-hdr{flex-shrink:0;background:linear-gradient(90deg,#1a0000,#2a0505);border-bottom:3px solid #ff3333;padding:10px 16px;display:flex;align-items:center;gap:12px;animation:hblink 1.4s infinite}
@keyframes hblink{0%,100%{border-color:#ff3333}50%{border-color:#ff8800}}
.al-hdr.pre{background:linear-gradient(90deg,#1a1000,#2a1a00);border-color:#ffaa44;animation:none}
.al-hdr.ok{background:linear-gradient(90deg,#001a06,#002a0a);border-color:#44ff88;animation:none}
.al-siren{font-size:2.4rem;animation:spin 1s linear infinite}
@keyframes spin{0%{filter:hue-rotate(0)}100%{filter:hue-rotate(360deg)}}
.al-hdr.ok .al-siren,.al-hdr.pre .al-siren{animation:none}
.al-title{font-size:1.6rem;font-weight:900;letter-spacing:.06em;color:#ff4444;text-transform:uppercase;text-shadow:0 0 20px rgba(255,68,68,.4);animation:tp 1s infinite}
@keyframes tp{0%,100%{opacity:1}50%{opacity:.7}}
.al-hdr.ok .al-title{color:#44ff88;animation:none;text-shadow:none}
.al-hdr.pre .al-title{color:#ffaa44;animation:none;text-shadow:none}
.al-info{flex:1;min-width:0}
.al-name{font-size:1rem;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.al-zone{font-size:.75rem;color:#8fafc7}
.al-img{width:52px;height:52px;object-fit:contain;background:#1e2d3d;border-radius:8px;padding:4px}
.cd-bar{flex-shrink:0;display:flex;align-items:center;gap:16px;background:rgba(255,50,50,.1);border-bottom:1px solid rgba(255,50,50,.2);padding:8px 16px}
.cd-bar.hidden{display:none}
.cd-lbl{font-size:.6rem;color:#8fafc7;text-transform:uppercase;letter-spacing:.1em}
.cd-num{font-size:2.4rem;font-weight:900;color:#ff4444;font-variant-numeric:tabular-nums;line-height:1.1}
.cd-city-ru{font-size:1rem;font-weight:700;color:#ffaa44}
.cd-city-he{font-size:.8rem;color:#8fafc7;direction:rtl}
.al-instr{font-size:.85rem;color:#c9a84c;background:rgba(255,180,0,.07);border-left:3px solid #c9a84c;padding:4px 10px;border-radius:0 4px 4px 0;margin-top:2px}
#map{flex:1;z-index:1}
.al-footer{flex-shrink:0;display:flex;justify-content:space-between;align-items:center;background:#0a1018;border-top:1px solid rgba(255,255,255,.06);padding:5px 16px;font-size:.7rem}
.al-fl{color:#4a90d9}.al-fr{color:#4a6a8a}
#flash{position:fixed;inset:0;z-index:100;background:rgba(255,50,50,.7);pointer-events:none;opacity:0}
#flash.on{animation:fa .8s ease-out forwards}
@keyframes fa{0%{opacity:.7}100%{opacity:0}}
</style>
</head>
<body>
<div id="flash"></div>

<div id="standby">
  <div class="sb-flag">🇮🇱</div>
  <div class="sb-clock" id="sClock">00:00:00</div>
  <div class="sb-date" id="sDate"></div>
  <div class="sb-status"><div class="sb-dot"></div><div class="sb-ok">Всё спокойно</div></div>
  <div class="sb-hist" id="sHist" style="display:none"><span id="sHistTxt"></span></div>
</div>

<div id="alert-screen">
  <div class="al-hdr" id="alHdr">
    <div class="al-siren" id="alSiren">🚨</div>
    <div><div class="al-title" id="alTitle">ТРЕВОГА</div></div>
    <div class="al-info">
      <div class="al-name" id="alName">—</div>
      <div class="al-zone" id="alZone"></div>
    </div>
    <img id="alImg" class="al-img" src="" onerror="this.style.display='none'">
  </div>

  <div class="cd-bar hidden" id="cdBar">
    <div><div class="cd-lbl">⏱ до укрытия</div><div class="cd-num" id="cdNum">—</div></div>
    <div>
      <div class="cd-city-ru" id="cdCityRu"></div>
      <div class="cd-city-he" id="cdCityHe"></div>
      <div class="al-instr" id="alInstr"></div>
    </div>
  </div>

  <div id="map"></div>

  <div class="al-footer">
    <span class="al-fl" id="alFl"></span>
    <span class="al-fr" id="alFr"></span>
  </div>
</div>

<script>
var OBJ  = '<?php echo htmlspecialchars($objName); ?>';
var POLL = 2000;
var AUTO_FS = true;

var map = L.map('map',{zoomControl:true,attributionControl:false});
L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',{maxZoom:19}).addTo(map);
var polyL=null, mkrL=null, lastMapJson='';

function drawPoly(json){
  if(json===lastMapJson)return; lastMapJson=json;
  if(polyL){map.removeLayer(polyL);polyL=null;}
  if(mkrL){map.removeLayer(mkrL);mkrL=null;}
  if(!json)return;
  var d; try{d=JSON.parse(json);}catch(e){return;}
  if(d.lat&&d.lng){
    mkrL=L.circleMarker([d.lat,d.lng],{radius:9,color:'#ff4444',fillColor:'#ff4444',fillOpacity:.9,weight:2}).addTo(map);
    mkrL.bindTooltip('<b>'+(d.name_ru||d.name_he||'')+'</b>'+(d.zone_ru?'<br><small>'+d.zone_ru+'</small>':''),{permanent:false,direction:'top'});
  }
  if(d.polygon&&d.polygon.length>2){
    polyL=L.polygon(d.polygon.map(function(p){return[p[0],p[1]];}),{color:'#ff4444',fillColor:'#ff4444',fillOpacity:.2,weight:2.5,dashArray:'6,4'}).addTo(map);
    map.fitBounds(polyL.getBounds(),{padding:[40,40],maxZoom:14});
  } else if(d.lat&&d.lng){map.setView([d.lat,d.lng],13);}
}

var cdTimer=null;
function startCd(shelter,ts){
  if(cdTimer){clearInterval(cdTimer);cdTimer=null;}
  var s=parseInt(shelter)||0, t=parseFloat(ts)||0;
  if(!s||!t){document.getElementById('cdBar').classList.add('hidden');return;}
  document.getElementById('cdBar').classList.remove('hidden');
  function tick(){
    var rem=s-(Math.floor(Date.now()/1000)-t);
    var el=document.getElementById('cdNum');
    if(rem>-60){el.textContent=rem+' сек';el.style.color=rem<=10?'#ff8800':'#ff4444';}
    else{el.textContent='—';clearInterval(cdTimer);cdTimer=null;}
  }
  tick(); cdTimer=setInterval(tick,1000);
}
function stopCd(){if(cdTimer){clearInterval(cdTimer);cdTimer=null;}document.getElementById('cdBar').classList.add('hidden');}

function updateClock(){
  var n=new Date();
  document.getElementById('sClock').textContent=('0'+n.getHours()).slice(-2)+':'+('0'+n.getMinutes()).slice(-2)+':'+('0'+n.getSeconds()).slice(-2);
  document.getElementById('sDate').textContent=n.toLocaleDateString('ru-RU',{weekday:'long',day:'numeric',month:'long'});
}
setInterval(updateClock,1000); updateClock();

function goFS(){if(!AUTO_FS)return;var e=document.documentElement;(e.requestFullscreen||e.webkitRequestFullscreen||function(){}).call(e);}
function exitFS(){if(document.fullscreenElement||(document.webkitFullscreenElement))(document.exitFullscreen||document.webkitExitFullscreen||function(){}).call(document);}

var curMode='standby', lastStatus=null, init=true;

function showAlert(d){
  var isAlert=d.status==='Alert', isPre=(d.cat==14);
  if(curMode!=='alert'){document.getElementById('flash').classList.remove('on');void document.getElementById('flash').offsetWidth;document.getElementById('flash').classList.add('on');goFS();curMode='alert';}
  document.getElementById('standby').classList.add('hidden');
  document.getElementById('alert-screen').classList.add('show');
  var hdr=document.getElementById('alHdr');
  hdr.className='al-hdr'+(isPre?' pre':(!isAlert?' ok':''));
  document.getElementById('alSiren').textContent=isAlert?'🚨':(isPre?'⚡':'✅');
  document.getElementById('alTitle').textContent=isAlert?'ТРЕВОГА':(isPre?'ПРЕДУПРЕЖДЕНИЕ':'ОТБОЙ');
  document.getElementById('alName').textContent=d.name||'—';
  document.getElementById('alZone').textContent=(d.cityRu&&d.zoneRu)?d.cityRu+' — '+d.zoneRu:(d.cityRu||'');
  document.getElementById('alInstr').textContent=d.instr||'';
  document.getElementById('cdCityRu').textContent=d.cityRu||'';
  document.getElementById('cdCityHe').textContent=d.city||'';
  document.getElementById('alFl').textContent=d.name||'';
  document.getElementById('alFr').textContent='Обновлено: '+(new Date().toLocaleTimeString('ru-RU'));
  if(d.img){var i=document.getElementById('alImg');i.src=d.img;i.style.display='block';}
  if(isAlert&&d.shelter&&d.lastTS)startCd(d.shelter,d.lastTS); else stopCd();
  if(d.mapData)drawPoly(d.mapData);
}
function showClear(d){
  if(curMode==='standby')return;
  showAlert(d);
  setTimeout(function(){backToStandby(d);},8000);
}
function backToStandby(d){
  curMode='standby';
  document.getElementById('alert-screen').classList.remove('show');
  document.getElementById('standby').classList.remove('hidden');
  stopCd();
  if(polyL){map.removeLayer(polyL);polyL=null;}
  if(mkrL){map.removeLayer(mkrL);mkrL=null;}
  lastMapJson=''; map.setView([31.5,34.8],8);
  exitFS();
  if(d&&d.history){document.getElementById('sHistTxt').textContent=d.history;document.getElementById('sHist').style.display=d.history?'block':'none';}
}

function g(p){return fetch('/objects/?op=get&object='+OBJ+'&p='+p,{cache:'no-store'}).then(function(r){return r.text();}).then(function(v){return v.trim();});}

function pollAll(){
  Promise.all(['Status','Name','CityRu','ZoneRu','City','Instructions','Img','MapData','ShelterTime','LastAlertTS','Category','History'].map(g))
  .then(function(v){
    var d={status:v[0],name:v[1],cityRu:v[2],zoneRu:v[3],city:v[4],instr:v[5],img:v[6],mapData:v[7],shelter:v[8],lastTS:v[9],cat:parseInt(v[10])||0,history:v[11]};
    var changed=(d.status!==lastStatus);
    if(changed||init){lastStatus=d.status;init=false;
      if(d.status==='Alert')showAlert(d);
      else if(d.status==='No Alert'&&curMode==='alert')showClear(d);
      else if(curMode==='standby'&&d.history){document.getElementById('sHistTxt').textContent=d.history;document.getElementById('sHist').style.display='block';}
    }
    if(d.status==='Alert'&&d.mapData&&d.mapData!==lastMapJson)drawPoly(d.mapData);
  }).catch(function(e){console.error(e);});
}
function poll(){g('Status').then(function(s){if(s!==lastStatus)pollAll();}).catch(function(){});}

map.setView([31.5,34.8],8);
pollAll();
setInterval(poll,POLL);
document.body.addEventListener('click',function(){if(curMode==='alert'&&AUTO_FS)goFS();});
</script>
</body>
</html>