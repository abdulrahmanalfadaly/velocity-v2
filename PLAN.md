# Velocity v2 – build plan and progress

The rebuild of Velocity as a full personal task workspace, following the FYP
report (Project I: *Velocity: A Web-Based Task Management System Using
Multi-Criteria Task Prioritization*, Nilai University).

- **Ranking:** only the report's formula – Simple Additive Weighting over four
  fixed-bound criteria (importance, urgency, deadline proximity, workload
  pressure), AHP default weights 42 / 23 / 23 / 12, contrastive explanations,
  adjustable weights. Every list, suggestion and map uses this one score.
  Other signals (status, energy, target dates) may filter or label tasks, never
  add points. Engine: `app/Domain/Prioritization` (ported unchanged from the FYP
  app, with its tests in `tests/Unit/Prioritization`).
- **Stack:** Laravel 12 (PHP 8.2) + Inertia 2 + React 19 + TypeScript +
  Tailwind 4, MariaDB/MySQL from XAMPP. No hosted services needed.
- **Approved design:** clickable mockups in
  `C:\Users\abdul\OneDrive\Desktop\Velocity\design\mockups` (open `index.html`).
  The real screens must match them.

## Two versions in one app

A per-account switch, **Settings → FYP mode**, chooses between them. Both use
the same tasks and the same report formula, so the order is always the same.

| | FYP mode | Personal Velocity |
|---|---|---|
| Screens | My tasks, History, Weights & method (the first version's screens) | Home, Tasks, Planner, Notes, Habits, People, Review, Goals map, projects |
| Explanations | All of them: score parts, breakdown table, "reproduce by hand", contrastive sentence, AHP | None: just the order, due dates and scores |
| Extras | Load the Table 3.4 scenario set, export the ranking (JSON) | – |
| Study log | Records every ranking shown and every weight change | Off |

- FYP code lives apart: `resources/js/pages/fyp`, `resources/js/components/fyp`,
  `resources/js/layouts/fyp-shell.tsx`, `resources/css/fyp.css`,
  `app/Http/Controllers/FypController.php`. Personal-only routes use the
  `personal` middleware (FYP accounts are sent to My tasks).
- **Rule:** FYP mode stays exactly as the report describes; change it only when
  the user asks. Everything new is personal-only, with no explanation text.
- `VELOCITY_FYP_DEFAULT=true` makes new accounts start in FYP mode (for the study).

## Decisions the user approved

1. Look: warm neutrals, violet accent; colour always means something
   (blue/orange/green/amber = the four score parts only, red = overdue only,
   violet = you/actions, rose/teal/olive = goals).
2. Navigation: the seven sections (Home, Tasks, Planner, Notes, Habits, People,
   Review) in a top bar; each section has its own side menu; Home has none.
   Phone: bottom bar (Home, Tasks, +, Planner, More) and the section's side menu
   as a drawer opened from the screen title.
3. A project is its own world: header in its goal colour, milestones, tasks with
   steps (a step can become a real ranked task), the project's own habits,
   notes, people and files.
4. Goals Map: a real mind map (You → goals → projects → tasks → steps, plus
   notes and habits), pan/zoom, glowing path to the #1 task, fold/unfold, focus,
   search, minimap, outline view. Dots flow out from you along every branch in
   its goal's colour; the path to #1 keeps its brighter white sparks (user's
   request, 9 Nov).

**Pending feedback from the user:** small changes to how the side menus are
organised (to be given later – ask before Phase 2 is finished).

## Phases

| Phase | Scope | Status |
|---|---|---|
| 0 | Project setup, design system, app shell, demo data | in progress |
| 1 | The report's core: tasks, inbox, areas, projects, goals, statuses; waiting/blocked tasks leave the ranking; formula, explanations, weights, history; Study mode | in progress |
| 2 | Recurring tasks, templates, bulk actions, board/timeline, reminders, files, delete with undo | in progress |
| 3 | Focus timer + Planner (books hours from the workload criterion, in rank order) | – |
| 4 | Notes (editor, links to tasks, journal, files, canvas last) | – |
| 5 | Habits (schedules, check-ins, streaks, routines) | – |
| 6 | People (contacts, dates, follow-ups) | – |
| 7 | Home, Review charts, global search | – |
| 8 | API for Mark (personal tokens with scopes) + GitHub | – |

## Phase 0 / 1 checklist

- [x] Laravel React starter kit created at `C:\Projects\Velocity-v2`
- [x] MySQL database `velocity_v2`, `config/velocity.php`, clock override (`VELOCITY_NOW`)
- [x] Ranking engine + 44 unit tests ported and passing
- [x] Schema: areas, goals, projects, milestones, tasks (parent, statuses,
      dependencies, available-from, soft delete), steps, events, weights,
      notes, habits + entries, study log
