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

## Reception layout (2026-10-06)

The appointments route now uses a reception-specific shell based on the user's comparison screenshots: collapsed global navigation, a compact booking/patient/search toolbar, previous/next-day controls that preserve the current filters, inline visit-type/room/therapist selectors, state tabs, count chips, label chips and a search immediately above the list. Existing available tools appear in a small side rail. Rows retain the user's allowed/visible columns and show colored workflow states linking to the existing visit screen. No new clinical mutations or permissions are introduced. The other routes retain the general workspace shell. All new presentation rules are in `public/assets/reception.css`; no inline CSS or JavaScript is introduced.
