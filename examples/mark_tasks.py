"""Mark: tasks.

    python mark_tasks.py                               # the ranking (reads only)
    python mark_tasks.py add "Ethics essay outline fri 3h #fyp"
    python mark_tasks.py done 42
    python mark_tasks.py cancel 42 --reason "Supervisor dropped it"
    python mark_tasks.py skip 42                       # a repeating task: skip only this one
    python mark_tasks.py due 42 2026-11-20             # a new due date (the end of that day)
    python mark_tasks.py follow-up 42 2026-11-18
    python mark_tasks.py remind 42 --before 1d         # 15m 1h 3h 0d 1d 2d 7d
    python mark_tasks.py remind 42 --at "2026-11-19 09:00"
    python mark_tasks.py bug 42 --every 60             # until it's done
"""

import argparse

from mark_velocity import call


def main() -> None:
    p = argparse.ArgumentParser()
    sub = p.add_subparsers(dest="cmd")
    sub.add_parser("list")
    sub.add_parser("add").add_argument("text")
    sub.add_parser("done").add_argument("id", type=int)
    c = sub.add_parser("cancel")
    c.add_argument("id", type=int)
    c.add_argument("--reason")
    sub.add_parser("skip").add_argument("id", type=int)
    d = sub.add_parser("due")
    d.add_argument("id", type=int)
    d.add_argument("date")
    f = sub.add_parser("follow-up")
    f.add_argument("id", type=int)
    f.add_argument("date")
    r = sub.add_parser("remind")
    r.add_argument("id", type=int)
    r.add_argument("--before")
    r.add_argument("--at")
    b = sub.add_parser("bug")
    b.add_argument("id", type=int)
    b.add_argument("--every", type=int, default=60)
    a = p.parse_args()

    if a.cmd in (None, "list"):
        for t in call("GET", "/tasks?limit=20")["data"]:
            print(f"#{t['rank']} {t['title']}  ({t['score']})")
    elif a.cmd == "add":
        t = call("POST", "/tasks", {"text": a.text})["data"]
        print(f"Added #{t['rank']} {t['title']}")
    elif a.cmd == "done":
        print("Done:", call("POST", f"/tasks/{a.id}/complete")["data"]["title"])
    elif a.cmd in ("cancel", "skip"):
        body = {"reason": getattr(a, "reason", None), "skip": a.cmd == "skip"}
        t = call("POST", f"/tasks/{a.id}/cancel", body)["data"]
        print(f"{'Skipped' if a.cmd == 'skip' else 'Cancelled'}: {t['title']}" + (f" (next: {t['next']['dueAt']})" if t.get("next") else ""))
    elif a.cmd == "due":
        print("Due:", call("PATCH", f"/tasks/{a.id}", {"due_at": a.date})["data"]["dueAt"])
    elif a.cmd == "follow-up":
        print("Follow up on:", call("PATCH", f"/tasks/{a.id}", {"follow_up_on": a.date})["data"]["followUpOn"])
    elif a.cmd == "remind":
        body = {"kind": "before", "before": a.before} if a.before else {"kind": "at", "at": a.at}
        print("Reminder:", call("POST", f"/tasks/{a.id}/reminders", body)["data"]["nextAt"])
    elif a.cmd == "bug":
        call("POST", f"/tasks/{a.id}/reminders", {"kind": "bug", "every": a.every})
        print(f"Bugging every {a.every} min until it's done")


if __name__ == "__main__":
    main()
