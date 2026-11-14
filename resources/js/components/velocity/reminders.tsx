import { Clock } from '@/lib/velocity';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Icon } from './icon';

interface Reminder {
    id: number;
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
            const { reminders, dates: due = [] } = (await res.json()) as { reminders: Reminder[]; dates?: DateReminder[] };
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
                        tag: `velocity-${r.id}`,
                    });
                    n.onclick = () => {
                        window.focus();
                        onOpen(r.id);
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
    if (!shown.length && !dates.length) return null;

    return (
        <div className="reminders" role="region" aria-label="Reminders">
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
                                    onOpen(r.id);
                                }}
                            >
                                Open
                            </button>
                            <button
                                className="btn btn-secondary btn-sm"
                                onClick={() => {
                                    drop(r.id);
                                    router.post(route('tasks.snooze', r.id), { minutes: 10 }, keep);
                                }}
                            >
                                10 min
                            </button>
                            <button
                                className="btn btn-secondary btn-sm"
                                onClick={() => {
                                    drop(r.id);
                                    router.post(route('tasks.snooze', r.id), { minutes: 60 }, keep);
                                }}
                            >
                                1 hour
                            </button>
                            <button
                                className="btn btn-ghost btn-sm"
                                onClick={() => {
                                    drop(r.id);
                                    router.post(route('tasks.complete', r.id), {}, keep);
                                }}
                            >
                                <Icon name="check" className="ic-sm" />
                                Done
                            </button>
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
