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
5. Clock times read "6:00 pm" everywhere (15 Nov). Native date and time boxes
   follow the computer's own setting.
6. One dropdown everywhere: Velocity's own (`components/velocity/select.tsx`),
   never the browser's plain one; a search box when the list is long (15 Nov).
   The same for dates and times (`components/velocity/date-pick.tsx`):
   "Tue 17 Nov, 11:59 pm" or "None", a small calendar and a typed time.
7. Saving stays as it is: dialogs with Add/Save where fields only make sense
   together (an event's day and times, a habit's schedule), autosave for single
   fields (15 Nov).
8. The Goals map stays under Tasks (15 Nov).
9. Notes get drawing and shapes, but no separate drawing mode or board: a
   drawing tool (pen and shapes) is added to the note editor as it is (16 Nov).

**Pending feedback from the user:** small changes to how the side menus are
organised. Left for the very end, after the backlog (user, 16 Nov).

## Phases

| Phase | Scope | Status |
|---|---|---|
| 0 | Project setup, design system, app shell, demo data | in progress |
| 1 | The report's core: tasks, inbox, areas, projects, goals, statuses; waiting/blocked tasks leave the ranking; formula, explanations, weights, history; Study mode | in progress |
| 2 | Recurring tasks, templates, bulk actions, board/timeline, reminders, files, delete with undo | done (side-menu feedback: at the very end) |
| 3 | Focus timer + Planner (books hours from the workload criterion, in rank order) | in progress |
| 4 | Notes (editor, links to tasks, journal, files, canvas last) | in progress (drawing: yes, as a tool in the editor; comes with Phase 12) |
| 5 | Habits (schedules, check-ins, streaks, routines) | done |
| 6 | People (contacts, dates, follow-ups) | done |
| 7 | Home, Review charts, global search | done |
| 8 | API for Mark (personal tokens with scopes) + GitHub | done |
| 9 | The GPT version's missing features, top picks first (full list: `docs/backlog.md`) | top picks done; the rest of the list next |
| 10 | The GPT list, batch 2: finishing tasks and projects | done |
| 11 | The GPT list, batch 3: Planner, Focus, Home, Search and Capture | done |
| 12 | The GPT list, batch 4: Notes, Habits and People | done |
| 13 | The GPT list, last batch: the rest of `docs/backlog.md` | done |

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
- [x] Ask the user for their side-menu changes (done 18 Nov for the Tasks
      menu: see "Side menus" below)

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

## Phase 9 checklist

