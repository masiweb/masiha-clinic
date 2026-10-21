/* State popovers are rendered in the browser top layer to avoid table-cell clipping. */
(() => {
  const items = document.querySelectorAll('.visit-state-popup[popover]');
  if (!items.length) return;

  function place(menu) {
    if (!menu.matches(':popover-open')) return;
    const trigger = document.querySelector('[popovertarget="' + menu.id + '"]');
    if (!trigger) return;
    const rect = trigger.getBoundingClientRect();
    const screenWidth = document.documentElement.clientWidth;
    const screenHeight = window.innerHeight;
    const width = Math.min(290, screenWidth - 24);
    const maxLeft = Math.max(12, screenWidth - width - 12);
    const left = Math.max(12, Math.min(rect.right - width, maxLeft));
    // Popover has measured dimensions only after it opens.
    const height = Math.max(menu.getBoundingClientRect().height, 120);
    let top = rect.bottom + 7;
    if (top + height > screenHeight - 12 && rect.top - height - 7 >= 12) {
      top = rect.top - height - 7;
    } else {
      top = Math.min(top, Math.max(12, screenHeight - height - 12));
    }
    menu.style.left = Math.round(left) + 'px';
    menu.style.top = Math.round(top) + 'px';
  }

  items.forEach(menu => {
    menu.addEventListener('toggle', event => {
      if (event.newState === 'open') {
        requestAnimationFrame(() => place(menu));
      } else {
        menu.style.removeProperty('left');
        menu.style.removeProperty('top');
      }
    });
  });

  window.addEventListener('resize', () => {
    items.forEach(menu => { if (menu.matches(':popover-open')) place(menu); });
  }, { passive: true });
  document.addEventListener('scroll', event => {
    if (event.target && event.target.closest && event.target.closest('.visit-state-popup')) return;
    items.forEach(menu => { if (menu.matches(':popover-open')) place(menu); });
  }, true);
})();
