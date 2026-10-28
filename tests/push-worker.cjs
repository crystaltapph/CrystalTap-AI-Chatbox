const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const listeners = {}, shown = [], opened = [];
const context = {
  CTAI_VERSION:'fixture',CTAI_ASSETS:'https://example.invalid/assets/',URL,
  self:{addEventListener:(type,fn)=>{listeners[type]=fn;},registration:{scope:'https://example.invalid/crystaltap-agent/',showNotification:async(title,options)=>{shown.push({title,...options});}},clients:{matchAll:async()=>[],openWindow:async url=>{opened.push(url);}}}
};
vm.runInNewContext(fs.readFileSync('mobile/sw.js','utf8'),context);
(async()=>{
  let pending;
  listeners.push({data:{json:()=>({tag:'ctai-1',conversation:17,body:'PRIVATE CUSTOMER MESSAGE'})},waitUntil:p=>{pending=p;}});await pending;
  assert.equal(shown[0].body,'A support conversation needs your attention.');
  assert.equal(shown[0].silent,false);
  assert.equal(JSON.stringify(shown).includes('PRIVATE CUSTOMER MESSAGE'),false);
  listeners.push({data:{json:()=>({test:true,conversation:0,tag:'ctai-test'})},waitUntil:p=>{pending=p;}});await pending;
  assert.match(shown[1].body,/Push reached this device/);
  listeners.notificationclick({notification:{close(){},data:shown[0].data},waitUntil:p=>{pending=p;}});await pending;
  assert.equal(opened[0],'https://example.invalid/crystaltap-agent/#conversation/17');
  console.log('5 service-worker checks passed. Physical phone delivery and sound are not simulated.');
})().catch(error=>{console.error(error);process.exitCode=1;});
