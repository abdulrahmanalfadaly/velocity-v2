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
| `tasks` | the ranking, a task, the focus timer | add, change, done / not done, delete / restore, steps, start / stop focus |
| `projects` | projects (with milestones), goals | add / change projects and goals, tick milestones |
| `planner` | classes, events, booked time | add / change / remove events, plan a day or week |
| `notes` | notes, the journal | write notes, add to the journal |
| `habits` | how each habit stands | add, tick (or a count), skip |
| `people` | people, dates, catch-ups due | add people, log a catch-up |
| `review` | week / month numbers, weekly review | – |
| `github` | a task's linked issues and pull requests | link and unlink them |

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
| `GET /tasks?view=upnext` | the ranking. `view`: `upnext` (default), `inbox` (ranked, no project), `waiting` (with `kind` and `why`), `done`. Also `project_id`, `q`, `limit` (≤ 200). |
| `GET /tasks/{id}` | one task, with what it starts after, notes, people, minutes focused, history |
| `POST /tasks` | `{"text": "Ethics essay outline fri 3h #fyp"}` like quick add (optional `importance`, `urgency`, `project_id`), or fields: `title`, `importance`, `urgency`, `due_at`, `est_hours`, `notes`, `project_id`, `depends_on` … |
| `POST /tasks/preview` | where a task would land, without saving: `{"text": …}` or `importance`, `urgency`, `due_at`, `est_hours` → `rank`, `of`, `score` |
| `PATCH /tasks/{id}` | any of: `title`, `notes`, `importance`, `urgency`, `due_at`, `est_hours`, `status` (todo / doing / waiting), `waiting_for`, `project_id`, `repeat_unit`, `repeat_every`, `available_from`, `reminder_at`, `depends_on` |
| `POST /tasks/{id}/complete` | done; a repeating task's next copy is in `meta.next` |
| `POST /tasks/{id}/reopen` | not done again |
| `DELETE /tasks/{id}` · `POST /tasks/{id}/restore` | delete, and bring back |
| `POST /tasks/{id}/steps` · `PATCH /steps/{id}` | add a step `{"title"}`; tick `{"done": true}` or rename |
| `GET /focus` · `POST /focus` · `POST /focus/stop` | the running session; start `{"task_id"?, "minutes"?}`; stop (under a minute isn't kept: `counted: false`) |

### Projects and goals (`projects`)

`GET /projects` (`?archived=1` for all) · `GET /projects/{id}` (milestones, and
its open tasks in rank order if the key reads tasks) · `POST /projects`
`{"name", "description"?, "due_date"?, "goal_id"?, "area_id"?}` ·
`PATCH /projects/{id}` · `PATCH /milestones/{id}` `{"done": true}` ·
`GET /goals` · `POST /goals` `{"title", "why"?, "target"?}` · `PATCH /goals/{id}`

### Planner (`planner`)

| | |
|---|---|
| `GET /planner?from=2026-11-16&to=2026-11-22` | events (each time a weekly class happens), booked time, habit times, people's dates, tasks due. Default: the next 7 days; at most 62. |
| `POST /planner/plan` | `{"span": "day"}` or `"week"`, optional `date`, `task_id`: books free time in rank order → `bookedMinutes`, `didNotFit` |
| `POST /events` | `{"title", "calendar": "class"/"work"/"personal", "date", "start_time", "end_time"}` or `"all_day": true`; `location`, `notes`, `repeat_weekly`, `repeat_until` |
| `PATCH /events/{id}` | any of the same fields |
| `DELETE /events/{id}` | the whole event; with `{"date"}` only that week of a weekly one |

### Notes (`notes`)

Text goes in and out as plain text; each line is a paragraph. `doc` holds the
full document the editor uses.

| | |
|---|---|
| `GET /notes?in=all` | `in`: `all` (not the journal), `journal`, `pinned`; `q`, `project_id`, `limit` |
| `GET /notes/{id}` | with `text`, `doc`, linked tasks and people |
| `POST /notes` | `{"title", "text", "project_id"?, "task_id"?}` |
| `PATCH /notes/{id}` | `title`, `text` (replaces), `append` (adds lines at the end), `pinned`, `project_id` |
| `GET /journal?date=` | that day's entry (today if no date), or `null` |
| `POST /journal` | `{"text": "Chapter 3 approved.", "date"?}`: adds to the entry, starting it if needed |

### Habits (`habits`)

`GET /habits?date=` (each habit's state that day, streak, due or not) ·
`POST /habits` `{"name", "schedule": "daily"/"days"/"weekly", "days"?, "per_week"?, "target"?, "unit"?, "at_time"?, "minutes"?}` ·
`POST /habits/{id}/check` `{"date"?, "count"?}` (no count = all of it, `0` = not
done; not ahead of today) · `POST /habits/{id}/skip` `{"date"?}`

### People (`people`)

`GET /people?view=all` (`attention`: catch-up due or a date within a week;
`dates`: dates in the next 60 days) · `GET /people/{id}` · `POST /people`
`{"name", "relationship"?, "organisation"?, "catch_up_days"?, "contacts"?: [{"kind": "email", "value": "…"}]}` ·
`POST /people/{id}/catch-ups` `{"kind": "message"/"call"/"met"/"other", "on"?, "note"?}`

### GitHub (`github`)

GitHub must be connected in Settings → Integrations (a fine-grained token).

| | |
|---|---|
| `GET /tasks/{id}/github` | the task's linked issues and pull requests: `repo`, `number`, `kind` (issue / pull), `title`, `state` (open / closed / merged / draft), `url`. Read again from GitHub when a few minutes old; `meta.error` says if GitHub couldn't be reached (what was known is still there). |
| `POST /tasks/{id}/github` | `{"url": "https://github.com/owner/name/issues/42"}` (or `/pull/42`) |
| `DELETE /tasks/{id}/github/{linkId}` | unlink (nothing changes on GitHub) |

GitHub problems answer with code `GITHUB`: `409` (not connected, or the token
stopped working), `422` (not found, or the token can't see it), `503` (GitHub
can't be reached).

### Review (`review`)

`GET /review?period=week&date=` (the week or month's numbers, compared with the
same days before, as on the Review page) · `GET /review/weekly` (the weekly
review's list for this week)

## Example

See [`examples/mark_velocity.py`](../examples/mark_velocity.py): Python, no
packages to install.
