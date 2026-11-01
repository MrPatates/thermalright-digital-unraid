// Exercise the actual inline UI with a small DOM, without npm packages or a browser service.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

// PHP rendering/escaping is checked separately; use English static labels here.
const page = fs.readFileSync('plugin/ThermalrightDigital.page', 'utf8').replace(/<\?=td_h\('((?:\\.|[^'\\])*)'\)\?>/g,(_,text)=>text.replace(/\\'/g,"'").replace(/\\\\/g,'\\'));
const script = page.match(/<script>([\s\S]*?)<\/script>/)[1];
const token = 'native-token/+=';
const flush = () => new Promise(resolve => setImmediate(resolve));
const jsonResponse = data => ({status:200,ok:true,text:async()=>JSON.stringify(data)});

function harness(status,locale='en_US',overrides={}) {
  const nodes = new Map();
  const attr = (text, name) => (text.match(new RegExp(`(?:^|\\s)${name}="([^"]*)"`)) || [])[1];
  const makeNode = attrs => ({
    value:attr(attrs,'value')||'', options:[], listeners:{}, textContent:'',
    hidden:/(?:^|\s)hidden(?:\s|$)/.test(attrs), checked:false, disabled:false,
    dataset:{action:attr(attrs,'data-action')},
    add(option){this.options.push(option);},
    addEventListener(type,listener){this.listeners[type]=listener;}
  });
  for(const match of page.matchAll(/<([a-z]+)\b([^>]*)>/g)) {
    const id=attr(match[2],'id');
    if(id) nodes.set(id,makeNode(match[2]));
  }
  for(const match of page.matchAll(/<select\b([^>]*)>([\s\S]*?)<\/select>/g)) {
    const select=nodes.get(attr(match[1],'id'));
    for(const option of match[2].matchAll(/<option\b([^>]*)>([^<]*)<\/option>/g)) select.add({value:attr(option[1],'value'),text:option[2]});
    select.value=select.options[0].value;
  }
  const formMarkup=page.match(/<form\b[^>]*>([\s\S]*?)<\/form>/)[1];
  const buttons=[...formMarkup.matchAll(/<button\b([^>]*)>/g)].map(match=>nodes.get(attr(match[1],'id'))||makeNode(match[1]));
  const node=id=>{
    assert.ok(nodes.has(id),'Existing element: '+id);
    return nodes.get(id);
  };
  const requests=[], intervals=[];
  const languages=JSON.parse(fs.readFileSync('plugin/lang/languages.json','utf8'));
  nodes.get('td-i18n').textContent=JSON.stringify({locale,...languages[locale],messages:{...JSON.parse(fs.readFileSync(`plugin/lang/${locale}.json`,'utf8')),...overrides}});
  let responder=opts=>jsonResponse(opts.body.get('action')==='status'?status:{logs:''});
  vm.runInNewContext(script,{
    document:{hidden:false,getElementById:node,querySelector:()=>({value:token}),querySelectorAll:selector=>selector==='[data-action]'?buttons.filter(b=>b.dataset.action):buttons},
    URLSearchParams, Date, setInterval(callback){intervals.push(callback);},
    Option:function(text,value){this.text=text;this.value=value;},
    fetch:async(url,opts)=>{requests.push({url,...opts});return responder(opts);}
  });
  return {
    node,requests,buttons,
    async poll(){intervals[0]();await flush();},
    async fire(id,type){const listener=node(id).listeners[type];assert.equal(typeof listener,'function');listener({preventDefault(){}});await flush();},
    setResponder(fn){responder=fn;},
    resetResponder(){responder=opts=>jsonResponse(opts.body.get('action')==='status'?status:{logs:''});}
  };
}

