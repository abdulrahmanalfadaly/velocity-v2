import { Clock } from '@/lib/velocity';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { DatePick } from './date-pick';
import { copyText, nudgeText } from './follow-ups';
import { Icon } from './icon';
import { Select } from './select';

/** Tomorrow's date in the user's time zone ("2026-11-16"). */
const tomorrow = (clock: Clock) => clock.localInput(new Date(clock.now.getTime() + 864e5).toISOString()).slice(0, 10);

interface Reminder {
    id: number;
    taskId: number;
    kind: 'at' | 'before' | 'bug';
    title: string;
    remindAt: string;
    dueAt: string | null;
}

/** A birthday or another date coming round. */
interface DateReminder {
    id: number;
    personId: number;
    title: string;
    date: string;
    days: number;
    turns: number | null;
}

/** A follow-up whose day has come (Settings → Tasks → Follow-ups: "Remind me on the day"). */
interface FollowUpReminder {
    taskId: number;
    title: string;
    who: string | null;
    /** Days past its follow-up day (0 = today). */
    late: number;
}

const followWhen = (f: FollowUpReminder) =>
    [f.late > 0 ? (f.late === 1 ? '1 day late' : `${f.late} days late`) : 'Follow up today', f.who ? `waiting for ${f.who}` : null]
        .filter(Boolean)
        .join(' · ');

const when = (d: DateReminder) =>
    `${d.days === 0 ? 'Today' : d.days === 1 ? 'Tomorrow' : `In ${d.days} days`}${d.turns ? ` · turns ${d.turns}` : ''}`;
const EVERY = 30_000;
const keep = { preserveScroll: true, preserveState: true };

/** Can this browser show Windows notifications, and has the user said yes? */
export const notifyState = (): 'on' | 'off' | 'ask' | 'none' =>
    typeof Notification === 'undefined'
        ? 'none'
        : Notification.permission === 'granted'
          ? 'on'
          : Notification.permission === 'denied'
            ? 'off'
            : 'ask';

export const askToNotify = () => (typeof Notification !== 'undefined' ? Notification.requestPermission() : Promise.resolve('denied'));

/**
 * While Velocity is open it asks every 30 seconds (and whenever the window comes
 * back into view) for reminders that are due. Each one shows as a card here, and as
 * a Windows notification when that is allowed.
 */
