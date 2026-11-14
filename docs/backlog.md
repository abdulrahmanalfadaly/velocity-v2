# Backlog: what the GPT version had that v2 doesn't (yet)

Made on 15 Nov 2026 by comparing every screen and option of the GPT version
(`C:\Projects\Velocity`) with v2. The user wants to go through **all of it**
eventually; the top picks (marked ★1 … ★12) come first as Phase 9 in
`PLAN.md`. When an item is built, tick it here and record it in PLAN.md's
checklist for its phase.

Both versions already have: tasks, projects, the day and week planner, the
focus timer, notes, habits, people, Review, API keys and GitHub links. This
list only covers what's missing or partly there.

**Verdicts**

- **Add**: can be built, nothing stands in the way.
- **Partly**: v2 has some of it; the rest can be added.
- **Filter only**: may come in as a filter or a label, never as score points
  or a different order (the report's formula is the only ranking).
- **Your call**: clashes with a choice made on purpose earlier; ask the user.
- **Can't**: needs something v2 doesn't have (a mail server, hosting).
- **Low**: little value; last.
- **Skip**: left out on purpose.

## Tasks

- [ ] ★2 Cancel a task with a reason. **Add** (the data allows "Cancelled"; no way to set it yet)
- [ ] A capture-only Inbox where new tasks wait unsorted and unranked. **Your call** (v2's Inbox is the ranked list of tasks with no project)
- [ ] Target date and energy level. **Filter only** (GPT gave them score points)
- [ ] Link a task straight to a goal; warn when a task is due after its project. **Add**
- [ ] More quick-add words (start date, reminder, importance) and chips you can remove. **Partly** (due, project, hours, repeat work)
- [ ] ★2 Follow-up dates and a "Needs attention" group. **Add**
- [ ] "Make available now" (clear the start date in one click). **Add**
- [ ] Dependencies: a "Blocks" list, stop finishing a task that is still blocked, warnings on cancel or reopen. **Partly**
- [ ] Real subtasks, with "Complete anyway?" when some are still open. **Add**
- [ ] ★2 Archive a task, and auto-archive old finished ones. **Add**
- [ ] ★7 Repeat on several weekdays (Mon + Thu), an end date, skip one, move one without moving the rest. **Partly**
- [ ] Templates with dates like "day 3 after start". **Add**
- [ ] Bulk edit more fields, and bulk reopen. **Partly** (done, move, due date, delete exist)
- [ ] This-or-That (pick between two tasks). **Add**, without changing the order
- [ ] Board and Timeline on project pages; Timeline by week, month or quarter. **Add** (Up next and Inbox have them)
- [ ] ★9 Smart views like "Due today" or "Quick wins" in the side menu. **Partly** (Up next has Due this week, 1 hour or less, Overdue and No project as filters that reset when you leave); filters only, still in formula order
- [ ] Other sorts, drag to reorder, pin a "Next action". **Your call** (breaks "one order")
- [ ] ★9 Next Actions page: the top task of each project. **Add** (the formula picks it)
- [ ] An "All tasks" list that includes finished and archived ones. **Add**
- [ ] A "suggested work" score with time and energy pickers and reasons. **Can't** as GPT did it; **Filter only**; reasons stay in FYP mode
- [ ] ★3 Reminders: several per task, ones that move with the due date, "Bug me" repeats, more snooze choices. **Partly** (one reminder; "before due" is saved as a fixed time; snooze 10 min / 1 h)
- [ ] Paste files into a task, a file count on rows, files on projects. **Partly** (PLAN decision 3 promises project files)
- [ ] Task activity list. **Add** (the data is stored already)
- [ ] Delete a parent task (keep or delete its subtasks); empty the Archive. **Add**

## Projects, goals and areas

- [ ] ★10 Project status, start and target dates, "Complete project". **Add**
- [ ] ★10 An "All projects" page. **Add**
- [ ] Delete a project or goal that still has things in it, with Undo. **Add**
- [ ] ★10 Goal dates, status, and progress by milestones or by hand. **Add** (the Goals map already shows progress from tasks)
- [ ] Several goals for one project or habit. **Add**
- [ ] ★10 Areas: rename, remove, collapse, a menu on each. **Add**

## Planner

- [ ] Month view. **Add** (year view: **Low**)
- [ ] Blocks for a whole project, or "reserved" time. **Add**
- [ ] ★5 Drag to resize a block; type exact times. **Add**
- [ ] ★5 A warning when two things overlap. **Add**
- [ ] A warning before moving a locked block. **Add**
- [ ] ★5 Settings: gap between blocks, default length, day start and end. **Add** (time bands: with them)
- [ ] ★5 A free-time panel, with copy. **Add**
- [ ] Markers for project due dates, milestones, goal targets, with show/hide. **Partly** (task due flags exist)
- [ ] Habit markers, "Show habits" / "Show people" switches. **Add**
- [ ] Advice when a booked task becomes blocked or waiting. **Add**
- [ ] Cancelled events kept in history; locked events; overlap checks for events. **Add**
- [ ] Session chooser, moving a block to another task, block history. **Low**

## Focus

- [ ] ★4 Pomodoro breaks, and a Focus settings page (lengths, auto-start, sound). **Add**
- [ ] ★4 Any length from 1 to 240 minutes, and a stopwatch. **Partly** (25 / 50 / 90 / no limit)
- [ ] ★4 An intention before you start and a note after. **Add**
- [ ] Focus on a project, goal, habit or note (not just a task). **Add**
- [ ] ★4 Focus history, with charts by hour and weekday. **Add**
- [ ] ★4 Finish the task from inside the timer. **Add**
- [ ] At the planned end GPT stopped the timer; v2 keeps counting. **Your call**

## Home, Search and Quick add

- [ ] "I have 30 min, low energy" pickers. **Filter only**
- [ ] Follow-ups on Home, an Inbox hint, a Refresh button, a refresh at midnight. **Add**
- [ ] A projects card, a focus card with breaks, a mini goals map. **Low** (optional)
- [ ] Search: filter by type, include archived, find people by nickname. **Add**
- [ ] ★1 Quick add → Event. **Add** (the tab is there, greyed out)
- [ ] ★1 Quick add → Person. **Add**
- [ ] Quick add: text for a note, keep the draft with Retry on an error, "Add another". **Add**

## Review

- [ ] ★11 A day view. **Add**
- [ ] A custom date range. **Your call** (dropped on purpose in Phase 7)
- [ ] ★11 More numbers: tasks created, cancelled, reopened, postponed; project, goal and milestone results; planner moves; focus by hour and weekday; events; click a number to see the items. **Add**
- [ ] A capacity view. **Your call** (dropped on purpose in Phase 7)
- [ ] ★11 Compare with the previous period (a switch). **Add**

## Notes

- [ ] ★8 Tables. **Add**
- [ ] Toolbar buttons for underline, inline code, a divider line. **Add**
- [ ] PDF preview inside a note. **Add** (page zoom: **Low**)
- [ ] Turn selected text into a task, with a jump back to that paragraph. **Partly** (Make task exists, no jump back)
- [ ] ★8 Links to goals, habits and other notes, with a "linked from" list. **Add**
- [ ] A note in several projects. **Add**
- [ ] Note areas. **Add**
- [ ] Archive a note as read-only. **Add**
- [ ] A drawing board (pen, shapes, arrows, sticky notes, sticky → task, zoom). **Add**; the user decides after using notes
- [ ] The "clash" text. **Skip**

## Habits

Several of these were left out on purpose in Phase 5; the user chose to add
them after seeing this list.

- [ ] ★6 Minutes habits ("read 20 min"). **Add**
- [ ] ★6 Limit habits ("at most 2 coffees") and avoid habits ("no smoking"). **Add**
- [ ] ★6 "5 times a month" and "every 3 days". **Add**
- [ ] Start and end dates, and a status. **Add**
- [ ] ★6 Pause and resume. **Add**
- [ ] ★6 A note on each check-in. **Add**
- [ ] Month view and deeper stats: date range, half credit, bounce-backs, trends, hardest weekday, averages. **Add**
- [ ] Search and filters. **Add**
- [ ] A description and a "why". **Add**
- [ ] Several goals and an area for each habit. **Add**
- [ ] A routine description, and archiving routines. **Add**
- [ ] Focus on a habit. **Add**

## People

- [ ] Nickname, location, usernames as a contact type, reorder contacts. **Add**
- [ ] Search the people list. **Add**
- [ ] Several reminders per date, at any time ahead; snooze; next 30 / 60 / 90 days. **Add**
- [ ] Time of an interaction, edit it, a "doesn't count" switch. **Add**
- [ ] A memories list. **Add** (left out on purpose in Phase 6)
- [ ] Follow-ups as their own items. **Your call** (v2 makes them tasks on purpose)
- [ ] Follow-ups and catch-ups on the planner, with a switch. **Add**

## Mark's API and GitHub

- [ ] ★12 A wider API: archive and cancel, bulk edits, dependencies, reminders, files, templates, editing habits, undo, stats, people details, pausing focus, editing the planner, areas. **Add**
- [ ] ★12 More example Mark scripts (GPT had 16, v2 has 1). **Add**
- [ ] A request id on every reply. **Add** (small)
- [ ] Version checks and "ask again" retries. **Skip**
- [ ] Page-by-page lists with a "next" marker. **Low**
- [ ] ★12 GitHub through the API (search, create issue, refresh, set a project's repo). **Add**
- [ ] A GitHub "Refresh" button, and a guard against posting the same issue twice. **Add**
- [ ] Signing in with a GitHub App. **Can't** easily (needs a registered app and a public web address)

## Settings and general

- [ ] Task, Focus and Planner settings pages. **Add** (Focus and Planner come with ★4 and ★5)
- [ ] Wallpapers. **Add**
- [ ] A "skip to content" link and accessibility checks. **Add**
- [ ] Export and import your data (neither version has it). **Add** (bonus)
- [ ] Email confirmation and real password-reset emails. **Can't** without a mail server (Gmail's could do it later)
- [ ] Reminders while the app is closed. **Can't** without hosting
- [ ] Using the app from anywhere. **Can't** without hosting

## Top picks (Phase 9), in order

1. Quick add Event and Person.
2. Follow-ups, cancel with a reason, archive a task.
3. Reminders: "Bug me", several per task, more snooze choices.
4. Focus: Pomodoro breaks and settings, intention and note, history.
5. Planner: resize and exact times, overlap warning, gaps and default length, free time.
6. Habits: limit and avoid, every N days and N a month, pause, check-in notes.
7. Repeats: several weekdays, an end date, skip one.
8. Notes: tables, links to notes, goals and habits.
9. Smart-view filters and a Next Actions page (formula order).
10. Areas, project status and completion, goal progress.
11. More numbers in Review.
12. A wider API for Mark.