- [x] Ranking service: eligibility first (waiting / blocked / not yet → "not
      ready", never scored lower), then the engine. Tests prove the demo ranks
      exactly like the mockups (85.1, 78.7, 67.5 … at Mon 9 Nov 2026, 9:00)
- [x] Demo seeder: student "Aina" (login in `database/seeders/DemoSeeder.php`)
- [x] Design system: mockup CSS in `resources/css/velocity.css` (tokens renamed
      to avoid Tailwind/starter clashes: --line, --text-3, --violet, --r-*,
      --sh-*); starter variables mapped onto them in `app.css`
- [x] App shell `resources/js/layouts/velocity-shell.tsx`: top bar, Tasks side
      menu, phone bars + drawer + More sheet, Ctrl K search, Ctrl Shift K quick
      add (with live "lands at #n" preview), toasts with Undo
- [x] Home, Up next (+ task panel, filters, near ties, weights popover that
      re-ranks while dragging), Project world, Goals map (engine in
      `resources/js/features/goals-map/engine.ts`), Weights & method (AHP
      table), History (FR10)
- [x] FYP mode (replaces "Study mode"): Settings switch, the first version's
      screens and explanations, task form with live preview, Table 3.4
      scenario set, ranking export, study log only in FYP mode; personal mode
      without explanation text
- [x] 95 tests passing (engine, ranking, both modes, every page and action,
      Table 3.4 reproduced through the whole app, ownership)
- [ ] Export of the study log itself (CSV) for the Spearman analysis
- [x] Inbox and "not ready" as their own views in the Tasks side menu
      (`/tasks/inbox` keeps each task's rank number; `/tasks/waiting` groups
      waiting / after another task / starts later, with "Ready now")
- [x] Settings: time zone picker (new accounts start in the device's zone);
      settings, log in, sign up and password screens restyled in the Velocity
      design ("Keep me logged in" now actually works)
- [ ] Browser checks at phone and tablet sizes for the real pages
- [x] Goals map engine fully typed (no more `@ts-nocheck`): the server data
      (`MapGoal`, `MapTask` …) and every kind of map node have their own types
- [x] GitHub checks (linter + tests) pass; `config/inertia.php` points at the
      lowercase `resources/js/pages` folder, which Linux needs

## Phase 2 checklist

- [x] Repeating tasks: every day / weekday / week / 2 weeks / month / year (or
      "every 3 days" from quick add). Finishing one creates the next copy with
      the next due date (past dates skipped, steps start again undone), so each
      finished copy stays in History; the copy is ranked by the formula like
      any task. Undo takes the copy back. Set in the task panel ("Repeats") or
      by typing "every monday", "every 2 weeks" … in quick add (only "every …"
      counts, so "Weekly report" stays a title). `App\Services\Repeat`
- [x] Deep test (12 Nov): every page and action tried in the browser, two code
      reviews, a brand-new account. Added what was missing: New project ("+" in
      the Tasks menu, a new area on the way), edit / archive / restore projects,
      projects without an area in the menu, milestones you can add, tick and
      remove, New goal / Edit goal / archive on the Goals map (projects without a
      goal get an "Other projects" branch), an Archive page (deleted tasks,
      archived projects and goals, with Restore), habit check-ins on Home and
      project pages, and in the task panel: notes, waiting for, can start,
      starts after (no loops), goal, removing steps; the panel also opens on
      project pages. Fixed: done twice wiped the score, undo of done always gave
      "To do", project days left off by one, monthly repeats drifting, quick add
      reading "2 decks" as 2 Dec (and 0h, 31/2, past times, title-less lines),
      archived items in search and Home, filters resetting after ticking a task,
      double Enter adding twice, Escape not closing the panel, the Goals map on
      phones having no Tasks menu, red used for late finishes in History
- [x] Templates: save any task (with its steps) from the task panel, or make
      one by hand on the Templates page (Tasks menu › More). "Use" asks for a
      due date and project, then makes an ordinary task with the steps undone,
      opened in Up next. `TaskTemplate`, `TemplateController`
- [ ] Bulk actions
- [ ] Board / timeline views
- [ ] Reminders
- [ ] Files on tasks
- [x] Undo everywhere (12 Nov): every message with a change offers Undo for
      8 s, and Ctrl+Z (outside a text box) does the same. Undoable: every
      change in the task panel (put back from the saved event), done / not
      done, adding a task (quick add, project box, template), delete / restore,
      removing a step, "Make it a task", removing a milestone, templates
      (create, edit, delete, use), projects and goals (create while empty,
      edit, archive / restore), habit taps, weights reset. Steps, milestones
      and templates are soft-deleted so they can come back. The server names
      the action (`Controller::undo()`); `toast.tsx` maps it to a route.
      Not undoable on purpose: ticks that are their own undo (step done,
      milestone reached), password and account deletion.
- [ ] Ask the user for their side-menu changes before Phase 2 is finished

## Run it

**One click:** `scripts/Velocity.bat` (a copy sits on the Desktop) starts the database, the app
server and the live server, opens Velocity in its own Chrome window, and stops everything it
started when that window is closed. Log: `%LOCALAPPDATA%\Velocity\launcher.log`. By hand:

```bash
composer install && npm install
php artisan migrate --seed      # demo account: see database/seeders/DemoSeeder.php
npm run dev                     # live reload while working (or npm run build once)
php artisan serve --port=8200   # http://localhost:8200
# Speed: opcache is on in C:\xampp\php\php.ini (zend_extension=opcache, opcache.enable=1,
# opcache.revalidate_freq=0 so code changes still show at once). Pages went ~0.6 s → ~0.3 s.
# The app refreshes its data by itself when you come back to its tab after 20 s away.
php vendor/bin/phpunit
```