The GPT version's features that v2 lacked, the user's twelve top picks first
(the rest wait in `docs/backlog.md`). Plan approved on 15 Nov with these calls:
limit / avoid habits count a day with nothing logged as kept; the plain focus
timer keeps counting past its time (Pomodoro stops at each round's end); a
cancelled task frees the tasks waiting on it; Planned / On hold projects' tasks
leave Up next; only finished or cancelled tasks can be archived.

- [x] ★1 Quick add: Event tab ("Dentist tomorrow 3-4pm #personal", "Quiz fri
      9-10am #class", "Gym every mon 6pm"; the day, times, calendar and Every
      week fill in as you type and can be changed by hand; no time = all day; a
      start alone lasts the hours typed or an hour; only weekly repeats) and
      Person tab (name and who they are; you stay where you are). Errors show
      in the box and the text stays. The parser also reads "3 pm", "noon",
      "1h 30m" (one length) and ranges ("11-1pm" = 11 am to 1 pm, "3pm to
      4:30pm"); "at 3" and "2-3" stay words. `QuickAddParser::parseEvent`,
      `events.preview`
- [x] ★2 Follow-ups: a Follow up day in the task panel (any open task); when it
      comes the task heads Not ready yet ("Follow up · 1 day late · waiting for
      Dr. Lim, 3 days", rank kept, "Followed up" clears it), Home's Needs
      attention, and the Planner's all-day row. Never changes the order.
      Waiting tasks remember since when ("Waiting for Dr. Lim · 3 days";
      personal mode only). Cancel task (panel ⊘, selection bar): optional
      reason, shown in the panel and the project's Finished list; its focus
      stops; tasks after it can start; a repeating one offers Skip this one
      (next copy made) or Stop repeating; Reopen / Undo put it back as it was.
      Archive (finished tasks only): out of project pages, Done today and
      search, listed first on the Archive page with Take out; still counted in
      History and Review. Settings → Tasks: archive finished tasks never /
      after 1, 7 or 30 days (a task taken out stays out until finished again).
      Delete now has a bin icon. `FollowUps`, `AutoArchive`,
      `TaskActions::cancel / archive`
- [x] ★3 Reminders: up to 5 per task in the panel's Reminders row: at a time,
      before due (15 min / 1 h / 3 h before, the due day, 1 / 2 / 7 days before
      at 9:00 am; they move with the due date however it changes and wait
      while there is none), or Bug me every 15 / 30 / 60 / 120 min (one per
      task; missed ones never pile up) until done or "Stop bugging". The card:
      Open, Snooze (10 / 30 min, 1 / 3 h, tomorrow 9:00 am, or a time picked),
      Done, Stop bugging. Finished tasks' reminders wait; reopen brings them
      back. Repeat copies bring them along. Old single reminders moved over;
      the API's `reminder_at` still sets one. `TaskReminder`, `Reminders`
- [x] ★4 Focus: "Start focus" starts the way Settings → Focus says (Timer
      or Pomodoro, the timer's length); its ▾ opens a box with "What will you
      do?" and Pomodoro / 25 / 50 / 90 / any 1–240 / No limit. Pomodoro: a
      round stops at its length (never counted past it, even with the window
      closed), then a short break, or a long one every few rounds; the chip
      shows "Round 2 · 18:42", "Break 3:12", "Break's over" with Take a break /
      Skip break / Start round 3 / Done; left alone 2 hours it ends. Settings →
      Focus: lengths, rounds before the long break, breaks / next round
      starting by themselves, a soft chime. The plain timer still counts past
      its time. Task done in the chip (focus kept, task ticked, Undo). The stop
      card asks "What got done?". Review → Focus: a week or month by hour of
      the day and by weekday (sessions spread over the hours they ran), every
      session with its intention and note (editable; remove with Undo).
      `FocusActions::state`, `FocusSettings`, `ReviewService::focus`
- [x] ★5 Planner: drag a block's bottom edge to change its length (15-minute
      steps), or type its start and end in the block's menu ("Resized" /
      "Changed", Undo). Overlaps are a warning only: a booked, moved or resized
      block, a new or changed event, and quick add's Event tab say "overlaps
      Data Mining lecture (9:00–11:00 am)", and the grid marks such a block
      with a dashed outline. Settings → Planner: gap around blocks and events
      (0–60, was a fixed 10), shortest and longest block (30 / 120); the daily
      limit stays the formula's H. Free time in the side menu: each day shown,
      working hours from now on around events, habit times and blocks (with
      the gap), the total, and "Copy free time". `PlannerOverlaps`,
      `PlannerSettings`, `PlanBuilder::free`
- [x] ★6 Habits (left out in Phase 5, added at the user's choice): kinds Do it,
      Minutes ("Read 20 min": a tap fills it, or an amount), Limit ("at most
      2 cups": each tap adds one; over = missed) and Avoid ("It happened"
      flips the day); a limit or avoid day with nothing logged is kept, today
      counts once it's over. Schedules: N times a month (a month asks for its
      share of the days it had: began 16 Oct → 3 of 4) and every N days from
      a start date; limits and avoiding stay daily / set days / every N days,
      with no Planner time. Pause (until resumed or a day) and Resume: paused
      days are neither due nor missed, so the streak waits; paused habits
      leave Today (a Paused fold) and the Planner. A note on any day's
      check-in (the day editor), listed in the habit's panel. One "was this
      day met?" rule (`Habit::achieved`) for stats, Home, Review, projects,
      the Planner and the API. `HabitPause`, `HabitStats`
- [x] ★7 Repeats: a weekly repeat can name its days (Mo–Su buttons in the
      task panel; "every Mon and Thu", "every mon, wed, fri"; "every 2 weeks
      on Mon, Thu" skips the week between) and a last day ("until 20 Dec" in
      the panel or quick add; no copy after it; events too). "Skip this one"
      in the Repeats row. Each copy remembers the day it was meant for, so
      moving one copy doesn't move the rest (older copies keep their series
      the first time they're moved); a new rule starts the series again from
      the task's due date. `Repeat::next` with days, `repeat_anchor`
- [x] ★8 Notes: tables (Table button: 3 × 3 with a header row; inside one a
      bar to add or remove rows and columns, turn the header row on or off,
      remove the table; Tab moves between cells; search and the API read it
      row by row, "Report | 60%"). "Link note, goal or habit" beside Link task
      and Link person (one search over all three); the linked note says
      "Linked from …", a goal's panel on the Goals map and a habit's panel
      list their notes; unlink with Undo. A note with only links is kept.
      `@tiptap/extension-table`, `NoteLink`
- [x] ★9 Views in the Tasks menu, each with its count and its own address
      (`/tasks?show=today`, kept after a reload): Due today, Due this week,
      Overdue, Quick wins (1 hour or less), Doing, Important (4–5), Repeating;
      the same chips on Up next. Each is the ranking with some tasks left out,
      same order, same numbers. Next actions (Tasks menu): each project's best
      task by the formula, in the order of those tasks (plus the best with no
      project), with Focus; projects with nothing ready below with why ("1
      waiting · 1 starting later"). Low energy and Eat the frog left out (they
      need extra points). `TaskController::next`, `NavData` view counts
- [x] ★10 Areas in the Tasks menu: ⋯ menu (Rename, icon, New project here,
      Remove area — its projects move to Other, Undo puts them back), click to
      fold (remembered on the device), New area at the end; empty areas show.
      Projects: Planned / Active / On hold on the project page (a planned or
      on-hold project's tasks leave Up next and wait under Not ready yet as
      "Project on hold"; personal mode only, FYP's ranking untouched; the menu
      tags them), Complete (with open tasks: "Cancel them and complete" /
      "Keep them open" / "Not yet"; a "Completed … · Reopen" bar; out of the
      menu; Undo reopens the tasks it cancelled). All projects page (Tasks
      menu): tabs Active / Planned / On hold / Completed / Archived, each with
      progress, open tasks, due date and its best task's rank. Goals: status
      (Active, Paused, Achieved, Dropped), a real target date (the words stay
      as an option), progress from tasks, from milestones, or by hand (a
      slider); the Goals map shows them. `AreaController`,
      `ProjectStatusController`, `Goal::progressPct`
- [x] ★11 Review: Today (a day at a time, against the day before) in the side
      menu. "What changed": tasks added (not repeat copies), cancelled (with
      the reason), opened again, postponed (a due date moved later, "Fri 13 Nov
      → Mon 16 Nov"; the selection bar's changes now go into the history too),
      projects completed, goals achieved, milestones reached, blocks moved on
      the Planner; click a number to see its items (up to 50, newest first,
      each opening its task or project). Time in classes, work and personal
      events and the all-day ones. A Compare switch (remembered on the device)
      shows the period before as "Last week 9 · +3". When you focus by hour
      and weekday is Review → Focus (★4). `ReviewService::activity`,
      `planner_moves`, `goals.achieved_at`
- [x] ★12 The API for all of it (93 routes): cancel, archive, bulk, Next
      actions, reminders (with snooze), templates, focus (Pomodoro phases,
      pause / resume / next / end, history, the note after); project status,
      completing, a project's ranked tasks, areas, goal status / date /
      progress; free time and booked blocks (with what they overlap); a
      habit in full, editing, a day with a note, pause / resume, archive;
      editing and archiving people; note links; Review by day with "what
      changed"; GitHub search, create an issue, read again, a project's
      repository. Every answer has an `X-Request-Id`. The screens and the
      API share the same code (`NextActions`, `ProjectActions`,
      `Reminders`, `NoteLink::attach`). Mark's examples: eight short
      scripts on top of `mark_velocity.py`; `docs/api.md` covers it all.

## Phase 10 checklist

The second batch from `docs/backlog.md`: finishing tasks and projects. Plan
approved on 15 Nov; the one call (other sorts on All tasks) went with the
recommendation: no other sorts. Open tasks keep the formula's order there
too, and finished ones go newest first.

- [x] Small wins: a task with no project can belong straight to a goal
      (`tasks.goal_id`; under the goal on the Goals map; moving it into a
      project drops it); the panel warns when a task is due after its
      project's due date (a label only); "Start now" on tasks that start later
- [x] Quick add: "from mon" (start date), "remind 9am" / "remind fri 8am" /
      "remind 1h before", "!" and "!!" (importance; the dots win); every chip
      has an x that keeps its words in the title; the Note tab writes a note
      with its text and stays ("Open in Notes" goes to the editor); "Add
      another"; a dropped connection keeps the draft
- [x] Waiting on other tasks: a Blocks list in the panel (add, take off;
      loops refused with the reason); ticking a task still waiting asks
      "Complete anyway?" (the server and the API only do it when asked);
      "“Slides” can start now" after done or cancelled, "waits on it again"
      after reopening (`TaskLinks`)
- [x] Real subtasks: "Add subtask" (a task under this one, ranked like any;
      it starts with the parent's importance, urgency and due date), their
      own list with ticks; a parent with open subtasks asks: complete them
      too, or only this one; deleting a parent asks: delete them too, or keep
      them as tasks of their own (Undo brings back what went with it)
- [x] Activity list in the task panel: its history in plain words ("Due
      moved Wed 11 Nov → Fri 13 Nov", "Moved to “Chapter 3”", "Done"), with
      when and via whom (`TaskPresenter::activity`)
- [x] All tasks (Tasks › All tasks): Open / Done / Cancelled / Archived, a
      search and a project filter; select several to reopen, archive, take
      out of the archive or delete (each with Undo)
- [x] The selection bar also sets importance, urgency, status and a
      follow-up date on several tasks (Undo puts each one's old value back)
- [x] A project's Tasks tab: List / Board / Timeline; the Timeline shows 2
      weeks by day, or 2 or 3 months by week (a card dropped on a week keeps
      its weekday; no date: that week's Friday)
- [x] Templates with dates counted from the day they are used ("due 3 days
      after use, 17:00", "can start 1 day after"); saving a task as a
      template turns its dates into days from today
- [x] Files: paste a file or screenshot while a task's panel is open; a
      paperclip count on rows and cards; a project Files tab with the
      project's own files (`project_files`) and its tasks' files
- [x] Deleting a project or goal with things in it, with Undo (projects and
      goals are now only marked deleted): a project's tasks go to the Inbox
      or are deleted with it, its notes and habits stay; a goal's projects
      and tasks stay without it (`ProjectActions::delete`, `deleteGoal`).
      The Archive's "Delete all for good" / "Empty" ask first (no Undo)
- [x] The API for all of it: `goal_id`, the new quick-add words,
      `anyway` / `children` on complete and delete, subtasks, blocks,
      `cancelled` and `archived` views, the new bulk actions, template
      dates, deleting and restoring projects and goals (`meta.undo`).
      Uploading files and emptying the Archive stay on the screens only.

## Phase 11 checklist

The third batch from `docs/backlog.md`: Planner, Focus, Home, Search and
Capture. Approved on 16 Nov with the suggested answers: a timer's time-up is
a choice in Settings → Focus (keep counting by default), and "Capture only" is
a choice in quick add. Left out on purpose: a target date, Home's projects /
focus / mini-map cards, the Planner's year view, session chooser and block
history.

- [x] Planner month view: Day / Week / Month; each day box shows its first
      few things, then "+2 more"; a day opens it; phones show marks
- [x] Time for a whole project ("FYP, Thu 2–5 pm", shows the project's top
      task by the formula) and reserved time ("Gym", grey stripes, not
      work); planning books around both (`planner_blocks.kind`)
- [x] Lock a block (dragging asks "Move it anyway?"; the server refuses
      without `anyway`), and "Change task" in the block menu
- [x] A booked task that can't be worked on now (waiting, after another
      task, project on hold, booked before it can start) turns grey and says
      why; "Use for #N …" gives the time to the next task still to book
- [x] Events: drag and resize like blocks (weekly: "Only this week" makes a
      one-off copy, or "Every week"), a Locked box, "Cancel this week" /
      "Mark as cancelled" (stays crossed out, takes no time, Review counts it)
- [x] "Show" switches (task due dates, projects and milestones, goal dates,
      habits, birthdays and catch-ups, follow-ups) and new markers: project
      due dates, milestones, goal target dates, habits with no set time,
      catch-ups
- [x] Focus on a project, goal, habit or note (`focus_sessions.project_id`
      …); a habit's focus offers to tick it off ("Add 25 min to Read
      today?"); Settings → Focus "When a timer's time is up: Keep counting /
      Stop"
- [x] Home: "I have [time] [energy]" on Do next (tasks keep their own rank;
      `tasks.energy` is a filter only, set in the panel or with `~low`), a
      Refresh button, the Inbox count, a reload after midnight; Up next gets
      a "Low energy" filter
- [x] Search: one kind at a time, and "Include archived"
- [x] Capture only: quick add's box puts a task in the Inbox's "To sort",
      out of the ranking, until "Rank it" (`tasks.captured_at`)
- [x] The API for all of it (`docs/api.md`); the launcher now brings the
      database up to date when it starts

## Phase 12 checklist

The fourth batch from `docs/backlog.md`: Notes, Habits and People. Approved
on 16 Nov as suggested: follow-ups stay tasks (a "Follow up" button makes
one), PDF page zoom is left out, and drawing is a tool inside the note
editor (decision 9), not a mode.

- [x] Notes toolbar: underline, code in a line, a divider line
- [x] Drawing and shapes inside a note (`components/velocity/note-drawing.tsx`,
      a "drawing" block saved in the note: pen, line, arrow, rectangle,
      circle, eraser, three colours, two widths, undo, taller / shorter)
- [x] PDFs shown inside the note, with "Open full size"
- [x] A task made from a note's words keeps them (`note_task.quote`): "From
      note: …" opens the note at those words
- [x] Archive a note: read-only, out of the lists, Notes › Archived
- [x] A note in several projects (`note_project`; `project_id` stays its
      own, first one) and in an area (`notes.area_id`)
- [x] Habits: start and end dates for any schedule, a status (not started,
      finished), "What it is", "Why it matters", goals (`goal_habit`) and an
      area; editing from a row loads the whole habit first
- [x] Routines: a description, archive / restore
- [x] Habits › This month, and Numbers in a habit's panel (30 days, 3
      months, a year, all): consistency with half credit, trend, bounce-backs,
      hardest weekday, a week, a day on average (`HabitStats::stats`)
- [x] All habits: search, kind, project, paused; habits under their goals on
      the Goals map
- [x] People: nickname, location, usernames, contacts in order, a search
- [x] Date reminders: up to six each at their own times, snooze (an hour,
      tomorrow), the next 30 / 60 / 90 days
- [x] Interactions: a time, change one, "Doesn't count"
- [x] Memories, and "Follow up" (a waiting task with a follow-up day)
- [x] The API for all of it (`docs/api.md`)

## Phase 13 checklist

The rest of `docs/backlog.md`, approved on 16 Nov as suggested: Review gets
your own dates; a capacity view stays out (the Planner's free-time panel
covers it, and Review is about what happened).

- [x] A project can serve several goals (`goal_project`; `goal_id` stays
      its main one and its colour): under each goal on the Goals map,
      counted once in the totals; goal chips; deleting the main goal moves
      the next one up, and Undo puts it back
- [x] This or That on Up next: select 2 or more, pick between two at a time
      until one is left (rank numbers only, the order never changes), then
      Start focus, Doing or Open
- [x] GitHub: Refresh on a task's links and a project's issues; an issue
      with the same title in the same repository isn't posted twice (a lock
      per task for two at once)
- [x] Review → Pick dates: any stretch up to a year, compared with the same
      number of days before; a bar a week for long stretches
- [x] Wallpapers (Settings → Appearance): none, five gradients with a light
      and a dark look, four photos, or your own picture; on the account;
      not in FYP mode
- [x] "Skip to content", and an accessibility pass: a script over every main
      screen and dialog (names, labels, picture text, ids, references),
      contrast of 4.5:1 or more for text, focus back to the opener when a
      popover or dialog closes
- [x] Export and import (Settings → Data): one .zip with every table and
      file; import only into an empty account, with new ids and the links
      inside notes pointed at them (`App\Services\AccountTransfer`)
- [x] The API: lists page by page (`limit`, `cursor`, `meta.next`) for
      tasks, notes and people; projects' `goal_ids`; Review's `range`

## Side menus

The user's rule (18 Nov): a side menu holds only areas and projects; every
other view stays, moved onto its page as tabs and chips, or into Settings.

- [x] Tasks menu: each area with its projects, and a bar fixed at the
      bottom with "New area" and New project (no title row).
      An area's name opens its projects; the arrow folds it; areas move by
      dragging their name, or Move up / down in their menu (with Undo)
- [x] Tasks page tabs: Up next · Not ready · Next actions · All
      (`components/velocity/tasks-head.tsx`); the addresses stay the same
- [x] Inbox is a chip on Up next (tasks with no project, with "To sort"
      above them); `/tasks/inbox` sends you there. The old "Views" were
      already chips; "Low energy" now stays after a reload too
- [x] One bar on every Tasks tab and on the Projects page: List · Board ·
      Timeline · Projects, then a Map button (`TasksSwitch`). Away from Up
      next a layout opens Up next in it. The Projects page has area chips
      (`/projects?area=3`, `?area=none`); the Goals map has "← Tasks"
- [x] Weights only in Settings → Tasks (the page `/weights` sends you there;
      FYP mode keeps its own Weights page); the Up next pop-up is gone
- [x] Templates: a button in Up next's "Add a task" (pick one, or "Manage
      templates"); Archive in the account menu; History removed (it is
      Review › 12 weeks)
- [x] Task panel, top to bottom (20 Nov): the title and status; Waiting for
      and Follow up; Ranking (Importance, Urgency, Due, Work, Energy);
      Subtasks; Steps; then groups that open and close, remembered on the
      device: Schedule (Can start, Starts after, Blocks, Repeats,
      Reminders), Details (Project, Goal, People, Notes, GitHub, Files),
      Log, History (Focused, Via, Activity; closed at first). A closed
      group shows what is set in it
- [x] The Log: entries the user writes about the task, each with files;
      edit, delete with Undo (`task_logs`, `task_files.log_id`). A file
      dropped or pasted anywhere while a task is open goes on the task
      (on an entry when dropped on it, or on the new one when dropped or
      pasted into its box)
- [ ] The other sections' side menus (Planner, Notes, Habits, People,
      Review), when the user gives their changes

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
