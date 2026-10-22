const $ = id => document.getElementById(id);
const store = {
  get(k, d){ try{ const v = localStorage.getItem(k); return v ? JSON.parse(v) : d; }catch(e){ return d; } },
  set(k, v){ try{ localStorage.setItem(k, JSON.stringify(v)); }catch(e){} }
};
let cfg = Object.assign({s:25, b:5, auto:false}, store.get('cfg', {}));
let log = store.get('log', {});          // {"2026-10-06": 75, ...} 勉強分のみ
let mode = 'study', totalSec = 0, remainMs = 0, endTime = null, timerId = null, overStarted = false;   // overStarted: 時間を超えて延長中
const pad = n => String(n).padStart(2, '0');
const dkey = d => d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate());
const fmt = s => pad(Math.floor(s/60)) + ':' + pad(s%60);
const todayMin = () => log[dkey(new Date())] || 0;
const UNIT = new URLSearchParams(location.search).has('test') ? 1 : 60;   // ?test=1 を付けると「1分=1秒」の試運転モード
const CIRC = 2 * Math.PI * 90;           // リングの円周（r=90）
$('ring').style.strokeDasharray = CIRC;
if(!$('over')){                           // timer.php に #over が無くてもエラーで止まらないように補う
  const o = document.createElement('div'); o.id = 'over';
  $('display').after(o);
}
if(!$('quote')){                          // 名言の表示欄（timer.php に無くても自動で作る）
  const q = document.createElement('p'); q.id = 'quote'; q.style.cssText = 'margin:10px 0 0;font-size:.9rem;color:var(--sub);min-height:1.4em';
  $('msg').after(q);
}
const QUOTES = [
  '小さな一歩も、積み重ねれば島になる。',
  '今日の25分が、明日の自信になる。',
  '完璧より、続けることのほうが強い。'
];

function markChips(){
  document.querySelectorAll('.presets .chip').forEach(c =>
    c.classList.toggle('on', +c.dataset.s === cfg.s && +c.dataset.b === cfg.b));
}
function resetTime(){
  totalSec = (mode === 'study' ? cfg.s : cfg.b) * UNIT;
  remainMs = totalSec * 1000; render();
}
function render(){
  const over = remainMs < 0;                                  // 予定時間を超えている
  const sec = Math.ceil(Math.max(0, remainMs)/1000);
  $('display').textContent = fmt(sec);
  const done = over ? 1 : 1 - remainMs/(totalSec*1000);       // 経過割合 0〜1
  $('ring').style.strokeDashoffset = CIRC * (1 - done);
  $('app').dataset.mode = mode;
  $('app').classList.toggle('over', over);
  const overSec = over ? Math.floor(-remainMs/1000) : 0;
  $('over').textContent = over ? '+' + fmt(overSec) + ' 超過' : '';
  $('phase').textContent = mode === 'study' ? '📖 勉強中' : '☕ 休憩中';
  $('today').textContent = todayMin();
  document.title = (timerId ? (over ? '+' + fmt(overSec) : fmt(sec)) + ' ' + (mode==='study'?'勉強':'休憩') + ' - ' : '') + '勉強タイマー';
}
function applyCfg(){
  const clamp = (v, lo, hi, d) => { v = Math.round(Number(v)); return v >= lo ? Math.min(hi, v) : d; };
  cfg.s = clamp($('sMin').value, 1, 180, cfg.s);
  cfg.b = clamp($('bMin').value, 1, 60, cfg.b);
  cfg.auto = $('auto').checked;
  $('sMin').value = cfg.s; $('bMin').value = cfg.b;
  store.set('cfg', cfg);
  markChips();
  if(!timerId) resetTime();
}
function beep(){
  try{
    const ac = new (window.AudioContext||window.webkitAudioContext)();
    [0,0.35,0.7].forEach(t=>{
      const o = ac.createOscillator(), g = ac.createGain();
      o.frequency.value = 880; o.connect(g); g.connect(ac.destination);
      g.gain.setValueAtTime(0.2, ac.currentTime+t);
      o.start(ac.currentTime+t); o.stop(ac.currentTime+t+0.2);
    });
  }catch(e){}
}
function notify(title, body){
  try{ if('Notification' in window && Notification.permission === 'granted') new Notification(title, {body}); }catch(e){}
}
function notifStatus(){
  let t = '非対応のブラウザです';
  try{
    if('Notification' in window){
      t = {granted:'許可済み', denied:'ブロック中（ブラウザ設定で解除）', default:'未許可'}[Notification.permission];
    }
  }catch(e){}
  $('notifState').textContent = t;
}
$('notifBtn').onclick = async () => {
  try{ await Notification.requestPermission(); }catch(e){}
  notifStatus();
  notify('通知テスト', 'この通知が出れば設定OKです');
};

