/**
 * СДЭК Checkout Integration
 * Full-width panel with city search, PVZ list, delivery calculation.
 */
(function () {
  'use strict';
  if (typeof PodvalCDEK === 'undefined' || !PodvalCDEK.enabled) return;
  var CONF = PodvalCDEK;
  var state = { cityCode:'', cityName:'', pvzList:[], selectedPvz:null, deliveryCost:0, periodText:'', timer:null };
  var cdekCard = document.querySelector('[data-value="cdek"]');
  var shipPayRow = document.querySelector('.podval-co__shipping-payment-row');
  if (!cdekCard || !shipPayRow) return;

  /* ── Build panel (inserted AFTER shipping-payment row = full width) ── */
  var panel = document.createElement('div');
  panel.id = 'podval-cdek-panel';
  panel.className = 'podval-cdek-panel';
  panel.style.display = 'none';
  panel.innerHTML =
    '<div class="mcdek-head">' +
      '<h2 class="podval-co__section-title">Доставка СДЭК</h2>' +
      '<div class="mcdek-badge" id="mcdek-badge" style="display:none"><span id="mcdek-cost"></span><span class="mcdek-sep">·</span><span id="mcdek-period"></span></div>' +
    '</div>' +
    '<div class="mcdek-city">' +
      '<label class="podval-co__label" for="mcdek-city-inp">Город доставки <abbr class="required">*</abbr></label>' +
      '<div class="mcdek-city-wrap">' +
        '<input type="text" id="mcdek-city-inp" class="podval-co__input" placeholder="Начните вводить город..." autocomplete="off">' +
        '<div class="mcdek-dd" id="mcdek-dd"></div>' +
      '</div>' +
    '</div>' +
    '<div class="mcdek-pvz-area" id="mcdek-pvz-area" style="display:none">' +
      '<div class="mcdek-pvz-left">' +
        '<label class="podval-co__label">Выберите пункт выдачи <abbr class="required">*</abbr></label>' +
        '<input type="text" id="mcdek-pvz-filter" class="podval-co__input podval-co__input--sm" placeholder="Фильтр по адресу...">' +
        '<div class="mcdek-pvz-list" id="mcdek-pvz-list"></div>' +
      '</div>' +
      '<div class="mcdek-pvz-right">' +
        '<div id="mcdek-map" class="mcdek-map"></div>' +
        '<div class="mcdek-chosen" id="mcdek-chosen" style="display:none">' +
          '<div class="mcdek-chosen-label">Выбранный ПВЗ</div>' +
          '<div class="mcdek-chosen-name" id="mcdek-chosen-name"></div>' +
          '<div class="mcdek-chosen-addr" id="mcdek-chosen-addr"></div>' +
          '<div class="mcdek-chosen-time" id="mcdek-chosen-time"></div>' +
          '<button type="button" class="mcdek-btn-change" id="mcdek-btn-change">Выбрать другой</button>' +
        '</div>' +
      '</div>' +
    '</div>' +
    '<input type="hidden" name="podval_cdek_pvz_code" id="podval-cdek-pvz-code-input">' +
    '<input type="hidden" name="podval_cdek_pvz_address" id="podval-cdek-pvz-address-input">' +
    '<input type="hidden" name="podval_cdek_city" id="podval-cdek-city-input">' +
    '<input type="hidden" name="podval_cdek_city_code" id="podval-cdek-city-code-input">' +
    '<input type="hidden" name="podval_cdek_cost" id="podval-cdek-cost-input">' +
    '<input type="hidden" name="podval_cdek_period" id="podval-cdek-period-input">';
  shipPayRow.parentNode.insertBefore(panel, shipPayRow.nextSibling);

  /* ── Toggle panel on shipping change ── */
  var shipGroup = document.getElementById('podval-shipping-methods');
  if (shipGroup) shipGroup.addEventListener('change', function(){
    var v = (document.querySelector('input[name="podval_shipping"]:checked')||{}).value;
    panel.style.display = v==='cdek' ? 'block' : 'none';
    var af = document.getElementById('podval-address-field');
    if (af && v==='cdek') af.style.display='none';
  });

  /* ── City search ── */
  var cInp = document.getElementById('mcdek-city-inp');
  var cDD  = document.getElementById('mcdek-dd');
  cInp.addEventListener('input', function(){ var v=this.value.trim(); if(v.length<2){cDD.style.display='none';return;} clearTimeout(state.timer); state.timer=setTimeout(function(){doSearch(v);},350); });
  cInp.addEventListener('focus', function(){ if(cDD.children.length && this.value.trim().length>=2) cDD.style.display='block'; });
  document.addEventListener('click', function(e){ if(!e.target.closest('.mcdek-city')) cDD.style.display='none'; });

  function doSearch(q){
    ajax('podval_cdek_city_search',{query:q},function(data){
      if(!data.length){cDD.innerHTML='<div class="mcdek-dd-item mcdek-dd-empty">Город не найден</div>';cDD.style.display='block';return;}
      cDD.innerHTML='';
      data.forEach(function(c){
        var d=document.createElement('div');d.className='mcdek-dd-item';d.textContent=c.label;
        d.addEventListener('click',function(){pickCity(c.code,c.name,c.label);});
        cDD.appendChild(d);
      });
      cDD.style.display='block';
    });
  }

  function pickCity(code,name,label){
    state.cityCode=code; state.cityName=name; cInp.value=label||name; cDD.style.display='none';
    document.getElementById('podval-cdek-city-input').value=name;
    document.getElementById('podval-cdek-city-code-input').value=code;
    state.selectedPvz=null;
    document.getElementById('podval-cdek-pvz-code-input').value='';
    document.getElementById('podval-cdek-pvz-address-input').value='';
    document.getElementById('mcdek-chosen').style.display='none';
    doCalc(code); loadPVZ(code);
  }

  /* ── Calculate ── */
  function doCalc(code){
    var badge=document.getElementById('mcdek-badge');
    badge.style.display='flex'; document.getElementById('mcdek-cost').textContent='Считаем...'; document.getElementById('mcdek-period').textContent='';
    ajax('podval_cdek_calc',{city_code:code},function(d){
      state.deliveryCost=d.delivery_sum; state.periodText=d.period_text;
      var ct=d.is_free?'Бесплатно':d.formatted;
      document.getElementById('mcdek-cost').textContent=ct;
      document.getElementById('mcdek-period').textContent=d.period_text;
      document.getElementById('podval-cdek-cost-input').value=d.delivery_sum;
      document.getElementById('podval-cdek-period-input').value=d.period_text;
      if(typeof window.podvalUpdateShippingCost==='function') window.podvalUpdateShippingCost();
      var desc=cdekCard.querySelector('.podval-co__select-desc');
      if(desc) desc.textContent=ct+(d.period_text?' · '+d.period_text:'');
    }, function(err){
      document.getElementById('mcdek-cost').textContent='Ошибка расчёта';
      document.getElementById('mcdek-period').textContent='';
    });
  }

  /* ── PVZ list ── */
  function loadPVZ(code){
    var area=document.getElementById('mcdek-pvz-area');
    var list=document.getElementById('mcdek-pvz-list');
    area.style.display='grid'; list.innerHTML='<div class="mcdek-pvz-loading">Загружаем ПВЗ...</div>';
    ajax('podval_cdek_pvz_list',{city_code:code},function(data){
      state.pvzList=data; renderPVZ(data); initFilter(); initMap(data);
    }, function(){ list.innerHTML='<div class="mcdek-pvz-empty">Нет ПВЗ в этом городе</div>'; });
  }

  function renderPVZ(pts){
    var list=document.getElementById('mcdek-pvz-list'); list.innerHTML='';
    pts.forEach(function(p){
      var el=document.createElement('div'); el.className='mcdek-pvz-item'; el.dataset.search=(p.name+' '+p.address).toLowerCase();
      el.innerHTML='<div class="mcdek-pvz-name">'+esc(p.name)+'</div><div class="mcdek-pvz-addr">'+esc(p.address)+'</div>'+(p.work_time?'<div class="mcdek-pvz-time">'+esc(p.work_time)+'</div>':'');
      el.addEventListener('click',function(){ pickPVZ(p,el); });
      list.appendChild(el);
    });
  }

  function initFilter(){
    var f=document.getElementById('mcdek-pvz-filter'); if(!f)return; f.value='';
    f.addEventListener('input',function(){ var q=this.value.toLowerCase(); document.querySelectorAll('.mcdek-pvz-item').forEach(function(el){ el.style.display=(el.dataset.search||'').indexOf(q)!==-1?'':'none'; }); });
  }

  function pickPVZ(p,el){
    state.selectedPvz=p;
    document.getElementById('mcdek-chosen-name').textContent=p.name;
    document.getElementById('mcdek-chosen-addr').textContent=p.address;
    document.getElementById('mcdek-chosen-time').textContent=p.work_time||'';
    document.getElementById('mcdek-chosen').style.display='block';
    document.getElementById('podval-cdek-pvz-code-input').value=p.code;
    document.getElementById('podval-cdek-pvz-address-input').value=p.address;
    document.querySelectorAll('.mcdek-pvz-item').forEach(function(e){e.classList.remove('is-active');});
    if(el) el.classList.add('is-active');
    // Center map on selected PVZ
    if(state.map && p.lat && p.lon) {
      state.map.setCenter([p.lat, p.lon], 16, {duration: 300});
    }
  }

  document.getElementById('mcdek-btn-change').addEventListener('click',function(){
    document.getElementById('mcdek-chosen').style.display='none';
    state.selectedPvz=null;
    document.getElementById('podval-cdek-pvz-code-input').value='';
    document.getElementById('podval-cdek-pvz-address-input').value='';
    document.querySelectorAll('.mcdek-pvz-item').forEach(function(e){e.classList.remove('is-active');});
    var pvzList = document.getElementById('mcdek-pvz-list');
    if (pvzList) pvzList.scrollTop = 0;
    var filter = document.getElementById('mcdek-pvz-filter');
    if (filter) { filter.value = ''; filter.focus(); }
    document.querySelectorAll('.mcdek-pvz-item').forEach(function(el){ el.style.display = ''; });
    // Reset map zoom to show all markers
    if(state.map && state.pvzList.length) {
      fitMapToMarkers();
    }
  });

  /* ── Yandex Map ── */
  state.map = null;
  state.mapReady = false;
  state.markers = [];

  function initMap(points) {
    if (!CONF.ymapsKey || typeof ymaps === 'undefined') return;

    var mapEl = document.getElementById('mcdek-map');
    if (!mapEl) return;

    // If map already exists, just update markers
    if (state.map) {
      updateMapMarkers(points);
      return;
    }

    ymaps.ready(function () {
      state.map = new ymaps.Map('mcdek-map', {
        center: [points[0].lat || 53.9, points[0].lon || 27.56],
        zoom: 12,
        controls: ['zoomControl']
      }, {
        suppressMapOpenBlock: true
      });

      state.mapReady = true;
      updateMapMarkers(points);
    });
  }

  function updateMapMarkers(points) {
    if (!state.map) return;

    // Clear old markers
    state.map.geoObjects.removeAll();
    state.markers = [];

    var bounds = [];

    points.forEach(function (p) {
      if (!p.lat || !p.lon) return;

      var placemark = new ymaps.Placemark([p.lat, p.lon], {
        balloonContentHeader: esc(p.name),
        balloonContentBody: esc(p.address) + (p.work_time ? '<br><small>' + esc(p.work_time) + '</small>' : ''),
        hintContent: esc(p.name)
      }, {
        preset: 'islands#circleDotIcon',
        iconColor: '#6c5ce7'
      });

      placemark.pvzData = p;

      placemark.events.add('click', function () {
        // Find matching list item and select it
        var items = document.querySelectorAll('.mcdek-pvz-item');
        items.forEach(function (el) {
          if (el.querySelector('.mcdek-pvz-name') && el.querySelector('.mcdek-pvz-name').textContent === p.name) {
            pickPVZ(p, el);
          }
        });
      });

      state.map.geoObjects.add(placemark);
      state.markers.push(placemark);
      bounds.push([p.lat, p.lon]);
    });

    fitMapToMarkers();
  }

  function fitMapToMarkers() {
    if (!state.map || !state.map.geoObjects.getLength()) return;
    state.map.setBounds(state.map.geoObjects.getBounds(), {
      checkZoomRange: true,
      zoomMargin: 40,
      duration: 300
    });
  }

  /* ── Validation ── */
  var form=document.querySelector('form.woocommerce-checkout');
  if(form) form.addEventListener('submit',function(e){
    var v=(document.querySelector('input[name="podval_shipping"]:checked')||{}).value;
    if(v!=='cdek')return;
    var err=[];
    if(!state.cityCode) err.push('Выберите город доставки');
    if(!state.selectedPvz) err.push('Выберите пункт выдачи СДЭК');
    if(err.length){ e.preventDefault(); e.stopImmediatePropagation(); alert(err.join('\n')); panel.scrollIntoView({behavior:'smooth',block:'center'}); }
  },true);

  /* ── Helpers ── */
  function ajax(action,params,ok,fail){
    var body='action='+action+'&nonce='+encodeURIComponent(CONF.nonce);
    for(var k in params) body+='&'+k+'='+encodeURIComponent(params[k]);
    var x=new XMLHttpRequest(); x.open('POST',CONF.ajaxUrl); x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    x.onload=function(){ try{var r=JSON.parse(x.responseText); if(r.success&&r.data){(ok||function(){})(r.data);}else{(fail||function(){})(r.data);}}catch(e){(fail||function(){})(e);} };
    x.onerror=function(){(fail||function(){})('network');};
    x.send(body);
  }
  function esc(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}
})();
