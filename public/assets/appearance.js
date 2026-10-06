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
  inputs.forEach(input => {
    const output = document.createElement('output');
    output.className = 'color-value';
    output.htmlFor = input.id;
    input.after(output);
  });
  const update = () => {
    const values = inputs.map(input => input.value);
    if (values.some(value => !/^#[0-9a-f]{6}$/i.test(value))) return;
    ['--primary', '--primary-hover', '--bg'].forEach((key, index) => preview.style.setProperty(key, values[index]));
    preview.style.setProperty('--primary-ink', contrast(values[0]));
    preview.style.setProperty('--hover-ink', contrast(values[1]));
    inputs.forEach(input => { input.nextElementSibling.value = input.value.toUpperCase(); });
    const dirty = [...new FormData(form)].some(([key, value]) => initial.get(key) !== value);
    status.textContent = dirty ? 'تغییرات ذخیره نشده است. برای اعمال در همه صفحات، «ذخیره ظاهر» را بزنید.' : 'تغییرات پس از زدن «ذخیره ظاهر» اعمال می‌شوند.';
  };
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  form.querySelectorAll('[data-palette]').forEach(button => button.addEventListener('click', () => {
    const colors = button.dataset.palette.split(',');
    if (colors.length !== 3 || colors.some(color => !/^#[0-9a-f]{6}$/i.test(color))) return;
    inputs.forEach((input, index) => { input.value = colors[index]; });
    update();
  }));
  update();
})();
