"""Mark: what today looks like (reads only).

    python mark_today.py            # the top tasks, today's plan, habits due, next actions
"""

from mark_velocity import call


def main() -> None:
    today = call("GET", "/today")["data"]
    print(f"Today, {today['date']} ({today['timezone']})")
    if today["tasks"] is not None:
        for task in today["tasks"]["top"]:
            print(f"  #{task['rank']} {task['title']}")
    if today["plan"] is not None:
        for event in today["plan"]["events"]:
            print(f"  {event['start'][11:16]} {event['title']}")
    if today["habits"]:
        print("Habits due:", ", ".join(h["name"] for h in today["habits"]))

    nxt = call("GET", "/tasks/next")["data"]
    print("Next actions:")
    for row in nxt["rows"]:
        where = row["project"]["name"] if row["project"] else "No project"
        print(f"  {where}: #{row['task']['rank']} {row['task']['title']}")
    for idle in nxt["idle"]:
        print(f"  {idle['name']}: {idle['why']}")


if __name__ == "__main__":
    main()
