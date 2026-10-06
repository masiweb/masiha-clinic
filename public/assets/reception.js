'use strict';
(() => {
  const normalize = value => value.normalize('NFKC').replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/[\u200c\u200e\u200f]/g, ' ').toLocaleLowerCase().trim();
  document.querySelectorAll('[data-row-editor]').forEach(editor => {
    const form = editor.querySelector('form');
    const search = editor.querySelector('[data-choice-search]');
    const options = [...editor.querySelectorAll('[data-choice-option]')];
    const refresh = () => {
      const query = normalize(search?.value || '');
      let visible = 0;
      options.forEach(option => { option.hidden = !normalize(option.textContent).includes(query); if (!option.hidden) visible++; });
      const counter = editor.querySelector('[data-choice-counter]');
      if (counter) counter.textContent = `${new Intl.NumberFormat('fa').format(editor.querySelectorAll('input[type=checkbox]:checked').length)} مورد انتخاب شده`;
      const empty = editor.querySelector('[data-choice-empty]');
      if (empty) empty.hidden = visible > 0;
    };
    search?.addEventListener('input', refresh);
    form.addEventListener('change', refresh);
    // Enter in the search field filters choices rather than accidentally saving.
    search?.addEventListener('keydown', event => { if (event.key === 'Enter') event.preventDefault(); });
    editor.addEventListener('toggle', () => {
      if (!editor.open) return;
      document.querySelectorAll('[data-row-editor][open]').forEach(other => { if (other !== editor) other.open = false; });
      refresh();
      (search || editor.querySelector('textarea'))?.focus();
    });
    editor.querySelector('[data-editor-cancel]')?.addEventListener('click', () => {
      form.reset(); refresh(); editor.open = false; editor.querySelector('summary').focus();
    });
    editor.addEventListener('keydown', event => {
      if (event.key === 'Escape') { editor.open = false; editor.querySelector('summary').focus(); }
    });
    refresh();
  });
})();