/* ---------- 画面ロック防止（Wake Lock） ---------- */
let wakeLock = null;
function updateLockState(){
  let t = '';
  if(!('wakeLock' in navigator)) t = '⚠️ このブラウザは画面の消灯防止に非対応です';
  else if(timerId) t = wakeLock ? '🔆 タイマー中は画面を消灯しません' : '🔆 消灯防止を準備中…';
  $('lockState').textContent = t;
}
async function lockScreen(){
  try{
    if('wakeLock' in navigator && !wakeLock){
      wakeLock = await navigator.wakeLock.request('screen');
      wakeLock.addEventListener('release', () => { wakeLock = null; updateLockState(); });
    }
  }catch(e){ wakeLock = null; }
  updateLockState();
}
function unlockScreen(){
  try{ if(wakeLock) wakeLock.release(); }catch(e){}
  wakeLock = null; updateLockState();
}
document.addEventListener('visibilitychange', () => {
  if(document.visibilityState === 'visible' && timerId) lockScreen();
});

function start(){
  endTime = Date.now() + remainMs;          // 終了時刻との差で計算（裏タブでもズレない）
  overStarted = false;
  timerId = setInterval(tick, 250);
  $('startBtn').textContent = '一時停止'; $('msg').textContent = '';
  lockScreen();
}
function tick(){
  remainMs = endTime - Date.now();          // 0を下回っても止めず、超過時間として数え続ける
  if(remainMs <= 0 && !overStarted){
    if(cfg.auto){ remainMs = 0; render(); finish(); return; }   // 自動開始ONなら従来どおり次へ
    overStarted = true;
    const study = mode === 'study';
    const msg = study ? '⏰ 予定の時間です。区切りがついたら「休憩へ進む」を押そう' : '⏰ 休憩の予定時間です。「勉強へ進む」を押そう';
    $('msg').textContent = msg;
    notify(study ? '勉強の予定時間です' : '休憩の予定時間です', msg);
    beep();
    $('startBtn').textContent = study ? '休憩へ進む' : '勉強へ進む';
  }
  render();
}
function finish(){
  clearInterval(timerId); timerId = null; overStarted = false;
  unlockScreen();
  const wasStudy = mode === 'study';
  const overSec = Math.max(0, Math.floor(-remainMs/1000));
  const studied = Math.round((totalSec + overSec) / UNIT);       // 超過分も含めた勉強時間（分）
  if(wasStudy){ const k = dkey(new Date()); log[k] = (log[k]||0) + studied; store.set('log', log); }
  mode = wasStudy ? 'break' : 'study';
  $('quote').textContent = wasStudy ? QUOTES[Math.floor(Math.random() * QUOTES.length)] : '';   // 休憩に入ったときだけ表示
  const extra = overSec >= UNIT ? `（予定より${Math.floor(overSec/UNIT)}分超過）` : '';
  const msg = wasStudy ? `🎉 ${studied}分 おつかれさま！${extra}休憩しよう` : '☕ 休憩おわり！勉強を再開しよう';
  $('msg').textContent = msg;
  if(cfg.auto){ notify(wasStudy ? '勉強おわり！' : '休憩おわり！', msg); beep(); }   // 延長なしの場合のみここで通知
  $('startBtn').textContent = 'スタート';
  resetTime();
  if(cfg.auto) start();
}
$('startBtn').onclick = () => {
  if(timerId){
    if(overStarted){ finish(); return; }     // 超過中のボタンは「次のフェーズへ進む」
    clearInterval(timerId); timerId = null; unlockScreen(); $('startBtn').textContent = '再開'; render(); return;
  }
  start();
};
$('resetBtn').onclick = () => {
  clearInterval(timerId); timerId = null; overStarted = false; mode = 'study'; unlockScreen();
  $('startBtn').textContent = 'スタート'; $('msg').textContent = ''; $('quote').textContent = ''; resetTime();
};
['sMin','bMin','auto'].forEach(id => $(id).onchange = applyCfg);
document.querySelectorAll('[data-s]').forEach(b => b.onclick = () => {
  if(timerId) return;
  $('sMin').value = b.dataset.s; $('bMin').value = b.dataset.b; applyCfg();
});

/* ---------- 記録タブ ---------- */
let cy, cm, range = 7;

function streak(){
  const d = new Date(); let n = 0;
  if(!log[dkey(d)]) d.setDate(d.getDate() - 1);   // 今日まだ0分なら昨日から数える
  while(log[dkey(d)]){ n++; d.setDate(d.getDate() - 1); }
  return n;
}

