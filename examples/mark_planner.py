"""Mark: the Planner.

    python mark_planner.py                                   # the next 7 days (reads only)
    python mark_planner.py free 2026-11-12 [--week]          # free time, ready to send to someone
    python mark_planner.py book 42 2026-11-12 14:00 90       # book a task (says what it overlaps)
    python mark_planner.py move 7 2026-11-13 09:00 [--minutes 60]
    python mark_planner.py plan day|week
"""

import argparse

from mark_velocity import call


def main() -> None:
    p = argparse.ArgumentParser()
    sub = p.add_subparsers(dest="cmd")
    sub.add_parser("show")
    f = sub.add_parser("free")
    f.add_argument("date")
    f.add_argument("--week", action="store_true")
    b = sub.add_parser("book")
    for name in ("task", "date", "start", "minutes"):
        b.add_argument(name)
    m = sub.add_parser("move")
    m.add_argument("block", type=int)
    m.add_argument("date")
    m.add_argument("start")
    m.add_argument("--minutes", type=int)
    sub.add_parser("plan").add_argument("span", choices=["day", "week"])
    a = p.parse_args()

    if a.cmd in (None, "show"):
        plan = call("GET", "/planner")["data"]
        for e in plan["events"]:
            print(f"{e['start'][:16]}  {e['title']}")
        for blk in plan["booked"]:
            print(f"{blk['start'][:16]}  [booked] {blk['title']}")
    elif a.cmd == "free":
        days = call("GET", f"/planner/free?date={a.date}&span={'week' if a.week else 'day'}")["data"]
        for d in days:
            slots = ", ".join(f"{s['start'][11:16]}–{s['end'][11:16]} UTC" for s in d["slots"]) or "no free time"
            print(f"{d['date']}: {slots}")
    elif a.cmd == "book":
        blk = call("POST", "/planner/blocks", {"task_id": int(a.task), "date": a.date, "start": a.start, "minutes": int(a.minutes)})["data"]
        print("Booked", blk["title"], "· overlaps " + ", ".join(o["title"] for o in blk["overlaps"]) if blk["overlaps"] else "")
    elif a.cmd == "move":
        body = {"date": a.date, "start": a.start} | ({"minutes": a.minutes} if a.minutes else {})
        print("Moved", call("PATCH", f"/planner/blocks/{a.block}", body)["data"]["title"])
    elif a.cmd == "plan":
        r = call("POST", "/planner/plan", {"span": a.span})["data"]
        print(f"Booked {r['bookedMinutes']} min; didn't fit: {len(r['didNotFit'])}")


if __name__ == "__main__":
    main()
