"""Mark: the focus timer.

    python mark_focus.py                                     # what the timer is doing (reads only)
    python mark_focus.py start 42 --pomodoro --intention "Draft 4.2"
    python mark_focus.py start 42 --minutes 50
    python mark_focus.py pause | resume | stop
    python mark_focus.py next                                # Pomodoro: the next round now
    python mark_focus.py end                                 # Pomodoro: done for now
    python mark_focus.py history [--month]
"""

import argparse

from mark_velocity import call


def main() -> None:
    p = argparse.ArgumentParser()
    sub = p.add_subparsers(dest="cmd")
    s = sub.add_parser("start")
    s.add_argument("task", type=int, nargs="?")
    s.add_argument("--pomodoro", action="store_true")
    s.add_argument("--minutes", type=int)
    s.add_argument("--intention")
    for name in ("status", "pause", "resume", "stop", "next", "end"):
        sub.add_parser(name)
    sub.add_parser("history").add_argument("--month", action="store_true")
    a = p.parse_args()

    if a.cmd in (None, "status"):
        t = call("GET", "/focus")["data"]
        print("Nothing running" if t is None else f"{t['phase']}: {t.get('taskTitle') or 'focus'} (round {t.get('round')})")
    elif a.cmd == "start":
        body = {"task_id": a.task, "intention": a.intention} | ({"mode": "pomodoro"} if a.pomodoro else {"minutes": a.minutes})
        print("Started", call("POST", "/focus", body)["data"].get("taskTitle") or "focus")
    elif a.cmd in ("pause", "resume", "stop", "next", "end"):
        call("POST", f"/focus/{a.cmd}")
        print(a.cmd.capitalize(), "done")
    elif a.cmd == "history":
        h = call("GET", f"/focus/history?range={'month' if a.month else 'week'}")["data"]
        print(f"{h['total']} min in {h['count']} sessions; longest {h['longest']} min")
        busiest = max(range(24), key=lambda i: h["hours"][i])
        print(f"Most focused hour: {busiest}:00")


if __name__ == "__main__":
    main()
