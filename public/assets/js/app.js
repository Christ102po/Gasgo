const q=document.querySelector('#stationSearch');
const cards=[...document.querySelectorAll('[data-station]')];
if(q) q.addEventListener('input',()=>{const term=q.value.toLowerCase().trim();cards.forEach(c=>c.style.display=c.innerText.toLowerCase().includes(term)?'':'none')});

const gasGoAppScript = document.currentScript;
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    const scriptUrl = gasGoAppScript
      ? gasGoAppScript.src
      : new URL('public/assets/js/app.js', window.location.origin + '/').href;
    const swUrl = new URL('../../../service-worker.js', scriptUrl);
    const scopeUrl = new URL('../../../', scriptUrl);
    navigator.serviceWorker.register(swUrl.href, { scope: scopeUrl.pathname }).catch(() => {});
  });
}

function saveFavorite(id){
  const key='gasgo_favorites', list=JSON.parse(localStorage.getItem(key)||'[]');
  const i=list.indexOf(Number(id)); if(i>=0) list.splice(i,1); else list.push(Number(id));
  localStorage.setItem(key,JSON.stringify(list)); location.reload();
}
function isFavorite(id){return JSON.parse(localStorage.getItem('gasgo_favorites')||'[]').includes(Number(id))}
