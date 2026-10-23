/* Personal clinical program order: native drag/drop + touch/keyboard fallback. */
(() => {
 'use strict';
 const collections=document.querySelectorAll('[data-program-sortable]');
 for(const list of collections){
  const rows=()=>Array.from(list.querySelectorAll('[data-program-item]'));
  const announce=document.createElement('p');
  announce.className='program-order-announcement';
  announce.setAttribute('role','status');
  announce.setAttribute('aria-live','polite');
  list.after(announce);
  let dragging=null;
  let touchDrag=null;
  const sync=()=>{
   const entries=rows();
   entries.forEach((row,index)=>{
    row.querySelector('input[name]')?.setAttribute('value',String(index+1));
    const input=row.querySelector('input[name]');
    if(input)input.value=String(index+1);
    const position=row.querySelector('.program-sort-rank');
    if(position)position.textContent=new Intl.NumberFormat('fa-IR').format(index+1);
    const up=row.querySelector('.program-move-up');
    const down=row.querySelector('.program-move-down');
    if(up)up.disabled=index===0;
    if(down)down.disabled=index===entries.length-1;
    row.setAttribute('aria-label','اولویت '+(index+1)+'، '+(row.querySelector('.program-sort-name')?.textContent||''));
   });
  };
  const move=(source,target,before)=>{
   if(!source||!target||source===target)return false;
   list.insertBefore(source,before?target:target.nextSibling);
   sync();
   announce.textContent='ترتیب جدید مشخص شد؛ برای ثبت نهایی، ذخیره نمایش را انتخاب کنید.';
   return true;
  };
  const relative=(item,clientY)=>clientY<item.getBoundingClientRect().top+item.getBoundingClientRect().height/2;
  list.addEventListener('dragstart',event=>{
   const item=event.target.closest('[data-program-item]');
   if(!item)return;
   dragging=item;
   item.classList.add('is-dragging');
   event.dataTransfer.effectAllowed='move';
   event.dataTransfer.setData('text/plain',item.dataset.programId||'');
  });
  list.addEventListener('dragover',event=>{
   if(!dragging)return;
   event.preventDefault();
   event.dataTransfer.dropEffect='move';
   const target=event.target.closest('[data-program-item]');
   rows().forEach(row=>row.classList.remove('drop-target'));
   if(target&&target!==dragging)target.classList.add('drop-target');
  });
  list.addEventListener('drop',event=>{
   if(!dragging)return;
   event.preventDefault();
   const target=event.target.closest('[data-program-item]');
   if(target)move(dragging,target,relative(target,event.clientY));
  });
  list.addEventListener('dragend',()=>{
   dragging=null;
   rows().forEach(row=>row.classList.remove('is-dragging','drop-target'));
  });
  list.addEventListener('click',event=>{
   const up=event.target.closest('.program-move-up');
   const down=event.target.closest('.program-move-down');
   if(!up&&!down)return;
   const item=event.target.closest('[data-program-item]');
   const sibling=up?item?.previousElementSibling:item?.nextElementSibling;
   if(!sibling||!sibling.matches('[data-program-item]'))return;
   move(item,sibling,!!up);
   (up||down).focus();
  });
  list.addEventListener('pointerdown',event=>{
   if(event.pointerType==='mouse'||!event.target.closest('.program-drag-handle'))return;
   const handle=event.target.closest('.program-drag-handle');
   const item=handle.closest('[data-program-item]');
   if(!item)return;
   event.preventDefault();
   touchDrag={item,handle,pointerId:event.pointerId};
   handle.setPointerCapture(event.pointerId);
   item.classList.add('is-dragging');
  });
  list.addEventListener('pointermove',event=>{
   if(!touchDrag||event.pointerId!==touchDrag.pointerId)return;
   event.preventDefault();
   const target=document.elementFromPoint(event.clientX,event.clientY)?.closest('[data-program-item]');
   if(target&&target!==touchDrag.item&&list.contains(target)){
    move(touchDrag.item,target,relative(target,event.clientY));
   }
  });
  const stopTouch=event=>{
   if(!touchDrag||event.pointerId!==touchDrag.pointerId)return;
   touchDrag.item.classList.remove('is-dragging');
   try{touchDrag.handle.releasePointerCapture(event.pointerId);}catch(_){}
   touchDrag=null;
   sync();
  };
  list.addEventListener('pointerup',stopTouch);
  list.addEventListener('pointercancel',stopTouch);
  list.closest('form')?.addEventListener('submit',sync);
  list.dataset.sortEnhanced='1';
  sync();
 }
})();