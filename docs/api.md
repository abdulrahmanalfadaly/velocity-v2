# Velocity API (v1)

For Mark, or anything else that should read or change Velocity. The screens and
the API run the same code, so a task completed through the API behaves exactly
like one ticked on screen (its repeat copy, its score at completion …).

## Keys

Make a key in **Settings → Integrations** (personal mode). Give it a name, full
access or a choice per area, and when it stops working. The key is shown once;
Velocity keeps only a fingerprint of it. Revoking is immediate and final.

Send it with every request:

```
Authorization: Bearer vel_…
Accept: application/json
```

The address is `http://localhost:8200/api/v1` on this computer (the
Integrations page shows it). Keep keys out of scripts and repositories: Mark
reads `VELOCITY_API_TOKEN` and `VELOCITY_BASE_URL` from the environment.

| Area | Read | Write |
|---|---|---|
| `tasks` | the ranking, a task, Next actions, reminders, templates, the focus timer and its history | add, change, done / not done, cancel, archive, delete / restore, steps, reminders, the selection bar's actions, templates, the focus timer (Pomodoro too) |
| `projects` | projects (with milestones and their ranked tasks), goals, areas | add / change projects, goals and areas, project status and completing, tick milestones |
| `planner` | classes, events, booked time, free time | add / change / remove events, book / move / resize / remove blocks, plan a day or week |
| `notes` | notes (with their links), the journal | write notes, add to the journal, link notes to notes, goals and habits |
| `habits` | how each habit stands, one habit in full | add, change, tick (or a count), set a day (with a note), skip, pause / resume, archive |
| `people` | people, dates, catch-ups due | add and change people, log a catch-up, archive |
| `review` | day / week / month numbers and what changed, weekly review | – |
| `github` | a task's linked issues and pull requests, searching, a project's repository | link and unlink, create an issue, read again, set a project's repository |

Each key may make 120 requests a minute. Changes made with a key are marked
with its name ("via Mark") in a task's history.

## Answers and errors

- Success: `{"data": …}`, sometimes with `"meta"`. New things answer `201`.
- Error: `{"error": {"code": "…", "message": "…"}}`; a `422` adds `"fields"`
  with a message per field.
  - `401 UNAUTHENTICATED`: no key, a wrong one, revoked or expired.
  - `403 FORBIDDEN`: the key lacks an area ("This key needs Notes: write.").
  - `404 NOT_FOUND`: doesn't exist, or isn't yours (never a hint which).
  - `422 INVALID`, `429 TOO_MANY_REQUESTS`.
- Times are UTC (`2026-11-13T15:59:00Z`). Times you send are read in your own
  time zone (`"2026-11-20 17:00"`) unless they carry an offset. A plain date as a
  due date means the end of that day.
- Keys in answers are camelCase; fields you send are snake_case, as on the
  screens' forms.
- Every answer has an `X-Request-Id` header: the one you sent (letters, digits
  and dashes, 8–64 long), or a new one, for matching Mark's log to an answer.
- Long lists come a page at a time (`GET /tasks`, `/notes`, `/people`): `limit`
  (1–200, 50 to start with), then `cursor` set to the last answer's `meta.next`
  until that is `null`. `meta.count` is how many there are in all. A made-up
  cursor answers `422`.

## A task

```json
{
  "id": 12, "title": "Write Chapter 4 – Implementation", "status": "todo",
  "importance": 5, "urgency": 4, "dueAt": "2026-11-13T15:59:00Z", "estHours": 6,
  "project": {"id": 1, "name": "Final Year Project"}, "steps": [],
  "rank": 1, "score": 85.1,
  "parts": {"importance": 42, "urgency": 17.3, "deadline": 19.3, "workload": 6.5},
  "url": "http://localhost:8200/tasks?task=12"
}
```

`rank` and `score` come from the report's formula; `parts` are the four
weighted criteria (they add up to the score). A task that isn't in the ranking
(done, waiting, starting later) has `rank: null`. Statuses: `todo`, `doing`,
`waiting`, `done`, `cancelled`.

