'use strict';
(() => {
 const workspace=document.querySelector('[data-booking-workspace]');if(!workspace)return;
 const form=workspace.closest('form'),field=name=>form.elements.namedItem(name);
 const output=workspace.querySelector('[data-slot-results]'),status=workspace.querySelector('[data-booking-status]'),summary=workspace.querySelector('[data-booking-summary]');
 const dateFormat=new Intl.DateTimeFormat('fa-IR',{weekday:'long',day:'numeric',month:'long'}),number=new Intl.NumberFormat('fa-IR');
 const dayDate=day=>new Date(day+'T12:00:00Z'),iso=d=>d.toISOString().slice(0,10);
 let request=0,controller,timer,selecting=false;
 const watched=['pid','therapist_id','clinic_id','date','duration','room'];
 const signature=()=>watched.map(name=>field(name)?.value||'').join('|');let lastSignature=signature();
 const today=()=>new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Tehran',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
 function updateSummary(){
  const patient=document.querySelector('#selected-patient')?.textContent||'',therapist=field('therapist_id').selectedOptions[0]?.textContent||'';
  const date=field('date').value,time=field('time').value;
  summary.textContent=patient+' · '+therapist+(date?' · '+dateFormat.format(dayDate(date)):'')+(time?' · ساعت '+time+' · '+number.format(Number(field('duration').value))+' دقیقه':' · ساعت انتخاب نشده');
 }
 function invalidate(){request++;controller?.abort();field('time').value='';field('equipment').value='';output.replaceChildren();updateSummary();}
 function setDate(day){field('date').value=day;field('date').dispatchEvent(new Event('change',{bubbles:true}));}
 function monthButtons(){
  const target=workspace.querySelector('[data-booking-months]');target.replaceChildren();const api=window.MasihaDate;if(!api)return;
  const [year,month]=api.parts(dayDate(today()));
  for(let i=0;i<5;i++){const m=(month-1+i)%12+1,y=year+Math.floor((month-1+i)/12),d=api.from(y,m,1);const b=document.createElement('button');b.type='button';b.className='button secondary';b.textContent=new Intl.DateTimeFormat('fa-IR',{month:'long',year:'numeric'}).format(d);b.addEventListener('click',()=>setDate(i===0?today():iso(d)));target.append(b);}
 }
 async function load(){
  updateSummary();
  const id=++request;controller?.abort();controller=new AbortController();output.replaceChildren();
  if(!Number(field('pid').value)){status.textContent='ابتدا بیمار را انتخاب یا پرونده جدید ثبت کنید.';return;}
  const from=field('date').value,duration=Number(field('duration').value);
  if(!from||!Number.isInteger(duration)||duration<5||duration>480){status.textContent='تاریخ و مدت جلسه معتبر انتخاب کنید.';return;}
  const params=new URLSearchParams({pid:field('pid').value,therapist:field('therapist_id').value,clinic:field('clinic_id').value,from,duration,room:field('room').value,days:7});
  status.textContent='در حال بررسی برنامه حضور و تداخل نوبت‌ها…';output.setAttribute('aria-busy','true');
  try{
   const response=await fetch('/api/appointment-slots?'+params,{signal:controller.signal});const data=await response.json();if(id!==request)return;if(!response.ok)throw Error(data.error||'دریافت زمان‌ها انجام نشد.');
   for(let i=0;i<7;i++){
    const d=dayDate(from);d.setUTCDate(d.getUTCDate()+i);const day=iso(d),slots=data.slots.filter(s=>s.starts_at.startsWith(day));
    const card=document.createElement('section');card.className='booking-day';const h=document.createElement('h3');h.textContent=dateFormat.format(d);card.append(h);
    const count=document.createElement('small');count.textContent=number.format(slots.length)+' زمان آزاد';card.append(count);
    if(!slots.length){const empty=document.createElement('p');empty.className='booking-empty';empty.textContent='زمان آزادی در برنامه ثبت‌شده نیست';card.append(empty);}
    for(const slot of slots){
     const button=document.createElement('button');button.type='button';button.className='booking-slot';button.setAttribute('aria-pressed','false');button.textContent=slot.starts_at.slice(11,16)+' – '+slot.ends_at.slice(11,16)+(slot.room?' · '+slot.room:'');
     button.addEventListener('click',()=>{
      selecting=true;setDate(day);field('time').value=slot.starts_at.slice(11,16);field('room').value=slot.room;field('equipment').value=slot.equipment;selecting=false;lastSignature=signature();
      output.querySelectorAll('.booking-slot').forEach(b=>{b.classList.remove('selected');b.setAttribute('aria-pressed','false');});button.classList.add('selected');button.setAttribute('aria-pressed','true');
      status.textContent='زمان انتخاب شد؛ برای رزرو، «ثبت نوبت» را بزنید.';updateSummary();
     });card.append(button);
    }output.append(card);
   }
   status.textContent=data.slots.length?'روز و ساعت دلخواه را انتخاب کنید.':'در این هفته زمان آزادی ثبت نشده است؛ هفته دیگر یا برنامه حضور درمانگر را بررسی کنید.';
  }catch(error){if(error.name!=='AbortError'&&id===request)status.textContent=error.message||'اتصال برقرار نشد؛ دوباره تلاش کنید.';}finally{if(id===request)output.removeAttribute('aria-busy');}
 }
 for(const name of watched)field(name)?.addEventListener('change',()=>{if(selecting||signature()===lastSignature)return;lastSignature=signature();invalidate();clearTimeout(timer);timer=setTimeout(load,180);});
 field('time').addEventListener('input',()=>{output.querySelectorAll('.booking-slot').forEach(b=>{b.classList.remove('selected');b.setAttribute('aria-pressed','false');});updateSummary();});
 workspace.querySelector('[data-find-slots]').addEventListener('click',()=>{clearTimeout(timer);invalidate();load();});
 workspace.querySelectorAll('[data-week-step]').forEach(b=>b.addEventListener('click',()=>{const d=dayDate(field('date').value||today());d.setUTCDate(d.getUTCDate()+Number(b.dataset.weekStep));setDate(iso(d));}));
 workspace.querySelector('[data-booking-today]').addEventListener('click',()=>setDate(today()));
 form.addEventListener('submit',e=>{if(!Number(field('pid').value)){e.preventDefault();status.textContent='ابتدا بیمار را انتخاب کنید.';status.scrollIntoView({block:'center'});}});
 document.querySelector('[data-open-quick-patient]')?.addEventListener('click',()=>{const quick=document.querySelector('#quick-patient');quick.open=true;quick.querySelector('input[name=fname]')?.focus();});
 monthButtons();updateSummary();load();
})();
