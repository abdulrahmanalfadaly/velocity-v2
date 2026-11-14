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
| 2 | Recurring tasks, templates, bulk actions, board/timeline, reminders, files, delete with undo | done (side-menu feedback still to come) |
| 3 | Focus timer + Planner (books hours from the workload criterion, in rank order) | in progress |
| 4 | Notes (editor, links to tasks, journal, files, canvas last) | in progress (drawing still to decide) |
| 5 | Habits (schedules, check-ins, streaks, routines) | done |
| 6 | People (contacts, dates, follow-ups) | done |
| 7 | Home, Review charts, global search | done |
| 8 | API for Mark (personal tokens with scopes) + GitHub | done |

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
- [x] Bulk actions: "Select" on Up next and Inbox turns the rows into tick
      boxes (shift-click for a range, "All"); the bar at the bottom marks them
      done, moves them to a project, sets a due date or deletes them. One Undo
      puts every task back as it was. `TaskController::bulk` / `bulkUndo`
- [x] Board and timeline views (List / Board / Timeline on Up next and Inbox,
      remembered per device). Board: To do / Doing / Waiting / Done today; drag
      a card to change its status (onto Done = done; from Done = opened again in
      that column). Timeline: Overdue, the next 14 days (with hours per day),
      Later, No date; drag a card to a day to give it that due date (keeps its
      time). Cards keep the formula's order in every column; filters still
      apply; every drop has Undo. Phones: columns scroll sideways; tap a card
      to change it in the panel. `components/velocity/task-views.tsx`
- [x] Reminders: a Reminder row in the task panel (any time, or 15 min / 1 h /
      3 h / 1 day before the due time). While Velocity is open it checks every
      30 s and when its window comes back into view; a due reminder shows a card
      (Open, snooze 10 min / 1 hour, Done) and, if allowed, a Windows
      notification. Each reminder is shown once; a new time or a snooze shows it
      again. Reminders missed while Velocity was closed appear when it opens.
      Repeat copies carry their reminder forward. `ReminderController`
- [x] Files on tasks: a Files section in the task panel (Attach, or drop files
      on it; up to 10 at a time, 20 MB each). Stored privately in
      storage/app/private/task-files/<user> (never public, never in Git); only
      the owner can open them. Pictures, PDFs and plain text open in a tab,
      everything else downloads. Remove and attach can both be undone; deleting
      the account removes its files from the disk. `TaskFileController`
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

## Phase 3 checklist

Built from the user's instructions, the mockups and the report only. The old
GPT version is a list of mistakes to avoid (a planner that ignored priority,
huge screens, tangled timer), never a source of code or design.

- [x] Focus timer: Start focus on the task panel, Home and project pages (50 min,
      or 25 / 90 / no limit); a chip in the top bar (a bar on phones) with the
      time, pause and stop. Time is worked out from timestamps, so a closed or
      reloaded window loses nothing. Starting marks the task Doing; under a
      minute isn't counted. Stopping asks "hours of work left?" (the estimate
      feeds the workload score, so changing it is the user's call). Focused time
      on the task panel and "focused this week" on projects. Undo for start
      and stop. `FocusController`, `FocusSession`
- [x] Classes and events, and the Planner page: Day / Week grid (phones: Day),
      side menu with New event, a month calendar (dots on busy days), Day / Week
      and the Classes / Work / Personal calendars (click to hide). Click an empty
      slot to add an event there. Events can be all day (one or more days), run
      past midnight, or repeat weekly until a last week; one week can be removed
      (a holiday). Weekly repeats are worked out in the user's time zone, so a
      9:00 class stays at 9:00 when clocks change. Today's due tasks show as flags
      on the grid. Events are neutral with an icon per calendar (colour is kept
      for score parts, overdue and goals). Undo for add / edit / remove.
      `EventController`, `PlannerController`, `Event::occurrences`
- [x] Phone on the same Wi-Fi: the launcher opens the app server to the local
      network and writes the link to %LOCALAPPDATA%\Velocity\phone-link.txt;
      phones get the built app (the live server only answers this PC), which the
      launcher rebuilds at start. Windows must treat the Wi-Fi as Private.
- [x] Plan my day / week: books tasks in ranking order (then tasks that start
      later in the period), each up to its hours of work, only in working hours
      (per weekday, days off allowed), around events with a 10-minute gap, never
      in the past, ending before each due time (overdue: as soon as possible),
      at most H hours a day (the formula's own H), in blocks of 30 min to 2 h
      with a break after each. Blocks the user placed or moved are pinned and
      kept; planning again replaces only the planner's own. "Not booked yet" in
      the side menu (with Book, or drag onto the grid); drag a block to move it
      (mouse at once, touch after a short hold); click a block to focus on it,
      open its task or remove it. Booked hours per day in the grid's header.
      Home's "Today's plan" card. Finished or deleted tasks' blocks stop counting
      everywhere (`PlannerBlock::active`). Undo for plan, book, move, remove and
      working hours. `PlanBuilder`, `PlannerBlockController`, `PlannerBlock`

## Phase 4 checklist

The GPT version's Notes is the scope reference (journal, class notes, making
tasks from text, files in notes); its version conflicts, recovery copies,
archive-only notes and seven kinds of links are left out on purpose.