export function Reminders({ clock, onOpen }: { clock: Clock; onOpen: (taskId: number) => void }) {
    const [shown, setShown] = useState<Reminder[]>([]);
    const [dates, setDates] = useState<DateReminder[]>([]);
    const [follow, setFollow] = useState<FollowUpReminder[]>([]);
    const [copied, setCopied] = useState<number | null>(null);
    const busy = useRef(false);
    // The latest clock and handler, without restarting the 30-second check whenever the page updates.
    const latest = useRef({ clock, onOpen });
    latest.current = { clock, onOpen };

    const check = useCallback(async () => {
        if (busy.current) return;
        busy.current = true;
        try {
            const res = await fetch(route('reminders.due'), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) return;
            const {
                reminders,
                dates: due = [],
                followUps = [],
            } = (await res.json()) as { reminders: Reminder[]; dates?: DateReminder[]; followUps?: FollowUpReminder[] };
            if (followUps.length) {
                setFollow((now) => [...now.filter((f) => !followUps.some((x) => x.taskId === f.taskId)), ...followUps]);
                if (notifyState() === 'on') {
                    followUps.forEach((f) => {
                        const n = new Notification(`Follow up: ${f.title}`, { body: followWhen(f), tag: `velocity-follow-${f.taskId}` });
                        n.onclick = () => {
                            window.focus();
                            latest.current.onOpen(f.taskId);
                            n.close();
                        };
                    });
                }
            }
            if (due.length) {
                setDates((now) => [...now.filter((d) => !due.some((x) => x.id === d.id)), ...due]);
                if (notifyState() === 'on') {
                    due.forEach((d) => {
                        const n = new Notification(d.title, { body: when(d), tag: `velocity-date-${d.id}` });
                        n.onclick = () => {
                            window.focus();
                            router.visit(`/people?person=${d.personId}`);
                            n.close();
                        };
                    });
                }
            }
            if (!reminders.length) return;
            setShown((now) => [...now.filter((r) => !reminders.some((x) => x.id === r.id)), ...reminders]);
            if (notifyState() === 'on') {
                const { clock, onOpen } = latest.current;
                reminders.forEach((r) => {
                    const n = new Notification(r.title, {
                        body: r.dueAt
                            ? `Due ${clock.date(r.dueAt, { weekday: 'short', day: 'numeric', month: 'short' })}, ${clock.time(r.dueAt)}`
                            : 'Reminder',
                        tag: `velocity-${r.taskId}`,
                    });
                    n.onclick = () => {
                        window.focus();
                        onOpen(r.taskId);
                        n.close();
                    };
                });
            }
        } catch {
            // Offline or the server is stopping: try again next time.
        } finally {
            busy.current = false;
        }
    }, []);

    useEffect(() => {
        check();
        const timer = setInterval(check, EVERY);
        const onVisible = () => !document.hidden && check();
        document.addEventListener('visibilitychange', onVisible);
        return () => {
            clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [check]);

    const drop = (id: number) => setShown((now) => now.filter((r) => r.id !== id));
    const dropDate = (id: number) => setDates((now) => now.filter((d) => d.id !== id));
    const dropFollow = (id: number) => setFollow((now) => now.filter((f) => f.taskId !== id));
    if (!shown.length && !dates.length && !follow.length) return null;

    return (
        <div className="reminders" role="region" aria-label="Reminders">
            {follow.map((f) => (
                <div className="reminder" key={`f${f.taskId}`} role="alert">
                    <span className="reminder-ic">
                        <Icon name="followUp" />
                    </span>
                    <div className="reminder-main">
                        <b>Follow up: {f.title}</b>
                        <span>{followWhen(f)}</span>
                        <div className="reminder-actions">
                            <button
                                className="btn btn-primary btn-sm"
                                onClick={() => {
                                    dropFollow(f.taskId);
                                    onOpen(f.taskId);
                                }}
                            >
                                Open
                            </button>
                            <button
                                className="btn btn-secondary btn-sm"
                                onClick={async () => {
                                    await copyText(nudgeText(f.title, f.who));
                                    setCopied(f.taskId);
                                    setTimeout(() => setCopied(null), 2000);
                                }}
                            >
                                <Icon name={copied === f.taskId ? 'check' : 'copy'} className="ic-sm" />
                                {copied === f.taskId ? 'Copied' : 'Copy message'}
                            </button>
                        </div>
                    </div>
                    <button className="icon-btn reminder-close" aria-label="Dismiss" title="Dismiss" onClick={() => dropFollow(f.taskId)}>
                        <Icon name="x" />
                    </button>
                </div>
            ))}
            {dates.map((d) => (
                <div className="reminder" key={`d${d.id}`} role="alert">
                    <span className="reminder-ic">
                        <Icon name="gift" />
                    </span>
                    <div className="reminder-main">
                        <b>{d.title}</b>
                        <span>{when(d)}</span>
                        <div className="reminder-actions">
                            <button
                                className="btn btn-primary btn-sm"
                                onClick={() => {
                                    dropDate(d.id);
                                    router.visit(`/people?person=${d.personId}`);
                                }}
                            >
                                Open
                            </button>
                            {(['1h', 'tomorrow'] as const).map((f) => (
                                <button
                                    key={f}
                                    className="btn btn-secondary btn-sm"
                                    onClick={() => {
                                        dropDate(d.id);
                                        router.post(route('person-dates.snooze', d.id), { for: f }, { preserveScroll: true, preserveState: true });
                                    }}
                                >
                                    {f === '1h' ? 'In an hour' : 'Tomorrow'}
                                </button>
                            ))}
                        </div>
                    </div>
                    <button className="icon-btn reminder-close" aria-label="Dismiss" title="Dismiss" onClick={() => dropDate(d.id)}>
                        <Icon name="x" />
                    </button>
                </div>
            ))}
            {shown.map((r) => (
                <div className="reminder" key={r.id} role="alert">
                    <span className="reminder-ic">
                        <Icon name="bell" />
                    </span>
                    <div className="reminder-main">
                        <b>{r.title}</b>
                        <span>
                            {r.dueAt
                                ? `Due ${clock.dayDiff(r.dueAt) === 0 ? 'today' : clock.date(r.dueAt, { weekday: 'short', day: 'numeric', month: 'short' })}, ${clock.time(r.dueAt)}`
                                : `Reminder for ${clock.time(r.remindAt)}`}
                        </span>
                        <div className="reminder-actions">
                            <button
                                className="btn btn-primary btn-sm"
                                onClick={() => {
                                    drop(r.id);
                                    onOpen(r.taskId);
                                }}
                            >
                                Open
                            </button>
                            <Select
                                className="btn btn-secondary btn-sm"
                                label="Snooze"
                                value=""
                                placeholder="Snooze"
                                search={false}
                                onChange={(v) => {
                                    drop(r.id);
                                    router.post(
                                        route('reminders.snooze', r.id),
                                        v === 'tomorrow' ? { until: `${tomorrow(clock)}T09:00` } : { minutes: Number(v) },
                                        keep,
                                    );
                                }}
                                options={[
                                    { value: '10', label: '10 min' },
                                    { value: '30', label: '30 min' },
                                    { value: '60', label: '1 hour' },
                                    { value: '180', label: '3 hours' },
                                    { value: 'tomorrow', label: 'Tomorrow, 9:00 am' },
                                ]}
                            />
                            <DatePick
                                mode="datetime"
                                className="btn btn-secondary btn-sm"
                                label="Snooze until"
                                placeholder="Pick a time…"
                                defaultTime="09:00"
                                value=""
                                onChange={(v) => {
                                    if (!v) return;
                                    drop(r.id);
                                    router.post(route('reminders.snooze', r.id), { until: v }, keep);
                                }}
                            />
                            <button
                                className="btn btn-ghost btn-sm"
                                onClick={() => {
                                    drop(r.id);
                                    router.post(route('tasks.complete', r.taskId), {}, keep);
                                }}
                            >
                                <Icon name="check" className="ic-sm" />
                                Done
                            </button>
                            {r.kind === 'bug' && (
                                <button
                                    className="btn btn-ghost btn-sm"
                                    onClick={() => {
                                        drop(r.id);
                                        router.delete(route('reminders.destroy', r.id), keep);
                                    }}
                                >
                                    Stop bugging
                                </button>
                            )}
                        </div>
                    </div>
                    <button className="icon-btn reminder-close" aria-label="Dismiss" title="Dismiss" onClick={() => drop(r.id)}>
                        <Icon name="x" />
                    </button>
                </div>
            ))}
        </div>
    );
}
