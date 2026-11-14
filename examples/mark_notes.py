"""Mark: notes and the journal.

    python mark_notes.py                                     # the latest notes (reads only)
    python mark_notes.py read 12
    python mark_notes.py add "Meeting with Dr. Lim" "Tighten the related work."
    python mark_notes.py append 12 "One more thing."
    python mark_notes.py journal "Chapter 3 approved."
    python mark_notes.py link 12 goal 3                      # note | goal | habit
"""

import argparse

from mark_velocity import call


def main() -> None:
    p = argparse.ArgumentParser()
    sub = p.add_subparsers(dest="cmd")
    sub.add_parser("list").add_argument("--q")
    sub.add_parser("read").add_argument("id", type=int)
    ad = sub.add_parser("add")
    ad.add_argument("title")
    ad.add_argument("text")
    ap = sub.add_parser("append")
    ap.add_argument("id", type=int)
    ap.add_argument("text")
    sub.add_parser("journal").add_argument("text")
    ln = sub.add_parser("link")
    ln.add_argument("id", type=int)
    ln.add_argument("type", choices=["note", "goal", "habit"])
    ln.add_argument("target", type=int)
    a = p.parse_args()

    if a.cmd in (None, "list"):
        q = f"&q={a.q}" if getattr(a, "q", None) else ""
        for n in call("GET", f"/notes?limit=10{q}")["data"]:
            print(f"{n['id']}: {n['title']} — {n['preview'][:60]}")
    elif a.cmd == "read":
        n = call("GET", f"/notes/{a.id}")["data"]
        print(n["title"], "\n", n["text"])
    elif a.cmd == "add":
        print("Added", call("POST", "/notes", {"title": a.title, "text": a.text})["data"]["id"])
    elif a.cmd == "append":
        call("PATCH", f"/notes/{a.id}", {"append": a.text})
        print("Added to the note")
    elif a.cmd == "journal":
        call("POST", "/journal", {"text": a.text})
        print("Added to today's entry")
    elif a.cmd == "link":
        call("POST", f"/notes/{a.id}/links", {"type": a.type, "id": a.target})
        print("Linked")


if __name__ == "__main__":
    main()