## Endpoints

### Overview

| | | |
|---|---|---|
| `GET /health` | no key | `{"status": "ok"}` |
| `GET /me` | any key | your name, time zone, today, the key's name and access |
| `GET /today` | parts by area | `tasks` (top 5, overdue, due today, running focus), `plan` (today's events and booked time), `habits` due today, `people` (dates this week, catch-ups due), `notes` (today's journal entry). A part the key can't read is `null`. |
| `GET /search?q=chap` | parts by area | matches grouped by kind, only kinds the key can read |

### Tasks (`tasks`)

| | |
|---|---|
| `GET /tasks?view=upnext` | the ranking. `view`: `upnext` (default), `inbox` (ranked, no project), `captured` (captured only, waiting to be sorted, not ranked), `waiting` (with `kind` and `why`), `done`, `cancelled`, `archived` (as on All tasks). Also `project_id`, `q`, and `limit` / `cursor` (page by page, in rank order for the ranked views). Each task has `energy` (`low` / `normal` / `high` / `null`: a filter only, never in the score) and `capturedAt`. |
| `GET /tasks/{id}` | one task, with its `subtasks`, notes, people, minutes focused, `activity` (its history in words) and `history` |
| `POST /tasks` | `{"text": "Ethics essay outline fri 3h #fyp"}` like quick add (also `from mon`, `remind 9am` / `remind 1h before`, `!` / `!!`, `~low` / `~normal` / `~high` for the energy it needs; optional `importance`, `urgency`, `project_id`, and `"capture": true` to keep it out of the ranking until `POST /tasks/{id}/rank`), or fields: `title`, `importance`, `urgency`, `due_at`, `est_hours`, `notes`, `project_id` … |
| `POST /tasks/preview` | where a task would land, without saving: `{"text": …}` or `importance`, `urgency`, `due_at`, `est_hours` → `rank`, `of`, `score` |
| `PATCH /tasks/{id}` | any of: `title`, `notes`, `importance`, `urgency`, `due_at`, `est_hours`, `status` (todo / doing / waiting), `waiting_for`, `project_id`, `goal_id` (only for a task with no project), `repeat_unit`, `repeat_every`, `available_from`, `reminder_at` (one "at a time" reminder; `null` removes them), `follow_up_on` (YYYY-MM-DD) |
| `POST /tasks/{id}/rank` | a captured task joins the ranking where the formula puts it |
| `POST /tasks/{id}/complete` | done; a repeating task's next copy is in `meta.next`. `{"children": "complete"}` finishes its open subtasks too |
| `POST /tasks/{id}/subtasks` | `{"title"}`: a subtask, ranked like any task (it starts with the parent's importance, urgency and due date) |
| `POST /tasks/{id}/reopen` | not done again |
| `DELETE /tasks/{id}` · `POST /tasks/{id}/restore` | delete (`{"children": "delete"}` takes its subtasks too), and bring back (with them) |
| `POST /tasks/{id}/steps` · `PATCH /steps/{id}` | add a step `{"title"}`; tick `{"done": true}` or rename |
| `POST /tasks/{id}/cancel` | `{"reason"?, "skip"?, "children"?: "cancel"}`: it won't be done; `skip` on a repeating task makes the next copy (in `next`); `"children": "cancel"` cancels its open subtasks too (they stay open otherwise) |
| `POST /tasks/{id}/archive` · `/unarchive` | a done or cancelled task out of the lists (still in History and Review) |
| `POST /tasks/bulk` | `{"ids": […≤100], "action": …}`: `complete`, `cancel`, `delete`, `move` (`project_id`), `due` (`due_at`), `importance` / `urgency` (1–5), `status` (todo / doing / waiting), `follow_up` (`follow_up_on` or `null`) on open tasks; `reopen`, `archive`, `unarchive` on finished ones |
| `GET /tasks/next` | Next actions: each project's best task by the formula (`rows`), and projects with nothing ready (`idle`, with `why`) |
| `GET /tasks/{id}/reminders` · `POST /tasks/{id}/reminders` | up to 5: `{"kind": "at", "at": "2026-11-20 09:00"}`, `{"kind": "before", "before": "15m"/"1h"/"3h"/"0d"/"1d"/"2d"/"7d"}` (moves with the due date), `{"kind": "bug", "every": 15/30/60/120}` (until done) |
| `DELETE /reminders/{id}` · `POST /reminders/{id}/snooze` | remove; snooze `{"minutes"}` or `{"until": "2026-11-12 09:00"}` |
| `GET /templates` · `POST /templates/{id}/use` | the templates (with `dueDays`, `dueTime`, `startDays`: dates counted from the day it is used); a task from one `{"due_at"?, "project_id"?}` (no `due_at`: the template's own) |
| `GET /focus` | the timer now: a running session (`phase: "focus"`), a Pomodoro between rounds (`roundDone`, `break`, `breakOver`, with `breakEndsAt`), or `null` |
| `POST /focus` · `POST /focus/stop` | start `{"task_id"?, "minutes"?, "mode"?: "timer"/"pomodoro", "intention"?}` (no minutes = no limit), or on something else with one of `project_id`, `goal_id`, `habit_id`, `note_id` (the session's `subject` names it); stop (under a minute isn't kept: `counted: false`). With Settings → Focus "Stop" (`timeUp`), a timer stops at its length. |
| `POST /focus/pause` · `/resume` · `/next` · `/end` | pause and go on; Pomodoro: the next round now, or done for now |
| `GET /focus/history?range=week&date=` · `PATCH /focus/{id}` | sessions, focus by hour and weekday (`range`: week / month); the note after `{"note"}` |

### Projects and goals (`projects`)

`GET /projects` (`?archived=1` for all) · `GET /projects/{id}` (milestones, and
its open tasks in rank order if the key reads tasks) · `POST /projects`
`{"name", "description"?, "due_date"?, "goal_ids"?, "area_id"?}` ·
`PATCH /projects/{id}` · `PATCH /milestones/{id}` `{"done": true}` ·
a project can serve several goals: `goal_ids` lists them, the first is its main one (its colour, `goal`);
answers have `goalIds` (the old `goal_id` still sets just the main one) ·
`GET /goals` · `POST /goals` `{"title", "why"?, "target"?, "target_date"?, "status"?, "progress_mode"?: "tasks"/"milestones"/"manual", "progress"?}` · `PATCH /goals/{id}` ·
`POST /projects/{id}/status` `{"status": "planned"/"active"/"on_hold"}` (planned and on-hold tasks wait) ·
`POST /projects/{id}/complete` `{"open": "cancel"/"keep"}` · `POST /projects/{id}/reopen` ·
`GET /projects/{id}/tasks` (`ranked` in the one ranking's order, `notReady`) ·
`DELETE /projects/{id}` `{"tasks": "inbox"/"delete"}` (its notes and habits stay, out of it; `meta.undo` is what
`POST /projects/{id}/restore` takes back) · `DELETE /goals/{id}` (its projects and tasks stay, without it; a project that serves another goal too gets that as its main one) ·
`POST /goals/{id}/restore` (send `meta.undo`) ·
`GET /areas` · `POST /areas` `{"name", "icon"?}` · `PATCH /areas/{id}`

### Planner (`planner`)

| | |
|---|---|
| `GET /planner?from=2026-11-16&to=2026-11-22` | events (each time a weekly class happens, with `locked` and `cancelled`), booked time (`kind`: task / project / reserved; `locked`; a project's `next` task; `blocked` with why when a booked task can't be worked on now), habit times, people's dates, `markers` (project and milestone due dates, goal target dates, habits with no set time, catch-ups), tasks due. Default: the next 7 days; at most 62. |
| `POST /planner/plan` | `{"span": "day"}` or `"week"`, optional `date`, `task_id`: books free time in rank order → `bookedMinutes`, `didNotFit` |
| `POST /events` | `{"title", "calendar": "class"/"work"/"personal", "date", "start_time", "end_time"}` or `"all_day": true`; `location`, `notes`, `repeat_weekly`, `repeat_until` |
| `PATCH /events/{id}` | any of the same fields |
| `DELETE /events/{id}` | the whole event; with `{"date"}` only that week of a weekly one |
| `PATCH /events/{id}/move` | `{"occurrence": "2026-11-19", "date", "start", "minutes", "scope": "all"/"one"}`: a weekly event moves every week, or only that week (a one-off `copy` takes its place). Locked (`"locked": true` on the event): needs `{"anyway": true}` |
| `POST /events/{id}/cancel` | `{"date"}`: that day is cancelled (it stays on the Planner, crossed out, and takes no time); `{"date", "cancelled": false}` takes it back |
| `GET /planner/free?date=&span=day` | free time in working hours from now on, around events, habit times and booked blocks: per day, `slots` and `minutes` (`span`: day / week) |
| `POST /planner/blocks` | book a task `{"task_id", "date", "start": "14:00", "minutes"}`, a whole project `{"kind": "project", "project_id", …}` or reserved time `{"kind": "reserved", "label": "Gym", …}`; the answer lists what it `overlaps` (a warning only) |
| `PATCH /planner/blocks/{id}` · `DELETE /planner/blocks/{id}` | move or resize `{"date"?, "start"?, "minutes"?}` (a locked block: `409` unless `{"anyway": true}`), `{"locked": true}`, give a task's time to another task `{"task_id"}`; remove |

### Notes (`notes`)

Text goes in and out as plain text; each line is a paragraph. `doc` holds the
full document the editor uses.

| | |
|---|---|
| `GET /notes?in=all` | `in`: `all` (not the journal), `journal`, `pinned`, `archived` (archived notes show only here); `q`, `project_id` (its first project or one of the others), `area_id`, `limit` / `cursor` (page by page, last changed first). Each note has `projectIds`, `areaId`, `archivedAt` |
| `GET /notes/{id}` | with `text`, `doc`, linked tasks and people |
| `POST /notes` | `{"title", "text", "project_id"?, "task_id"?}` |
| `PATCH /notes/{id}` | `title`, `text` (replaces), `append` (adds lines at the end), `pinned`, `project_id` (moves it), `project_ids` (all its projects, its own first), `area_id`, `archived` (an archived note is read-only: `422` until `{"archived": false}`) |
| `GET /journal?date=` | that day's entry (today if no date), or `null` |
| `POST /journal` | `{"text": "Chapter 3 approved.", "date"?}`: adds to the entry, starting it if needed |
| `POST /notes/{id}/links` · `DELETE /notes/{id}/links/{linkId}` | link to a note, goal or habit `{"type": "goal", "id": 3}`; unlink |

### Habits (`habits`)

`GET /habits?date=` (each habit's state that day, streak, due or not) ·
`POST /habits` `{"name", "schedule": "daily"/"days"/"weekly", "days"?, "per_week"?, "target"?, "unit"?, "at_time"?, "minutes"?, "starts_on"?, "ends_on"?, "description"?, "why"?, "goal_ids"?, "area_id"?}` ·
`POST /habits/{id}/check` `{"date"?, "count"?}` (no count = all of it, `0` = not
done; not ahead of today) · `POST /habits/{id}/skip` `{"date"?}` ·
`GET /habits/{id}?range=30` (streak, best, rate, 16 weeks, notes, `status`, `description`, `why`, `goals`, `area`, and `stats` for
`range` 30 / 90 / 365 / all: `rate` with partly done days as half, `trend`, `bounceBacks`, `hardestDay` 1–7, `perWeek`, `average`) ·
`PATCH /habits/{id}` (any field: `kind` do / minutes / limit / avoid, `schedule` daily / days / weekly / monthly / every with
`per_month`, `every_days`, `starts_on`, `ends_on` (not due before or after), `goal_ids` …) ·
`PUT /habits/{id}/day` `{"date", "count", "skipped"?, "note"?}` · `POST /habits/{id}/pause` `{"until"?}` · `POST /habits/{id}/resume` ·
`POST /habits/{id}/archive` (`{"undo": true}` brings it back)

### People (`people`)

`GET /people?view=all` (`attention`: catch-up due or a date within a week;
`dates`: dates in the next 60 days; `all` and `attention` come page by page with `limit` / `cursor`) · `GET /people/{id}` (with `memories`) · `POST /people`
`{"name", "nickname"?, "relationship"?, "organisation"?, "location"?, "catch_up_days"?, "contacts"?: [{"kind": "email"/"phone"/"link"/"username"/"other", "label"?, "value": "…"}]}` ·
`POST /people/{id}/catch-ups` `{"kind": "message"/"call"/"met"/"other", "on"?, "at"?: "18:30", "note"?, "counts"?: false}` (one that doesn't count leaves the catch-up clock alone) ·
`PATCH /people/{id}` `{"name"?, "nickname"?, "relationship"?, "organisation"?, "location"?, "catch_up_days"?}` · `POST /people/{id}/archive` (`{"undo": true}` brings them back) ·
`POST /people/{id}/memories` `{"text", "on"?}` · `POST /people/{id}/follow-up` `{"on", "title"?}`: a task waiting on them until that day (it stays a task)

### GitHub (`github`)

GitHub must be connected in Settings → Integrations (a fine-grained token).

| | |
|---|---|
| `GET /tasks/{id}/github` | the task's linked issues and pull requests: `repo`, `number`, `kind` (issue / pull), `title`, `state` (open / closed / merged / draft), `url`. Read again from GitHub when a few minutes old; `meta.error` says if GitHub couldn't be reached (what was known is still there). |
| `POST /tasks/{id}/github` | `{"url": "https://github.com/owner/name/issues/42"}` (or `/pull/42`) |
| `DELETE /tasks/{id}/github/{linkId}` | unlink (nothing changes on GitHub) |
| `GET /tasks/{id}/github/search?q=` | issues and pull requests in the project's repository (or `repo=owner/name`) |
| `POST /tasks/{id}/github/issue` | a new issue for the task, linked: `{"title", "body"?, "repo"?}` (the project's repository by default). If the task already has an issue with that title in that repository, nothing is posted: that issue comes back with `200` (a new one is `201`). |
| `POST /tasks/{id}/github/refresh` | read the task's links again from GitHub now |
| `GET /projects/{id}/github` · `PUT /projects/{id}/github` | the project's repository; set it `{"repo": "owner/name"}` (checked with GitHub) or `null` |

GitHub problems answer with code `GITHUB`: `409` (not connected, or the token
stopped working), `422` (not found, or the token can't see it), `503` (GitHub
can't be reached).

### Review (`review`)

`GET /review?period=week&date=` (`period`: day / week / month, or `range` with
`from` and `to` (YYYY-MM-DD, up to a year), compared with the same number of days before; the numbers,
compared with the same days before, and `activity`: tasks added, cancelled,
reopened, postponed, projects completed, goals achieved, milestones reached,
blocks moved, each with its items; `events`: minutes by calendar, as on the
Review page) · `GET /review/weekly` (the weekly review's list for this week)

## Example

See [`examples/mark_velocity.py`](../examples/mark_velocity.py): Python, no
packages to install. Shorter scripts for each part use it: `mark_today.py`,
`mark_tasks.py`, `mark_planner.py`, `mark_focus.py`, `mark_habits.py`,
`mark_notes.py`, `mark_people.py`, `mark_review.py` (each reads only when run
without a command).