- [x] Notes page: side menu (All notes, Pinned, Journal, Not in a project, each
      class or event with notes, each project with notes), the list (title,
      first words, date, project) beside the open note; one column below
      1100 px wide (list, or the note with Back). Search inside the list.
- [x] Editor (Tiptap, loaded only on the Notes page): headings, bold, italic,
      strikethrough, bulleted / numbered lists, checklists, quotes, code,
      links (Ctrl+click opens), pictures; the usual typing shortcuts. Saves
      itself 0.8 s after typing stops and when leaving the note (JSON, no page
      reload; the document is sent as a string so form trimming can't eat its
      spaces). Last save wins. A note left with nothing in it is thrown away
      when you leave it (and after a day if the window was closed).
      `NoteController::save`, `Note::textOf`
- [x] Links to tasks: "Link task" in a note (chips show the rank; click opens
      the task), linked notes and "New note" in the task panel. Select words →
      "Make task": a task in the note's project (or the Inbox), linked, Undo.
- [x] Class notes: "Notes for <day>" in the Planner's event dialog opens or
      starts the note for that class on that day ("Data Mining · Thu 19 Nov"),
      listed under the class in the side menu.
- [x] Journal: one entry a day in the user's own time zone (unique per day; a
      deleted entry makes way for a new one), Today, a month with dots on days
      that have entries, previous / next entry.
- [x] Files: paste or drop pictures into the text; other files (and Attach)
      go under Files. Stored privately in storage/app/private/note-files/<user>,
      owner only; removed with the note or the account. `NoteFileController`
- [x] Pin, move to a project (Undo), delete (Undo). Ctrl K finds notes by
      title or words inside and opens them; project pages, the Goals map and
      quick add (Note tab: a note named with what you typed) open notes too.
- [ ] Drawing inside a note: ask the user once the rest has been used

## Phase 5 checklist

The GPT version's Habits is the scope reference (routines, fixing past days,
excused days, habit times on the Planner); its five habit types with credit
maths, seven schedule kinds, dated schedule versions, extra statuses, notes on
every check-in and three Planner modes are left out on purpose.

- [x] Habits page with its own side menu (Today, This week, All habits, each
      routine with today's progress, Archived). Today groups To do / Done /
      Skipped / Not today; each row has the tick, progress ("3 of 8 glasses",
      "2 of 3 this week"), the last 7 days and the streak.
- [x] Schedules: every day, chosen weekdays, or N times a week on any days.
      Counted habits have a target and a unit. New / Edit habit dialog; also
      from a project's Habits card. Archive and delete with Undo.
- [x] This week: every habit's seven days (earlier weeks too); click any day to
      set it exactly (done / not done / how many / skipped). Skip today, or
      every due day until a date (a holiday). Skipped days neither break the
      streak nor count as done.
- [x] Streaks and figures: due days in a row (today never breaks it while it
      is still going; part of a target doesn't count), weeks in a row for
      weekly habits (skipped days lower a week's need when fewer days are left
      than it asks for; the first week is never held against it), best streak,
      done in the last 30 days (8 weeks), times done, and 16 weeks of days in
      the habit's panel. Counted in the user's own days. `HabitStats`
- [x] Routines: a name and habits in order (drag, or Up / Down); a routine
      page with numbered steps; "Morning 2 / 3" on Home. `RoutineController`
- [x] Planner times: a habit can have a time and length ("Gym 18:00, 45 min");
      it shows on the Planner and in Home's Today's plan on its days (tick or
      skip it there), and "Plan my day" books around it unless it is done or
      skipped. `HabitTimes`
- [x] Ctrl K finds habits; the Goals map opens them; Undo for every change.

## Phase 6 checklist

The GPT version's People is the scope reference (contacts, important dates with
reminders, keeping in touch, groups, links to notes); its follow-ups separate
from tasks, list of "memories", version locks and no-delete rule are left out
on purpose: follow-ups are tasks linked to the person, so the formula stays the
only ranking.

- [x] People page with its own side menu (All people, Needs attention,
      Birthdays & dates for the next 60 days, each group, Archived); each row
      shows what is next ("Birthday in 3 days", "Catch up · 2 days late").
      New / Edit person: name, who they are, where, how to reach them (email,
      phone, link, other: checked, links get https://), groups (new ones made
      on the way), keep in touch every n days. Archive and delete with Undo.
- [x] Person panel: contact lines (click to email, call, open), keep in touch
      ("Talked today": message / call / met / other, any past day, a note; the
      history with remove + Undo), dates, tasks ("Waiting on …", New task for
      them, Link task), notes (New note, Link note), projects, About.
- [x] Dates: birthday, anniversary or other; yearly or once (needs the year);
      29 February is kept on 28 February in other years; age / years from the
      year. Reminder on the day or 1 / 3 / 7 / 14 days before, at 9:00, shown
      once each time the date comes round (reminder card + Windows
      notification). `PersonDate::next`, `ReminderController`
- [x] Links: People row in the task panel (the picker says "Waiting on…" for a
      waiting task), people on notes, a People card on project pages.
- [x] Home: a People card (dates this week, catch-ups due). Planner: dates as
      all-day markers. Ctrl K finds people by name, who they are, where, or a
      contact. `PeopleInfo`

## Phase 7 checklist

The GPT version's Review is the scope reference (facts only, no scores or
advice, every chart readable as text); its capacity limits, completion-credit
maths, read-only transactions and custom ranges are left out on purpose.

