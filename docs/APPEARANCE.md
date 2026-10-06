# Clinic workspace appearance

The shared workspace uses a dark navigation rail, a light canvas, readable RTL forms and tables, larger controls, and responsive spacing. Appointment search keeps the date, patient and therapist visible; the remaining criteria are in a native disclosure that opens automatically when any of those filters is active. Room and insurance semantics are unchanged.

Administrators can open `/appearance` to set the primary button color, button hover color and overall canvas color independently. Three suggested palettes update only the example card until Save is submitted. Body and heading fonts retain their existing upload and selection flow. Text over configurable colors switches between black and white based on relative luminance. Settings are validated before a single transaction saves colors, fonts and the audit entry. Permissions and CSRF remain enforced.

Presentation files:

- `public/assets/workspace.css`: shared layout, components, responsive rules and appearance editor.
- `public/assets/appearance.js`: scoped palette preview and unsaved-change indication.
- `public/assets/app.js`: shared menu, print, label color and progress behaviors.
- `public/assets/theme.css`: theme-variable template served by `/theme.css`; PHP substitutes only validated hex values.
- `public/assets/font-body.css` and `font-heading.css`: font templates; PHP substitutes only existing numeric font IDs.

PHP page templates contain no inline style attributes, style blocks or event-handler attributes. Dynamic label colors and progress percentages are data attributes consumed by the external shared script. Existing external assets remain for their component-specific rules and behavior.

`tests/workflow.py` verifies theme persistence, rejected input without partial saves, permission/CSRF protection, external asset markup and expanded active filters alongside the existing clinical regressions. Set `MASIHA_UI_PREVIEW_DIR` during isolated tests to export synthetic HTML fixtures for local visual inspection; never export production patient pages as fixtures.
