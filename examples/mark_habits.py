"""Mark: habits.

    python mark_habits.py                                    # today's habits (reads only)
    python mark_habits.py show 3                              # streak, best, notes
    python mark_habits.py check 3 [--count 20]                # done today (minutes / how many)
    python mark_habits.py day 3 2026-11-10 1 --note "Easy pace"
    python mark_habits.py pause 3 [--until 2026-11-20] | resume 3
"""

import argparse

from mark_velocity import call


def main() -> None:
    p = argparse.ArgumentParser()
    sub = p.add_subparsers(dest="cmd")
    sub.add_parser("list")
    sub.add_parser("show").add_argument("id", type=int)
    c = sub.add_parser("check")
    c.add_argument("id", type=int)
    c.add_argument("--count", type=int)
    d = sub.add_parser("day")
    d.add_argument("id", type=int)
    d.add_argument("date")
    d.add_argument("count", type=int)
    d.add_argument("--note")
    ps = sub.add_parser("pause")
    ps.add_argument("id", type=int)
    ps.add_argument("--until")
    sub.add_parser("resume").add_argument("id", type=int)
    a = p.parse_args()

    if a.cmd in (None, "list"):
        for h in call("GET", "/habits")["data"]:
            print(f"[{h['group']}] {h['name']} · {h['label']} · streak {h['streak']}")
    elif a.cmd == "show":
        h = call("GET", f"/habits/{a.id}")["data"]
        print(f"{h['name']}: streak {h['streak']}, best {h['best']}")
        for n in h["notes"][:5]:
            print(f"  {n['date']}: {n['note']}")
    elif a.cmd == "check":
        h = call("POST", f"/habits/{a.id}/check", {"count": a.count} if a.count is not None else {})["data"]
        print(f"{h['name']}: {h['today']} today")
    elif a.cmd == "day":
        call("PUT", f"/habits/{a.id}/day", {"date": a.date, "count": a.count, "note": a.note})
        print("Saved")
    elif a.cmd == "pause":
        print(call("POST", f"/habits/{a.id}/pause", {"until": a.until})["data"]["group"])
    elif a.cmd == "resume":
        print(call("POST", f"/habits/{a.id}/resume")["data"]["group"])


if __name__ == "__main__":
    main()