- [x] Review section (top bar › Review) with its own side menu: This week,
      This month, 12 weeks, Weekly review. A week or month still going is
      compared with the same days of the period before; a whole one with the
      whole one before (months clamp to the shorter month). In numbers: tasks
      done (on time, average priority), focused (and booked on the Planner),
      habits (days done of days due), people in touch with, notes and journal
      entries. Charts: tasks done and focus each day, focus by project in its
      goal's colour; habits with their marks; first guess vs time focused for
      finished tasks (the estimate before any later "hours left" change);
      finished tasks. Up to three highlights (most tasks in 12 weeks or 6
      months, longest focus, people in touch). Plain CSS bars, each chart with
      its numbers as a table. `ReviewService`, `ReviewController`
- [x] 12 weeks: tasks done and focus per week, week by week (the personal
      History moves here; FYP mode's History is unchanged).
- [x] Weekly review: Inbox, overdue, waiting tasks, habits missed this week,
      catch-ups and dates coming, next week's deadlines and booked time, Plan
      next week, and "Mark as done" (with Undo) for the week.
- [x] Home: focused today / this week in the header, a Notes card (today's
      journal entry or "Write today's entry", the last notes touched); empty
      cards hide (Upcoming, Needs attention, Last 7 days); "Last 7 days" links
      to Review.
- [x] Ctrl K: actions (New task / note / habit / person, today's journal entry,
      Start focus on #1, Plan my day / week, Weekly review) and places ("Go to
      Planner"); classes and events (open on the Planner at their next time);
      what was opened last when nothing is typed; "See all results" opens a
      results page with the matching words of notes.

## Phase 8 checklist

The GPT version's API is the scope reference (Bearer keys with scopes, `{data}`
/ `{error}` answers, the same environment variables for Mark); its review
tokens for deletes, request reservations, capacity limits and field-by-field
redaction are left out on purpose. A part a key can't read is hidden whole.

- [x] Access keys: Settings → Integrations (personal mode). Name, full access
      or None / Read / Read & write per area (tasks, projects, planner, notes,
      habits, people, review read-only, github), never / 30 / 90 / 365 days.
      Shown once with Copy; only a fingerprint is kept; Revoke asks once and is
      final. Laravel Sanctum, keys only (no browser sessions), `vel_` prefix,
      120 requests a minute per key. `ApiAccess`, `IntegrationController`
- [x] One set of code for screens and API: `TaskActions` (quick add, change,
      done with score and repeat copy, not done, delete, restore, "starts
      after"), `FocusActions`; the other controllers' field checks are shared
      (`fields()`), so the API checks input exactly as the screens do.
- [x] API v1 (`routes/api.php`, `app/Http/Controllers/Api`, `docs/api.md`):
      health, me, today (each part by area, `null` without access), search;
      tasks (ranked with rank, score and the four parts; quick-add text;
      preview; steps; focus); projects, milestones, goals; Planner range,
      plan day / week, events; notes (plain text in and out, append), journal;
      habits (state, check with a count, skip, add); people (attention, dates,
      catch-ups, add); Review week / month and the weekly review.
- [x] "Via Mark": history entries made with a key keep its name; the task
      panel shows the last one ("Via · Mark · changed 14 Nov").
- [x] `examples/mark_velocity.py`: standard-library Python client.
- [x] GitHub with a fine-grained token (Settings → Integrations, with the exact
      settings to tick): checked with GitHub, kept encrypted, never sent to the
      browser; Disconnect. Only fixed api.github.com paths from a checked
      "owner/name" and a number; links are built by Velocity. `GitHub`,
      `GitHubActions`, `GitHubController`, `github_links`
- [x] Project: its repository (picked from the token's list, Undo) and a GitHub
      card with up to 10 open issues: "Add as task" (a task in the project,
      linked) or "Task ✓". Loads on its own, so the page never waits on GitHub.
- [x] Task panel: linked issues and pull requests (#42 · Open / Closed /
      Merged / Draft, open on GitHub), link by address or by searching the
      project's repository, unlink (Undo), "Create issue" (the task's notes
      only when ticked; no double send), "#42 closed on GitHub · Mark task
      done". Links over 10 minutes old are read again in the background;
      GitHub being down keeps what was known.
- [x] API: `GET/POST /tasks/{id}/github`, `DELETE /tasks/{id}/github/{link}`
      (GitHub area). No webhooks, no automatic completion, no field syncing.

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
