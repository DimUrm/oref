<?php
/**
 * oref_alert_widgets.js.php — виджеты для Dashboard MajorDoMo
 *
 * Виджет "OrefAlert Status" — компактный виджет в стиле Warning.
 * Добавляется на Dashboard через Система → Dashboard → Добавить виджет.
 */
?>
registerWidget('oref_alert', {
    title: 'OrefAlert',
    description: 'Мониторинг тревог Пикуд ха-Орэф',
    icon: '/img/modules/oref_alert.png',

    fields: [
        {
            name: 'object_name',
            title: 'Имя объекта',
            type: 'text',
            defaultValue: 'Alert'
        },
        {
            name: 'show_map_btn',
            title: 'Кнопка карты',
            type: 'checkbox',
            defaultValue: true
        }
    ],

    render: function(params) {
        var obj   = params.object_name || 'Alert';
        var mapBtn= params.show_map_btn ? true : false;

        return `
<style>
.oa-w{width:100%;background:linear-gradient(160deg,#0f1923,#162030);border:1px solid rgba(255,255,255,.07);
  border-radius:12px;overflow:hidden;font-family:'Segoe UI',sans-serif;box-shadow:0 4px 16px rgba(0,0,0,.4)}
.oa-w::before{content:'';display:block;height:3px;background:linear-gradient(90deg,#2a9df4,#56ccf2)}
.oa-w.alert::before{background:linear-gradient(90deg,#ff4444,#ff8c00);animation:oa-wp 1.2s infinite}
@keyframes oa-wp{0%,100%{opacity:1}50%{opacity:.5}}
.oa-wr{display:flex;align-items:center;padding:10px 12px;gap:10px}
.oa-wi{width:48px;height:48px;border-radius:50%;background:#1e2d3d;overflow:hidden;
  border:2px solid rgba(42,157,244,.5);flex-shrink:0}
.oa-w.alert .oa-wi{border-color:rgba(255,68,68,.7)}
.oa-wi img{width:100%;height:100%;object-fit:cover}
.oa-wt{flex:1;min-width:0}
.oa-ws{font-size:.72rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;
  color:#2a9df4;margin-bottom:1px}
.oa-w.alert .oa-ws{color:#ff6b6b}
.oa-wn{font-size:.95rem;font-weight:700;color:#e8edf2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.oa-wc{font-size:.78rem;color:#ffaa44;direction:rtl}
.oa-wb{padding:6px 12px 8px;border-top:1px solid rgba(255,255,255,.05);
  display:flex;align-items:center;justify-content:space-between}
.oa-wcd{font-size:1.4rem;font-weight:900;color:#ff4444;font-variant-numeric:tabular-nums}
.oa-wmap{font-size:.7rem;color:#2a9df4;text-decoration:none;
  background:rgba(42,157,244,.1);border:1px solid rgba(42,157,244,.3);
  border-radius:4px;padding:3px 8px;white-space:nowrap}
.oa-wmap:hover{background:rgba(42,157,244,.2)}
</style>

<div class="oa-w" id="oaW_${obj}">
  <div class="oa-wr">
    <div class="oa-wi"><img id="oaWI_${obj}" src="/cms/icons/default.png"></div>
    <div class="oa-wt">
      <div class="oa-ws" id="oaWS_${obj}">Загрузка...</div>
      <div class="oa-wn" id="oaWN_${obj}">OrefAlert</div>
      <div class="oa-wc" id="oaWC_${obj}"></div>
    </div>
  </div>
  <div class="oa-wb">
    <div id="oaWCD_${obj}" style="display:none">
      <div style="font-size:.6rem;color:#8fafc7;text-transform:uppercase;letter-spacing:.08em">⏱ до укрытия</div>
      <div class="oa-wcd" id="oaWCDN_${obj}">—</div>
    </div>
    <span id="oaWTime_${obj}" style="font-size:.65rem;color:#4a6a8a"></span>
    ${mapBtn ? `<a class="oa-wmap" href="/cms/oref_map.php" target="_blank">🗺️ Карта</a>` : ''}
  </div>
</div>

<script>
(function(){
  var OBJ='${obj}', last=null, cdTimer=null;
  var w=document.getElementById('oaW_'+OBJ);
  if(!w) return;

  function g(p){return fetch('/objects/?op=get&object='+OBJ+'&p='+p,{cache:'no-store'}).then(r=>r.text()).then(v=>v.trim());}

  function startCd(shelter, ts){
    if(cdTimer)clearInterval(cdTimer);
    if(!shelter||!ts){document.getElementById('oaWCD_'+OBJ).style.display='none';return;}
    document.getElementById('oaWCD_'+OBJ).style.display='block';
    function tick(){
      var rem=parseInt(shelter)-(Math.floor(Date.now()/1000)-parseFloat(ts));
      var el=document.getElementById('oaWCDN_'+OBJ);
      if(rem>-60){el.textContent=rem+' сек';}
      else{el.textContent='—';clearInterval(cdTimer);}
    }
    tick(); cdTimer=setInterval(tick,1000);
  }

  function update(){
    Promise.all(['Status','Name','CityRu','Img','ShelterTime','LastAlertTS','LastAlarmTime']
      .map(p=>g(p))).then(([status,name,cityRu,img,shelter,lts,lastAlarm])=>{
      w.className='oa-w'+(status==='Alert'?' alert':'');
      document.getElementById('oaWS_'+OBJ).textContent=status==='Alert'?'🚨 ТРЕВОГА':'✅ Спокойно';
      document.getElementById('oaWN_'+OBJ).textContent=name||'OrefAlert';
      document.getElementById('oaWC_'+OBJ).textContent=cityRu||'';
      if(img){document.getElementById('oaWI_'+OBJ).src=img;}
      document.getElementById('oaWTime_'+OBJ).textContent=lastAlarm?'⏱ '+lastAlarm:'';
      if(status==='Alert')startCd(shelter,lts);
      else{if(cdTimer)clearInterval(cdTimer);document.getElementById('oaWCD_'+OBJ).style.display='none';}
    });
  }

  function poll(){g('Status').then(s=>{if(s!==last){last=s;update();}});}
  update(); setInterval(poll,3000);
})();
</script>
`;
    }
});
