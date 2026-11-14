"""Mark: people.

    python mark_people.py                                    # who needs attention (reads only)
    python mark_people.py add "Sara Ahmad" --who Classmate
    python mark_people.py talked 5 call --note "Caught up about the project"
    python mark_people.py who 5 "Lab partner"
"""

import argparse

from mark_velocity import call


def main() -> None:
    p = argparse.ArgumentParser()
    sub = p.add_subparsers(dest="cmd")
    sub.add_parser("attention")
    ad = sub.add_parser("add")
    ad.add_argument("name")
    ad.add_argument("--who")
    t = sub.add_parser("talked")
    t.add_argument("id", type=int)
    t.add_argument("kind", choices=["message", "call", "met", "other"])
    t.add_argument("--note")
    w = sub.add_parser("who")
    w.add_argument("id", type=int)
    w.add_argument("relationship")
    a = p.parse_args()

    if a.cmd in (None, "attention"):
        for person in call("GET", "/people?view=attention")["data"]:
            print(f"{person['name']}: {person.get('next') or ''}")
    elif a.cmd == "add":
        print("Added", call("POST", "/people", {"name": a.name, "relationship": a.who})["data"]["name"])
    elif a.cmd == "talked":
        call("POST", f"/people/{a.id}/catch-ups", {"kind": a.kind, "note": a.note})
        print("Logged")
    elif a.cmd == "who":
        call("PATCH", f"/people/{a.id}", {"relationship": a.relationship})
        print("Saved")


if __name__ == "__main__":
    main()
