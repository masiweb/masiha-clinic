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

## Per-visit controls

Each allowed appointment column now includes inline editors: hashtags next to the patient, diagnoses with searchable multi-selection and creation, treatment packages with searchable multi-selection, and editable admission notes. Search normalizes Persian/Arabic letter variants, selection counts update locally, Escape closes an editor and Cancel restores its initial values. The financial `$` link appears only when `can('finance')` permits the signed-in account; the destination retains its own authorization checks.

`app/reception-editors.php` validates row access and workflow version, saves each change and its audit event in one transaction, and protects clinical diagnosis edits for the assigned clinician or administrator. Clinical read scopes and user column preferences still apply. `public/assets/reception.js` and `reception.css` contain the behavior and styles.

The repeatable appointments migration adds `session_packages`, backfills existing package selections and adds `physio_sessions.reception_notes`. The legacy package column mirrors one selection for compatibility; filters also search the relation table. Changing treatment selections does not change an invoice automatically. New bookings copy their admission text into the dedicated column. Existing mixed clinical notes are not reclassified as reception text. Regression coverage checks multi-selection, diagnosis creation/privacy, note isolation, stale updates, invalid choices, migration repeatability, CSRF and financial-link visibility.
