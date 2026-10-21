'use strict';
// Preview is intentionally scoped to the sample, never unsaved global settings.
(() => {
  const preview = document.querySelector('[data-theme-preview]');
  const keys = ['theme_color', 'theme_hover_color', 'theme_background_color'];
  const inputs = keys.map(key => document.getElementById(key));
  if (!preview || inputs.some(input => !input)) return;
  const form = inputs[0].form;
  const status = form.querySelector('[data-appearance-status]');
  const initial = new FormData(form);
  const contrast = color => {
    const channels = color.slice(1).match(/../g).map(h => parseInt(h, 16) / 255)
      .map(c => c <= .04045 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4);
    const luminance = channels[0] * .2126 + channels[1] * .7152 + channels[2] * .0722;
    return luminance > .179 ? '#000000' : '#ffffff';
  };
  const allInputs=[...inputs,...form.querySelectorAll('[data-theme-variable]')];
  allInputs.forEach(input => {
    const output = document.createElement('input');output.type='text';output.required=true;output.pattern='#[0-9A-Fa-f]{6}';output.maxLength=7;output.setAttribute('aria-label','کد HEX '+(input.labels?.[0]?.textContent||input.id));
    output.addEventListener('input',()=>{const valid=/^#[0-9a-f]{6}$/i.test(output.value);output.setCustomValidity(valid?'':'کد رنگ باید مانند #12AB34 باشد.');if(valid){input.value=output.value;update(false);}});
    input.addEventListener('input',()=>output.setCustomValidity(''));
    output.className = 'color-hex';
    output.htmlFor = input.id;
    input.after(output);
  });
  const update = (sync=true) => {
    const values = inputs.map(input => input.value);
    if (values.some(value => !/^#[0-9a-f]{6}$/i.test(value))) return;
    ['--primary', '--primary-hover', '--bg'].forEach((key, index) => preview.style.setProperty(key, values[index]));
    preview.style.setProperty('--primary-ink', contrast(values[0]));
    preview.style.setProperty('--hover-ink', contrast(values[1]));
    allInputs.forEach(input=>{if(input.dataset.themeVariable)preview.style.setProperty('--'+input.dataset.themeVariable,input.value);if(sync){input.nextElementSibling.value=input.value.toUpperCase();input.nextElementSibling.setCustomValidity('');}});
    const dirty = [...new FormData(form)].some(([key, value]) => initial.get(key) !== value);
    status.textContent = dirty ? 'تغییرات ذخیره نشده است. برای اعمال در همه صفحات، «ذخیره ظاهر» را بزنید.' : 'تغییرات پس از زدن «ذخیره ظاهر» اعمال می‌شوند.';
  };
  form.addEventListener('input', e=>{if(!e.target.classList.contains('color-hex'))update();});
  form.addEventListener('change', e=>{if(!e.target.classList.contains('color-hex'))update();});
  form.querySelectorAll('[data-palette]').forEach(button => button.addEventListener('click', () => {
    const colors = button.dataset.palette.split(',');
    if (colors.length !== 3 || colors.some(color => !/^#[0-9a-f]{6}$/i.test(color))) return;
    inputs.forEach((input, index) => { input.value = colors[index]; });
    if (button.dataset.themePreset === 'boghrat') {
      const preset = {
        theme_text:'#332939',theme_muted:'#726875',theme_border:'#e6dce4',
        theme_surface:'#ffffff',theme_mint:'#e6f6ee',theme_lavender:'#f3e6f3',
        theme_peach:'#f5e3c7',theme_sky:'#e8f7fc',theme_rose:'#fce9ef',
        theme_sidebar:'#79133f',theme_sidebar_text:'#fff1f6',
        theme_booked:'#fff0de',theme_booked_text:'#895b35'
      };
      for (const [key,color] of Object.entries(preset)) {
        const item = form.elements.namedItem(key);
        if(item)item.value=color;
      }
    }
    update();
  }));
  update();
})();
