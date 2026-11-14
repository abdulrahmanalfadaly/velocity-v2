"""Mark: looking back (reads only).

    python mark_review.py                                    # this week
    python mark_review.py day | month [--date 2026-11-10]
"""

import argparse

from mark_velocity import call


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("period", nargs="?", default="week", choices=["day", "week", "month"])
    p.add_argument("--date")
    a = p.parse_args()

    r = call("GET", f"/review?period={a.period}" + (f"&date={a.date}" if a.date else ""))["data"]
    n, b = r["now"], r["before"]
    print(f"{r['start']} to {r['last']}{' (so far)' if r['inProgress'] else ''}")
    print(f"  Tasks done {n['tasks']} (before {b['tasks']}) · focused {n['focus']} min (before {b['focus']})")
    for key, label in [("created", "added"), ("cancelled", "cancelled"), ("reopened", "opened again"), ("postponed", "postponed"),
                       ("projects", "projects completed"), ("goals", "goals achieved"), ("milestones", "milestones reached"), ("moved", "blocks moved")]:
        if r["activity"][key]["count"]:
            print(f"  {r['activity'][key]['count']} {label}")
            for item in r["activity"][key]["items"][:3]:
                print(f"    {item['title']}" + (f" · {item['detail']}" if item["detail"] else ""))
    e = r["events"]
    print(f"  Events: classes {e['class']} min, work {e['work']} min, personal {e['personal']} min")


if __name__ == "__main__":
    main()