(async () => {
  const status={unraid:'7.3.4',release:'2026.10.31.4',settings:{autostart:false,interval:1,sensor:'auto'},usb:[],docker:true,supervisor:false,metrics:{cpu_temp:null,cpu_load:null},backend:{},service:{},sensors:[],legacy:{}};
  const ui=harness(status);
  await flush();
  assert.equal(ui.requests.length,2);
  for(const req of ui.requests) {
    assert.equal(req.method,'POST');
    assert.equal(req.credentials,'same-origin');
    assert.equal(req.body.get('csrf_token'),token);
    assert.equal(req.headers['X-Thermalright-CSRF-Token'],token);
  }
  assert.equal(ui.node('td-backend').textContent,'Stopped');
  for(const id of ['temp','load','frequency','power']) assert.equal(ui.node('td-'+id).textContent,'Unavailable');
  assert.doesNotMatch(ui.node('td-message').textContent,/CSRF/);
  assert.equal(ui.node('td-rgb-mode').value,'static');
  assert.equal(ui.node('td-rgb-color').value,'#00b4ff');
  assert.equal(ui.node('td-frequency-mode').value,'max');
  assert.equal(ui.node('td-power-sensor').value,'auto');
  for (const z of ['temperature','load','power','frequency','decoration']) assert.equal(ui.node(`td-rgb-temperature-zone-${z}`).checked,true);
  console.log('PASS UI : native token in form/header; legacy settings and unavailable metrics');

  const failures=[
    [{status:500,ok:false,text:async()=>''},/Empty server response.*HTTP 500/],
    [{status:502,ok:false,text:async()=>'<html>Bad gateway</html>'},/Invalid JSON.*HTTP 502/],
    [{status:403,ok:false,text:async()=>JSON.stringify({error:'Invalid CSRF token'})},/Invalid CSRF token/],
    [{status:200,ok:true,redirected:true,url:'http://unraid/login',text:async()=>'<html>Login</html>'},/Unraid session expired/],
    [{status:200,ok:true,text:async()=>'null'},/Unexpected JSON/],
    [{status:500,ok:false,text:async()=>JSON.stringify({error:'Internal PHP error in control.php:4'})},/Internal PHP error in control.php:4/]
  ];
  for(const [response,expected] of failures) {
    ui.setResponder(()=>response);
    await ui.poll();
    assert.match(ui.node('td-message').textContent,expected);
    console.log('PASS UI error : '+ui.node('td-message').textContent);
  }
  ui.resetResponder();
  await ui.poll();
  assert.equal(ui.node('td-backend').textContent,'Stopped');
  assert.doesNotMatch(ui.node('td-message').textContent,/Error|response|session/);
  console.log('PASS UI : recovery after the server response is fixed');

  const zones=['temperature','load','power','frequency','decoration'];
  const powerId='0123456789abcdef01234567';
  const quietDefaults={enabled:false,days:[1,2,3,4,5,6,7],all_day:false,start:'23:00',end:'07:00'};
  const rgb={mode:'temperature',color:'#123456',brightness:72,period:8.5,target:'digits',thresholds:[35,55,75],temperature_colors:['#111111','#222222','#333333','#444444'],temperature_zones:['temperature','power'],smoothing:2.5,zone_colors:Object.fromEntries(zones.map((z,i)=>[z,'#'+String(i+1).repeat(6)]))};
  const modern={...status,
    settings:{autostart:true,interval:2,sensor:'cpu-sensor',frequency_mode:'average',power_sensor:powerId,rgb,quiet_hours:quietDefaults},
    metrics:{cpu_temp:38.75,cpu_load:5,cpu_mhz:5500,cpu_watts:0,sensor:'k10temp — Tctl',frequency_mode:'average',frequency_source:'scaling_cur_freq',frequency_kind:'requested',power_source:'CPU package'},
    backend:{ready:true,timestamp:Date.now()/1000,model:'PA140 Digital',capabilities:{power:false,frequency:true,zones:true,decoration:false,zone_names:['temperature','load','power','frequency']}},
    supervisor:true,sensors:[{id:'cpu-sensor',label:'k10temp — Tctl',value:38.75}],
    metric_sources:{power:[{id:powerId,label:'CPU package',kind:'power',available:false}]}
  };
  const configured=harness(modern);
  await flush();
  assert.equal(configured.node('td-frequency').textContent,'5500 MHz');
  assert.match(configured.node('td-frequency-summary').textContent,/Average.*driver-requested frequency/);
  assert.equal(configured.node('td-frequency-source').textContent,'scaling_cur_freq');
  assert.equal(configured.node('td-power').textContent,'0.0 W');
  assert.equal(configured.node('td-power-source').textContent,'CPU package');
  assert.equal(configured.node('td-temp').textContent,'38.8 °C');
  assert.match(configured.node('td-temp-source').textContent,/k10temp/);
  assert.match(configured.node('td-capabilities').textContent,/Watts : unsupported ; MHz : supported/);
  assert.match(configured.node('td-rgb-capabilities').textContent,/temperature, load, watts, MHz/);
  assert.match(configured.node('td-rgb-capabilities').textContent,/Decorations cannot be controlled/);
  assert.equal(configured.node('td-rgb-temperature').hidden,false);
  assert.equal(configured.node('td-rgb-zones').hidden,false);
  assert.equal(configured.node('td-rgb-zone-temperature').disabled,true);
  assert.equal(configured.node('td-rgb-zone-power').disabled,true);
  assert.equal(configured.node('td-rgb-zone-frequency').disabled,false);
  assert.match(configured.node('td-rgb-temperature-summary').textContent,/temperature, watts/);
  assert.equal(configured.node('td-rgb-brightness-value').textContent,'72 %');
  assert.equal(configured.node('td-power-sensor').value,powerId);
  assert.match(configured.node('td-power-sensor').options.find(o=>o.value===powerId).text,/unavailable/);
  await configured.fire('td-form','submit');
  const firstSave=configured.requests.find(r=>r.body.get('action')==='settings');
  assert.deepEqual(JSON.parse(firstSave.body.get('settings')),modern.settings);
  console.log('PASS UI : watts/MHz, requested source, physical capabilities and exact RGB settings');

  await configured.fire('td-temperature-only','click');
  for(const z of zones) assert.equal(configured.node(`td-rgb-temperature-zone-${z}`).checked,z==='temperature');
  assert.equal(configured.node('td-rgb-target').value,'all');
  assert.equal(configured.node('td-rgb-zone-temperature').disabled,true);
  for(const z of zones.filter(z=>z!=='temperature')) assert.equal(configured.node(`td-rgb-zone-${z}`).disabled,false);
  assert.match(configured.node('td-rgb-temperature-summary').textContent,/Gradient active on: temperature\./);
  assert.equal(configured.node('td-rgb-zone-note-power').textContent,'Fixed color');
  await configured.poll();
  assert.equal(configured.node('td-rgb-temperature-zone-power').checked,false);
  await configured.fire('td-form','submit');
  const temperatureSave=configured.requests.filter(r=>r.body.get('action')==='settings').at(-1);
  assert.deepEqual(JSON.parse(temperatureSave.body.get('settings')).rgb.temperature_zones,['temperature']);
  assert.deepEqual(JSON.parse(temperatureSave.body.get('settings')).rgb.zone_colors,rgb.zone_colors);
  console.log('PASS UI : dynamic temperature only, fixed other colors and selection preserved during polling');

  configured.node('td-rgb-target').value='decoration';
  await configured.fire('td-rgb-target','change');
  assert.equal(configured.node('td-rgb-zone-temperature').disabled,false);
  assert.match(configured.node('td-rgb-temperature-summary').textContent,/No categories within the selected scope/);
  configured.node('td-rgb-temperature-zone-decoration').checked=true;
  await configured.fire('td-rgb-temperature-zone-decoration','change');
  assert.equal(configured.node('td-rgb-zone-decoration').disabled,true);
  assert.match(configured.node('td-rgb-temperature-summary').textContent,/Gradient active on: decorations\./);
  console.log('PASS UI : effect scope and dynamic categories displayed clearly');

  for(const z of zones) configured.node(`td-rgb-temperature-zone-${z}`).checked=false;
  const beforeInvalid=configured.requests.filter(r=>r.body.get('action')==='settings').length;
  await configured.fire('td-form','submit');
  assert.equal(configured.requests.filter(r=>r.body.get('action')==='settings').length,beforeInvalid);
  assert.match(configured.node('td-message').textContent,/Select at least one category/);
  await configured.fire('td-temperature-only','click');
  console.log('PASS UI : empty dynamic selection rejected before sending');

  configured.node('td-rgb-mode').value='zones';
  await configured.fire('td-rgb-mode','change');
  assert.equal(configured.node('td-rgb-zones').hidden,false);
  assert.equal(configured.node('td-rgb-temperature').hidden,true);
  for(const z of zones) assert.equal(configured.node(`td-rgb-zone-${z}`).disabled,false);
  configured.node('td-rgb-zone-power').value='#abcdef';
  configured.node('td-rgb-brightness').value='34';
  await configured.fire('td-rgb-brightness','input');
  configured.node('td-frequency-mode').value='max';
  configured.node('td-power-sensor').value='off';
  modern.metrics.cpu_watts=null;
  modern.metrics.cpu_mhz=null;
  await configured.poll();
  assert.equal(configured.node('td-power').textContent,'Unavailable');
  assert.equal(configured.node('td-frequency').textContent,'Unavailable');
  assert.equal(configured.node('td-rgb-mode').value,'zones');
  assert.equal(configured.node('td-rgb-zone-power').value,'#abcdef');
  assert.equal(configured.node('td-rgb-brightness').value,'34');
  assert.equal(configured.node('td-frequency-mode').value,'max');
  assert.equal(configured.node('td-power-sensor').value,'off');
  await configured.fire('td-form','submit');
  const saves=configured.requests.filter(r=>r.body.get('action')==='settings');
  const saved=JSON.parse(saves.at(-1).body.get('settings'));
  assert.equal(saved.rgb.mode,'zones');
  assert.equal(saved.rgb.zone_colors.power,'#abcdef');
  assert.equal(saved.rgb.brightness,34);
  assert.equal(saved.frequency_mode,'max');
  assert.equal(saved.power_sensor,'off');
  assert.deepEqual(saved.rgb.thresholds,[35,55,75]);
  assert.deepEqual(saved.rgb.temperature_zones,['temperature']);
  console.log('PASS UI : polling retains drafts and serializes new values');

  modern.backend.test_active=true;
  await configured.fire('td-display-test','click');
  const tests=configured.requests.filter(r=>r.body.get('action')==='display-test');
  assert.equal(tests.length,1);
  assert.equal(tests[0].body.has('settings'),false);
  assert.equal(configured.requests.filter(r=>r.body.get('action')==='settings').length,3);
  assert.equal(configured.node('td-rgb-zone-power').value,'#abcdef');
  assert.match(configured.node('td-test-state').textContent,/Visual test running.*synthetic digits.*8 seconds.*remain real/);
  modern.backend.test_active=false;
  await configured.poll();
  assert.match(configured.node('td-test-state').textContent,/automatic return.*saved settings.*unsaved changes/);
  configured.setResponder(opts=>opts.body.get('action')==='display-test'?{status:409,ok:false,text:async()=>JSON.stringify({error:'Start the backend before testing.'})}:jsonResponse(opts.body.get('action')==='status'?modern:{logs:''}));
  await configured.fire('td-display-test','click');
  assert.equal(configured.node('td-message').textContent,'Start the backend before testing.');
  assert.ok(configured.buttons.every(b=>b.disabled===false));
  configured.setResponder(opts=>opts.body.get('action')==='display-test'?jsonResponse({message:'Test started.'}):{status:500,ok:false,text:async()=>''});
  await configured.fire('td-display-test','click');
  assert.match(configured.node('td-message').textContent,/Empty server response.*HTTP 500/);
  console.log('PASS UI : temporary test without saving draft; visual state and action error retained');

  const missing=harness({...status,settings:{...status.settings,power_sensor:powerId}});
  await flush();
  assert.equal(missing.node('td-power-sensor').value,powerId);
  assert.equal(missing.node('td-power-sensor').options.find(o=>o.value===powerId).text,'Configured source missing');
  console.log('PASS UI : missing configured source retained explicitly');

  assert.equal(ui.node('td-quiet-enabled').checked,false);
  assert.equal(ui.node('td-quiet-options').hidden,true);
  assert.equal(ui.node('td-quiet-start').disabled,true);
  console.log('PASS UI: legacy settings keep scheduled off disabled');
  const scheduled={...modern,
    settings:{...modern.settings,quiet_hours:{...quietDefaults,enabled:true,days:[1,5]}},
    display:{off:true,reason:'schedule',timezone:'Pacific/Auckland',local_time:'Mon 2026-11-02 23:30:00'},
    backend:{...modern.backend,display_off:true,display_reason:'schedule',timestamp:Date.now()/1000}
  };
  const night=harness(scheduled);await flush();
  assert.equal(night.node('td-quiet-options').hidden,false);
  for(const d of [1,2,3,4,5,6,7]) assert.equal(night.node(`td-quiet-day-${d}`).checked,[1,5].includes(d));
  assert.equal(night.node('td-quiet-start').value,'23:00');
  assert.equal(night.node('td-quiet-end').value,'07:00');
  assert.match(night.node('td-quiet-clock').textContent,/Mon 2026-11-02 23:30:00.*Pacific\/Auckland/);
  assert.match(night.node('td-backend').textContent,/Display off.*scheduled/);
  assert.match(night.node('td-quiet-status').textContent,/off by schedule.*Metrics and USB connection remain active/);
  assert.equal(night.node('td-temp').textContent,'38.8 °C');
  console.log('PASS UI: saved schedule, actual display-off state and server timezone are shown');
  night.node('td-quiet-end').value='08:15';night.node('td-quiet-day-2').checked=true;
  scheduled.backend.display_off=false;
  await night.poll();
  assert.equal(night.node('td-quiet-end').value,'08:15');
  assert.equal(night.node('td-quiet-day-2').checked,true);
  assert.equal(night.node('td-quiet-status').textContent,'Display on.');
  await night.fire('td-form','submit');
  const nightSave=JSON.parse(night.requests.find(r=>r.body.get('action')==='settings').body.get('settings'));
  assert.deepEqual(nightSave.quiet_hours,{enabled:true,days:[1,2,5],all_day:false,start:'23:00',end:'08:15'});
  console.log('PASS UI: polling preserves schedule drafts and waits for worker confirmation');
  const beforeScheduleErrors=night.requests.filter(r=>r.body.get('action')==='settings').length;
  for(const d of [1,2,3,4,5,6,7]) night.node(`td-quiet-day-${d}`).checked=false;
  await night.fire('td-form','submit');
  assert.match(night.node('td-message').textContent,/Select at least one day/);
  night.node('td-quiet-day-7').checked=true;night.node('td-quiet-start').value='07:00';night.node('td-quiet-end').value='07:00';
  await night.fire('td-form','submit');
  assert.match(night.node('td-message').textContent,/different off\/on times/);
  assert.equal(night.requests.filter(r=>r.body.get('action')==='settings').length,beforeScheduleErrors);
  console.log('PASS UI: missing weekdays and equal interval times are rejected before sending');
  night.node('td-quiet-all-day').checked=true;await night.fire('td-quiet-all-day','change');
  assert.equal(night.node('td-quiet-times').hidden,true);
  assert.equal(night.node('td-quiet-start').disabled,true);
  await night.fire('td-form','submit');
  const allDaySave=JSON.parse(night.requests.filter(r=>r.body.get('action')==='settings').at(-1).body.get('settings'));
  assert.deepEqual(allDaySave.quiet_hours,{enabled:true,days:[7],all_day:true,start:'07:00',end:'07:00'});
  night.node('td-quiet-enabled').checked=false;await night.fire('td-quiet-enabled','change');
  assert.equal(night.node('td-quiet-options').hidden,true);
  console.log('PASS UI: entire-day mode ignores time fields and schedule can be disabled');

  const languages=JSON.parse(fs.readFileSync('plugin/lang/languages.json','utf8'));
  for(const locale of Object.keys(languages)) {
    const catalog=JSON.parse(fs.readFileSync(`plugin/lang/${locale}.json`,'utf8'));
    const translate=(source,params={})=>catalog[source].replace(/\{([A-Za-z0-9_]+)\}/g,(_,key)=>String(params[key]));
    const localStatus={...scheduled,backend:{...scheduled.backend,display_off:true},settings:{...modern.settings,quiet_hours:{...quietDefaults,enabled:true,days:[1,5]}}};
    const localized=harness(localStatus,locale);await flush();
    assert.equal(localized.node('td-backend').textContent,catalog['Display off — scheduled']);
    assert.equal(localized.node('td-quiet-status').textContent,catalog['Display off by schedule. Metrics and USB connection remain active.']);
    const clockValue=value=>locale==='ar_AR'?'\u2066'+value+'\u2069':value;
    assert.equal(localized.node('td-quiet-clock').textContent,translate('Server time: {time} · {timezone}',{time:clockValue(`${catalog.Mon} 2026-11-02 23:30:00`),timezone:clockValue('Pacific/Auckland')}));
    assert.equal(localized.node('td-frequency').textContent,catalog.Unavailable);
    assert.ok(localized.node('td-version').textContent.endsWith(languages[locale].name));
    await localized.fire('td-temperature-only','click');
    assert.equal(localized.node('td-rgb-temperature-summary').textContent,translate('Gradient active on: {zones}.',{zones:catalog.temperature}));
    await localized.fire('td-form','submit');
    const submitted=JSON.parse(localized.requests.find(r=>r.body.get('action')==='settings').body.get('settings'));
    assert.deepEqual(submitted.quiet_hours,localStatus.settings.quiet_hours);
    assert.deepEqual(submitted.rgb.temperature_zones,['temperature']);
    assert.equal(submitted.frequency_mode,'average');
    for(const error of ['Invalid CSRF token','missing csrf_token','wrong csrf_token','uninitialized csrf_token']) {
      localized.setResponder(()=>({status:403,ok:false,text:async()=>JSON.stringify({error})}));
      await localized.poll();assert.equal(localized.node('td-message').textContent,catalog['Invalid CSRF token']);
    }
    localized.setResponder(()=>({status:500,ok:false,text:async()=>''}));await localized.poll();
    assert.equal(localized.node('td-message').textContent,translate('Empty server response (HTTP {status}). Check the server PHP log.',{status:500}));
    localized.setResponder(()=>({status:500,ok:false,text:async()=>JSON.stringify({error:'Internal PHP error in control.php:4. Check the server PHP log.'})}));await localized.poll();
    assert.equal(localized.node('td-message').textContent,translate('Internal PHP error in {location}. Check the server PHP log.',{location:'control.php:4'}));
    console.log('PASS UI locale '+locale+': localized state, clock, colors and errors; unchanged settings identifiers');
  }
  const attack='<img src=x onerror=alert(1)>';
  const safe=harness({...modern,backend:{...modern.backend,model:attack}},'fr_FR',{'Frames sent — {model}':'{model} transmis'});await flush();
  assert.equal(safe.node('td-backend').textContent,attack+' transmis');
  safe.setResponder(()=>({status:400,ok:false,text:async()=>JSON.stringify({error:attack})}));await safe.poll();
  assert.equal(safe.node('td-message').textContent,attack);
  console.log('PASS UI: translated parameters and unknown server errors remain text nodes');
})().catch(error=>{console.error(error);process.exit(1);});
