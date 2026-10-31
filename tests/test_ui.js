// Exercise the actual inline UI with a small DOM, without npm packages or a browser service.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const page = fs.readFileSync('plugin/ThermalrightDigital.page', 'utf8');
const script = page.match(/<script>([\s\S]*?)<\/script>/)[1];
const token = 'native-token/+=';
const flush = () => new Promise(resolve => setImmediate(resolve));
const jsonResponse = data => ({status:200,ok:true,text:async()=>JSON.stringify(data)});

function harness(status) {
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
  assert.equal(ui.node('td-backend').textContent,'Arrêté');
  for(const id of ['temp','load','frequency','power']) assert.equal(ui.node('td-'+id).textContent,'Indisponible');
  assert.doesNotMatch(ui.node('td-message').textContent,/CSRF/);
  assert.equal(ui.node('td-rgb-mode').value,'static');
  assert.equal(ui.node('td-rgb-color').value,'#00b4ff');
  assert.equal(ui.node('td-frequency-mode').value,'max');
  assert.equal(ui.node('td-power-sensor').value,'auto');
  for (const z of ['temperature','load','power','frequency','decoration']) assert.equal(ui.node(`td-rgb-temperature-zone-${z}`).checked,true);
  console.log('PASS UI : jeton natif conservé dans formulaire/en-tête ; anciens réglages et mesures indisponibles');

  const failures=[
    [{status:500,ok:false,text:async()=>''},/Réponse vide.*HTTP 500/],
    [{status:502,ok:false,text:async()=>'<html>Bad gateway</html>'},/JSON invalide.*HTTP 502/],
    [{status:403,ok:false,text:async()=>JSON.stringify({error:'Jeton CSRF invalide'})},/Jeton CSRF invalide/],
    [{status:200,ok:true,redirected:true,url:'http://unraid/login',text:async()=>'<html>Login</html>'},/Session Unraid expirée/],
    [{status:200,ok:true,text:async()=>'null'},/JSON inattendue/],
    [{status:500,ok:false,text:async()=>JSON.stringify({error:'Erreur PHP interne dans control.php:4'})},/Erreur PHP interne dans control.php:4/]
  ];
  for(const [response,expected] of failures) {
    ui.setResponder(()=>response);
    await ui.poll();
    assert.match(ui.node('td-message').textContent,expected);
    console.log('PASS UI erreur : '+ui.node('td-message').textContent);
  }
  ui.resetResponder();
  await ui.poll();
  assert.equal(ui.node('td-backend').textContent,'Arrêté');
  assert.doesNotMatch(ui.node('td-message').textContent,/Erreur|Réponse|Session/);
  console.log('PASS UI : reprise après réponse serveur réparée');

  const zones=['temperature','load','power','frequency','decoration'];
  const powerId='0123456789abcdef01234567';
  const rgb={mode:'temperature',color:'#123456',brightness:72,period:8.5,target:'digits',thresholds:[35,55,75],temperature_colors:['#111111','#222222','#333333','#444444'],temperature_zones:['temperature','power'],smoothing:2.5,zone_colors:Object.fromEntries(zones.map((z,i)=>[z,'#'+String(i+1).repeat(6)]))};
  const modern={...status,
    settings:{autostart:true,interval:2,sensor:'cpu-sensor',frequency_mode:'average',power_sensor:powerId,rgb},
    metrics:{cpu_temp:38.75,cpu_load:5,cpu_mhz:5500,cpu_watts:0,sensor:'k10temp — Tctl',frequency_mode:'average',frequency_source:'scaling_cur_freq',frequency_kind:'requested',power_source:'CPU package'},
    backend:{ready:true,timestamp:Date.now()/1000,model:'PA140 Digital',capabilities:{power:false,frequency:true,zones:true,decoration:false,zone_names:['temperature','load','power','frequency']}},
    supervisor:true,sensors:[{id:'cpu-sensor',label:'k10temp — Tctl',value:38.75}],
    metric_sources:{power:[{id:powerId,label:'CPU package',kind:'power',available:false}]}
  };
  const configured=harness(modern);
  await flush();
  assert.equal(configured.node('td-frequency').textContent,'5500 MHz');
  assert.match(configured.node('td-frequency-summary').textContent,/Moyenne.*fréquence demandée/);
  assert.equal(configured.node('td-frequency-source').textContent,'scaling_cur_freq');
  assert.equal(configured.node('td-power').textContent,'0.0 W');
  assert.equal(configured.node('td-power-source').textContent,'CPU package');
  assert.equal(configured.node('td-temp').textContent,'38.8 °C');
  assert.match(configured.node('td-temp-source').textContent,/k10temp/);
  assert.match(configured.node('td-capabilities').textContent,/Watts : non pris en charge ; MHz : pris en charge/);
  assert.match(configured.node('td-rgb-capabilities').textContent,/température, charge, watts, MHz/);
  assert.match(configured.node('td-rgb-capabilities').textContent,/décorations ne sont pas pilotables/);
  assert.equal(configured.node('td-rgb-temperature').hidden,false);
  assert.equal(configured.node('td-rgb-zones').hidden,false);
  assert.equal(configured.node('td-rgb-zone-temperature').disabled,true);
  assert.equal(configured.node('td-rgb-zone-power').disabled,true);
  assert.equal(configured.node('td-rgb-zone-frequency').disabled,false);
  assert.match(configured.node('td-rgb-temperature-summary').textContent,/température, watts/);
  assert.equal(configured.node('td-rgb-brightness-value').textContent,'72 %');
  assert.equal(configured.node('td-power-sensor').value,powerId);
  assert.match(configured.node('td-power-sensor').options.find(o=>o.value===powerId).text,/indisponible/);
  await configured.fire('td-form','submit');
  const firstSave=configured.requests.find(r=>r.body.get('action')==='settings');
  assert.deepEqual(JSON.parse(firstSave.body.get('settings')),modern.settings);
  console.log('PASS UI : rendu watts/MHz, source demandée, capacités physiques et restitution exacte des réglages RGB');

  await configured.fire('td-temperature-only','click');
  for(const z of zones) assert.equal(configured.node(`td-rgb-temperature-zone-${z}`).checked,z==='temperature');
  assert.equal(configured.node('td-rgb-target').value,'all');
  assert.equal(configured.node('td-rgb-zone-temperature').disabled,true);
  for(const z of zones.filter(z=>z!=='temperature')) assert.equal(configured.node(`td-rgb-zone-${z}`).disabled,false);
  assert.match(configured.node('td-rgb-temperature-summary').textContent,/Dégradé actif sur : température\./);
  assert.equal(configured.node('td-rgb-zone-note-power').textContent,'Couleur fixe');
  await configured.poll();
  assert.equal(configured.node('td-rgb-temperature-zone-power').checked,false);
  await configured.fire('td-form','submit');
  const temperatureSave=configured.requests.filter(r=>r.body.get('action')==='settings').at(-1);
  assert.deepEqual(JSON.parse(temperatureSave.body.get('settings')).rgb.temperature_zones,['temperature']);
  assert.deepEqual(JSON.parse(temperatureSave.body.get('settings')).rgb.zone_colors,rgb.zone_colors);
  console.log('PASS UI : température seule dynamique, autres couleurs fixes et sélection conservée pendant le polling');

  configured.node('td-rgb-target').value='decoration';
  await configured.fire('td-rgb-target','change');
  assert.equal(configured.node('td-rgb-zone-temperature').disabled,false);
  assert.match(configured.node('td-rgb-temperature-summary').textContent,/Aucune catégorie dans le périmètre/);
  configured.node('td-rgb-temperature-zone-decoration').checked=true;
  await configured.fire('td-rgb-temperature-zone-decoration','change');
  assert.equal(configured.node('td-rgb-zone-decoration').disabled,true);
  assert.match(configured.node('td-rgb-temperature-summary').textContent,/Dégradé actif sur : décorations\./);
  console.log('PASS UI : périmètre général et catégories dynamiques affichés sans ambiguïté');

  for(const z of zones) configured.node(`td-rgb-temperature-zone-${z}`).checked=false;
  const beforeInvalid=configured.requests.filter(r=>r.body.get('action')==='settings').length;
  await configured.fire('td-form','submit');
  assert.equal(configured.requests.filter(r=>r.body.get('action')==='settings').length,beforeInvalid);
  assert.match(configured.node('td-message').textContent,/Choisissez au moins une catégorie/);
  await configured.fire('td-temperature-only','click');
  console.log('PASS UI : sélection dynamique vide refusée avant l’envoi');

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
  assert.equal(configured.node('td-power').textContent,'Indisponible');
  assert.equal(configured.node('td-frequency').textContent,'Indisponible');
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
  console.log('PASS UI : polling sans perte du brouillon et sérialisation des nouvelles valeurs');

  modern.backend.test_active=true;
  await configured.fire('td-display-test','click');
  const tests=configured.requests.filter(r=>r.body.get('action')==='display-test');
  assert.equal(tests.length,1);
  assert.equal(tests[0].body.has('settings'),false);
  assert.equal(configured.requests.filter(r=>r.body.get('action')==='settings').length,3);
  assert.equal(configured.node('td-rgb-zone-power').value,'#abcdef');
  assert.match(configured.node('td-test-state').textContent,/Test visuel en cours.*chiffres artificiels.*8 secondes.*restent réelles/);
  modern.backend.test_active=false;
  await configured.poll();
  assert.match(configured.node('td-test-state').textContent,/retour automatique.*réglages enregistrés.*ne sont pas enregistrées/);
  configured.setResponder(opts=>opts.body.get('action')==='display-test'?{status:409,ok:false,text:async()=>JSON.stringify({error:'Démarrez le backend avant le test.'})}:jsonResponse(opts.body.get('action')==='status'?modern:{logs:''}));
  await configured.fire('td-display-test','click');
  assert.equal(configured.node('td-message').textContent,'Démarrez le backend avant le test.');
  assert.ok(configured.buttons.every(b=>b.disabled===false));
  configured.setResponder(opts=>opts.body.get('action')==='display-test'?jsonResponse({message:'Test lancé.'}):{status:500,ok:false,text:async()=>''});
  await configured.fire('td-display-test','click');
  assert.match(configured.node('td-message').textContent,/Réponse vide.*HTTP 500/);
  console.log('PASS UI : test temporaire sans sauvegarde du brouillon, état visuel et erreur action conservée');

  const missing=harness({...status,settings:{...status.settings,power_sensor:powerId}});
  await flush();
  assert.equal(missing.node('td-power-sensor').value,powerId);
  assert.equal(missing.node('td-power-sensor').options.find(o=>o.value===powerId).text,'Source configurée disparue');
  console.log('PASS UI : source configurée disparue conservée explicitement');
})().catch(error=>{console.error(error);process.exit(1);});
