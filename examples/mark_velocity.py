"""Mark talking to Velocity v2: a small client using only Python's standard library.

Set two environment variables first (never put the key in the script):
    VELOCITY_BASE_URL   e.g. http://localhost:8200   (without /api/v1)
    VELOCITY_API_TOKEN  the key from Settings -> Integrations

Run it with no arguments to read today (changes nothing).
    python mark_velocity.py
    python mark_velocity.py add "Ethics essay outline fri 3h #fyp"
    python mark_velocity.py journal "Chapter 3 approved by Dr. Lim."
"""

import json
import os
import sys
import urllib.error
import urllib.request

BASE_URL = os.environ.get("VELOCITY_BASE_URL", "http://localhost:8200").rstrip("/")
TOKEN = os.environ.get("VELOCITY_API_TOKEN")


class VelocityError(RuntimeError):
    def __init__(self, status: int, error: dict):
        self.status = status
        self.code = error.get("code", "REQUEST_FAILED")
        self.fields = error.get("fields")
        super().__init__(f"Velocity said {status} {self.code}: {error.get('message', 'Request failed')}")


def call(method: str, path: str, body: dict | None = None) -> dict:
    headers = {"Accept": "application/json"}
    if TOKEN:
        headers["Authorization"] = f"Bearer {TOKEN}"
    data = None
    if body is not None:
        headers["Content-Type"] = "application/json"
        data = json.dumps(body).encode("utf-8")
    request = urllib.request.Request(f"{BASE_URL}/api/v1{path}", data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            return json.load(response)
    except urllib.error.HTTPError as error:
        try:
            detail = json.load(error).get("error", {})
        except (ValueError, OSError):
            detail = {"message": "Request failed"}
        raise VelocityError(error.code, detail) from None


def show_today() -> None:
    today = call("GET", "/today")["data"]
    print(f"Today is {today['date']} ({today['timezone']})")
    if today["tasks"] is not None:
        for task in today["tasks"]["top"]:
            print(f"  #{task['rank']} {task['title']} (score {task['score']})")
        if today["tasks"]["overdue"]:
            print(f"  Overdue: {len(today['tasks']['overdue'])}")
    if today["plan"] is not None:
        for event in today["plan"]["events"]:
            print(f"  {event['start']} {event['title']}")
    if today["habits"] is not None:
        left = [h["name"] for h in today["habits"] if h.get("group") != "done"]
        print("  Habits left:", ", ".join(left) or "none")


def main() -> None:
    if not TOKEN:
        sys.exit("Set VELOCITY_API_TOKEN first (Settings -> Integrations).")
    print("Velocity:", call("GET", "/health")["data"]["status"])
    me = call("GET", "/me")["data"]
    print(f"Key '{me['key']}' for {me['name']}: {me['accessText']}")

    if len(sys.argv) >= 3 and sys.argv[1] == "add":
        task = call("POST", "/tasks", {"text": sys.argv[2]})["data"]
        print(f"Added '{task['title']}' at #{task['rank']} with a score of {task['score']}")
    elif len(sys.argv) >= 3 and sys.argv[1] == "journal":
        entry = call("POST", "/journal", {"text": sys.argv[2]})["data"]
        print(f"Journal {entry['journalDate']} now has {len(entry['text'].splitlines())} lines")
    else:
        show_today()


if __name__ == "__main__":
    main()
