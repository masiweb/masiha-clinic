'use strict';
const menuButton = document.querySelector('[data-menu]');
function setMenu(open) {
  document.body.classList.toggle('menu-open', open);
  menuButton?.setAttribute('aria-expanded', String(open));
}
menuButton?.addEventListener('click', () => setMenu(!document.body.classList.contains('menu-open')));
document.addEventListener('click',e=>{if(document.body.classList.contains('menu-open')&&!e.target.closest('.sidebar,[data-menu]'))setMenu(false);const b=e.target.closest('.field-help>button');if(b)b.parentElement.classList.toggle('open');});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){setMenu(false);document.querySelectorAll('.field-help.open').forEach(e=>e.classList.remove('open'));}});
document.querySelectorAll('form[method=post]').forEach(form=>form.addEventListener('submit',e=>{if(e.defaultPrevented||!form.checkValidity())return;const confirmation=form.dataset.confirm;if(confirmation&&!confirm(confirmation)){e.preventDefault();return;}setTimeout(()=>{if(e.defaultPrevented)return;form.querySelectorAll('button[type=submit]').forEach(b=>{b.disabled=true;b.dataset.label=b.textContent;b.textContent='در حال ثبت…';});},0);}));

// Dynamic presentation is kept out of server-rendered templates.
document.querySelectorAll('[data-label-color]').forEach(element => {
  if (/^#[0-9a-f]{6}$/i.test(element.dataset.labelColor)) element.style.color = element.dataset.labelColor;
});
document.querySelectorAll('[data-progress]').forEach(element => {
  const value = Number(element.dataset.progress);
  if (Number.isFinite(value)) element.style.width = `${Math.min(100, Math.max(0, value))}%`;
});
document.querySelectorAll('[data-print]').forEach(button => button.addEventListener('click', () => window.print()));
