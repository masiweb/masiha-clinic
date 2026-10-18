document.addEventListener('click',event=>{
 const trigger=event.target.closest('[data-photo-preview]');
 if(!trigger)return;
 event.preventDefault();
 let dlg=document.getElementById('patient-photo-dialog');
 if(!dlg){dlg=document.createElement('dialog');dlg.id='patient-photo-dialog';dlg.className='patient-photo-dialog';
  const close=document.createElement('button');close.type='button';close.className='patient-photo-close';close.textContent='بستن تصویر';close.addEventListener('click',()=>dlg.close());
  const img=document.createElement('img');img.alt='تصویر بزرگ‌نمایی‌شده بیمار';dlg.append(close,img);dlg.addEventListener('click',e=>{if(e.target===dlg)dlg.close()});document.body.append(dlg);
 }
 const img=dlg.querySelector('img');img.src=trigger.dataset.photoPreview;dlg.showModal();
});