function renderChart(){
  const now = new Date(), days = [];
  for(let i = range-1; i >= 0; i--){
    const d = new Date(now.getFullYear(), now.getMonth(), now.getDate()-i);
    days.push({d, m: log[dkey(d)]||0, today: i === 0});
  }
  const avg = Math.round(days.reduce((s,x) => s + x.m, 0) / range);
  const top = Math.max(60, ...days.map(x => x.m));
  const max = Math.ceil(top / 30) * 30;            // 軸の上限を30分刻みに
  const L = 34, R = 312, T = 14, B = 130, W = R - L, H = B - T;
  const slot = W / range, bw = Math.min(28, slot * 0.62);
  const y = v => B - v / max * H;

  let svg = '';
  [0, max/2, max].forEach(v => {
    svg += `<line x1="${L}" y1="${y(v)}" x2="${R}" y2="${y(v)}" stroke="currentColor" opacity="${v?'.12':'.3'}"/>`;
    svg += `<text x="${L-6}" y="${y(v)+3}" text-anchor="end">${v}</text>`;
  });
  days.forEach((x, i) => {
    const h = Math.round(x.m / max * H), cx = L + slot * (i + .5);
    if(h > 0) svg += `<rect x="${cx-bw/2}" y="${B-h}" width="${bw}" height="${h}" rx="${Math.min(5,bw/2)}" fill="var(--main)" opacity="${x.today?1:.72}"/>`;
    if(range === 7 && x.m) svg += `<text class="val" x="${cx}" y="${B-h-4}" text-anchor="middle">${x.m}</text>`;
    const show = range === 7 || i % 5 === (range-1) % 5;
    if(show) svg += `<text x="${cx}" y="148" text-anchor="middle">${range===7 ? (x.d.getMonth()+1)+'/'+x.d.getDate() : x.d.getDate()}</text>`;
  });
  if(avg > 0) svg += `<line x1="${L}" y1="${y(avg)}" x2="${R}" y2="${y(avg)}" stroke="#ffb703" stroke-width="2" stroke-dasharray="5 4"/>`;
  $('chart').innerHTML = svg;
  $('avg').textContent = avg + '分/日';
}

function renderRec(){
  const now = new Date();
  const weekSum = Array.from({length:7}, (_, i) => log[dkey(new Date(now.getFullYear(), now.getMonth(), now.getDate()-i))] || 0).reduce((a,b) => a+b, 0);
  const mp = now.getFullYear() + '-' + pad(now.getMonth()+1);
  const monthSum = Object.keys(log).filter(k => k.startsWith(mp)).reduce((s,k) => s + log[k], 0);
  $('sToday').textContent = todayMin(); $('sWeek').textContent = weekSum; $('sMonth').textContent = monthSum; $('sStreak').textContent = streak();

  renderChart();

  $('ym').textContent = cy + '年' + (cm+1) + '月';
  let html = ['日','月','火','水','木','金','土'].map(w => `<div class="h">${w}</div>`).join('');
  const first = new Date(cy, cm, 1).getDay(), last = new Date(cy, cm+1, 0).getDate(), tk = dkey(now);
  for(let i = 0; i < first; i++) html += '<div class="d e"></div>';
  for(let d = 1; d <= last; d++){
    const k = cy + '-' + pad(cm+1) + '-' + pad(d), m = log[k] || 0;
    const bg = m ? `background:rgba(33,158,188,${(0.25 + 0.65*Math.min(1, m/120)).toFixed(2)})` : '';
    html += `<div class="d${m?' has':''}${k===tk?' t':''}" style="${bg}">${d}${m?`<small>${m}分</small>`:''}</div>`;
  }
  $('cal').innerHTML = html;
}
$('prev').onclick = () => { cm--; if(cm < 0){ cm = 11; cy--; } renderRec(); };
$('next').onclick = () => { cm++; if(cm > 11){ cm = 0; cy++; } renderRec(); };
document.querySelectorAll('.seg button').forEach(b => b.onclick = () => {
  range = +b.dataset.r;
  document.querySelectorAll('.seg button').forEach(x => x.classList.toggle('on', x === b));
  renderChart();
});
document.querySelectorAll('.tab').forEach(t => t.onclick = () => {
  document.querySelectorAll('.tab').forEach(x => x.classList.toggle('on', x === t));
  $('timer').classList.toggle('hide', t.dataset.t !== 'timer');
  $('rec').classList.toggle('hide', t.dataset.t !== 'rec');
  if(t.dataset.t === 'rec'){ const n = new Date(); cy = n.getFullYear(); cm = n.getMonth(); renderRec(); }
});

$('sMin').value = cfg.s; $('bMin').value = cfg.b; $('auto').checked = cfg.auto;
notifStatus(); markChips(); resetTime(); updateLockState();
