/* Reception appointment book: read-only two-day spread, scoped API, Jalali date input. */
(() => {
 'use strict';
 const openButton=document.querySelector('[data-agenda-open]');
 const overlay=document.getElementById('reception-agenda-dialog');
 if(!openButton||!overlay)return;
 const panel=overlay.querySelector('.reception-agenda-window');
 const book=overlay.querySelector('#reception-agenda-book');
 const dateField=overlay.querySelector('#reception-agenda-date');
 const therapistField=overlay.querySelector('#reception-agenda-therapist');
 const labelField=overlay.querySelector('#reception-agenda-label');
 let request=null;
 let lastFocus=null;
 const today=()=>{const d=new Date();return [d.getFullYear(),String(d.getMonth()+1).padStart(2,'0'),String(d.getDate()).padStart(2,'0')].join('-');};
 const offset=(date,n)=>{const [y,m,d]=date.split('-').map(Number);const t=new Date(y,m-1,d,12);t.setDate(t.getDate()+n);return [t.getFullYear(),String(t.getMonth()+1).padStart(2,'0'),String(t.getDate()).padStart(2,'0')].join('-');};
 const node=(tag,cls,content)=>{
  const el=document.createElement(tag);
  if(cls)el.className=cls;
  if(content!==undefined)el.textContent=content;
  return el;
 };
 const clear=()=>{book.replaceChildren();};
 const fail=(message)=>{clear();book.append(node('p','reception-agenda-error',message));};
 const render=(days)=>{
  clear();
  if(!Array.isArray(days)||days.length!==2){fail('اطلاعات روزهای انتخاب‌شده دریافت نشد.');return;}
  for(const [index,day] of days.entries()){
   const page=node('section','reception-agenda-page '+(index?'page-left':'page-right'));
   const heading=node('div','reception-agenda-day');
   const date=node('strong','',day.weekday+' '+day.shamsi);
   const qty=node('span','',String(day.appointments.length)+' نوبت');
   heading.append(date,qty);
   page.append(heading);
   const events=node('div','reception-agenda-events');
   if(!day.appointments.length){
    events.append(node('p','reception-agenda-empty','نوبتی برای این روز ثبت نشده است.'));
   }
   for(const appointment of day.appointments){
    const card=node('a','reception-agenda-event state-'+appointment.state);
    card.href=appointment.href;
    const row=node('span','reception-agenda-event-top');
    row.append(node('strong','',appointment.time));
    row.append(node('span','',appointment.patient));
    const bottom=node('span','reception-agenda-event-details');
    bottom.append(node('span','',appointment.therapist||'بدون درمانگر'));
    if(appointment.label){
     const badge=node('span','reception-agenda-event-label',appointment.label);
     badge.style.backgroundColor=appointment.label_color;
     bottom.append(badge);
    }
    const status=node('span','reception-agenda-event-status',appointment.status);
    card.append(row,bottom,status);
    events.append(card);
   }
   page.append(events);
   const footer=node('div','reception-agenda-page-number',String(index+1));
   page.append(footer);
   book.append(page);
  }
 };
 async function refresh(){
  if(overlay.hidden)return;
  if(request)request.abort();
  request=new AbortController();
  clear();
  book.append(node('p','reception-agenda-loading','در حال دریافت نوبت‌های انتخاب‌شده…'));
  try{
   const search=new URLSearchParams({date:dateField.value,therapist:therapistField.value,label:labelField.value});
   const response=await fetch('/api/reception/agenda?'+search.toString(),{
    credentials:'same-origin',cache:'no-store',signal:request.signal,
    headers:{'Accept':'application/json'}
   });
   if(response.status===401){fail('نشست شما منقضی شده است؛ دوباره وارد سامانه شوید.');return;}
   const data=await response.json();
   if(!response.ok||!data.ok)throw Error(data.error||'دریافت نوبت‌ها انجام نشد.');
   render(data.days);
  }catch(error){
   if(error.name==='AbortError')return;
   fail(error.message||'خطای ارتباط با سرور.');
  }
 }
 function close(){
  if(overlay.hidden)return;
  overlay.hidden=true;
  openButton.setAttribute('aria-expanded','false');
  document.body.classList.remove('reception-agenda-open');
  if(request)request.abort();
  if(lastFocus?.focus)lastFocus.focus();
 }
 function open(){
  lastFocus=document.activeElement;
  overlay.hidden=false;
  document.body.classList.add('reception-agenda-open');
  openButton.setAttribute('aria-expanded','true');
  dateField.value=dateField.value||today();
  refresh();
  panel.focus();
 }
 openButton.setAttribute('aria-expanded','false');
 openButton.addEventListener('click',open);
 overlay.querySelectorAll('[data-agenda-close]').forEach(button=>button.addEventListener('click',close));
 overlay.addEventListener('keydown',event=>{
  if(event.key==='Escape'){event.preventDefault();close();}
  if(event.key==='Tab'){
   const elements=Array.from(overlay.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]),select:not([disabled])'))
    .filter(el=>el.offsetParent!==null);
   if(!elements.length)return;
   const first=elements[0],last=elements[elements.length-1];
   if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
   else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
  }
 });
 [dateField,therapistField,labelField].forEach(control=>control.addEventListener('change',refresh));
 const setDate=(value)=>{dateField.value=value;dateField.dispatchEvent(new Event('change',{bubbles:true}));};
 overlay.querySelector('[data-agenda-prev]').addEventListener('click',()=>setDate(offset(dateField.value,-2)));
 overlay.querySelector('[data-agenda-next]').addEventListener('click',()=>setDate(offset(dateField.value,2)));
 overlay.querySelector('[data-agenda-today]').addEventListener('click',()=>setDate(today()));
})();