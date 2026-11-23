import { Clock } from '@/lib/velocity';
import { Link } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import { Pop } from './habit-parts';
import { Icon } from './icon';
import type { PlanBlock } from './planner-parts';

type CalendarKey = 'class' | 'work' | 'personal';
const ICON: Record<CalendarKey, string> = { class: 'cap', work: 'briefcase', personal: 'heart' };

/** What a month box needs of a class or event. */
export interface MonthEvent {
    key: string;
    start: string;
    end: string;
    title: string;
    calendar: CalendarKey;
    allDay: boolean;
    cancelled?: boolean;
}

/** An all-day marker: a project or milestone due, a goal's target date, a habit with no set time, a catch-up. */
export interface Marker {
    key: string;
    date: string;
    kind: 'project' | 'milestone' | 'goal' | 'habit' | 'catchup';
    title: string;
    href: string;
    done?: boolean;
}
export const MARKER_ICON: Record<Marker['kind'], string> = {
    project: 'folder',
    milestone: 'flag',
    goal: 'target',
    habit: 'habits',
    catchup: 'message',
};

interface Entry {
    key: string;
    sort: number; // minute of the day; things with no time first
    node: ReactNode;
}

const MAX = 3; // things shown in one day box; the rest behind "+2 more" next to the day number (opens the whole day)
const POP_W = 320;
const shift = (day: string, n: number) => {
    const d = new Date(`${day}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
};
const at = (day: string) => `${day}T12:00:00Z`;
/** "9 am", "9:30 am": the short time the Planner's hour lines use. */
const short = (min: number) => {
    const h = Math.floor(min / 60) % 24;
    const m = min % 60;
    return `${((h + 11) % 12) + 1}${m ? `:${String(m).padStart(2, '0')}` : ''} ${h < 12 ? 'am' : 'pm'}`;
};

/** A month of day boxes: classes and events, booked time, deadlines, birthdays and follow-ups; a day opens it. */
export function MonthGrid<E extends MonthEvent>({
    days,
    month,
    today,
    events,
    blocks,
    dueTasks,
    personDates,
    followUps,
    markers = [],
    clock,
    onEvent,
    onDay,
}: {
    days: string[];
    month: string; // "2026-11"
    today: string;
    events: E[];
    blocks: PlanBlock[];
    dueTasks: { id: number; title: string; dueAt: string }[];
    personDates: { key: string; date: string; title: string; kind: 'birthday' | 'anniversary' | 'other'; personId: number }[];
    followUps: { id: number; title: string; date: string }[];
    markers?: Marker[];
    clock: Clock;
    onEvent: (e: E) => void;
    onDay: (day: string) => void;
}) {
    const local = (iso: string) => {
        const v = clock.localInput(iso);
        return { date: v.slice(0, 10), min: Number(v.slice(11, 13)) * 60 + Number(v.slice(14, 16)) };
    };
    const byDay: Record<string, Entry[]> = Object.fromEntries(days.map((d) => [d, []]));
    // "+2 more": the whole day in a pop-up, beside its box (full titles, times; Open the day for the day view).
    const [pop, setPop] = useState<null | { d: string; x: number; y: number }>(null);
    const add = (day: string, e: Entry) => byDay[day]?.push(e);
    const now = clock.now.getTime();

    events.forEach((ev) => {
        const s = local(ev.start);
        const node = (time: string | null) => (
            <button
                className={`mg-item is-event is-${ev.calendar}${ev.cancelled ? ' is-cancelled' : ''}`}
                onClick={() => onEvent(ev)}
                title={ev.cancelled ? `${ev.title} (cancelled)` : ev.title}
            >
                <Icon name={ICON[ev.calendar]} className="ic-xs" />
                {time && <span className="mg-time">{time}</span>}
                <span className="mg-t">{ev.title}</span>
            </button>
        );
        if (ev.allDay) {
            const last = shift(local(ev.end).date, -1);
            for (let d = s.date; d <= last; d = shift(d, 1)) add(d, { key: `${ev.key}@${d}`, sort: -2, node: node(null) });
        } else add(s.date, { key: ev.key, sort: s.min, node: node(short(s.min)) });
    });
    blocks.forEach((bl) => {
        const s = local(bl.start);
        add(s.date, {
            key: `b${bl.id}`,
            sort: s.min,
            node: (
                <button
                    className={`mg-item is-block is-${bl.kind}${bl.blocked ? ' is-blocked' : ''}`}
                    onClick={() => onDay(s.date)}
                    title={bl.blocked ? `${bl.title} · ${bl.blocked.why}` : bl.title}
                >
                    {bl.kind !== 'task' && <Icon name={bl.kind === 'project' ? 'folder' : 'ban'} className="ic-xs" />}
                    <span className="mg-time">{short(s.min)}</span>
                    {bl.rank && <span className="mg-rank">#{bl.rank}</span>}
                    <span className="mg-t">{bl.title}</span>
                </button>
            ),
        });
    });
    dueTasks.forEach((t) => {
        const s = local(t.dueAt);
        add(s.date, {
            key: `d${t.id}`,
            sort: s.min,
            node: (
                <Link
                    className={`mg-item is-due${Date.parse(t.dueAt) < now ? ' is-late' : ''}`}
                    href={`/tasks?task=${t.id}`}
                    title={`Due: ${t.title}`}
                >
                    <Icon name="flag" className="ic-xs" />
                    <span className="mg-t">{t.title}</span>
                </Link>
            ),
        });
    });
    personDates.forEach((pd) =>
        add(pd.date, {
            key: pd.key,
            sort: -1,
            node: (
                <Link className="mg-item is-date" href={`/people?person=${pd.personId}`} title={pd.title}>
                    <Icon name={pd.kind === 'birthday' ? 'gift' : pd.kind === 'anniversary' ? 'heart' : 'calendar'} className="ic-xs" />
                    <span className="mg-t">{pd.title}</span>
                </Link>
            ),
        }),
    );
    followUps.forEach((f) =>
        add(f.date, {
            key: `f${f.id}`,
            sort: -1,
            node: (
                <Link className="mg-item is-date" href={`/tasks/waiting?task=${f.id}`} title={`Follow up: ${f.title}`}>
                    <Icon name="followUp" className="ic-xs" />
                    <span className="mg-t">{f.title}</span>
                </Link>
            ),
        }),
    );
    markers.forEach((m) =>
        add(m.date, {
            key: m.key,
            sort: -1,
            node: (
                <Link className={`mg-item is-date is-${m.kind}`} href={m.href} title={m.title}>
                    <Icon name={MARKER_ICON[m.kind]} className="ic-xs" />
                    <span className="mg-t">{m.title}</span>
                </Link>
            ),
        }),
    );

    return (
        <div className="mgrid" role="grid" aria-label={clock.date(at(`${month}-01`), { month: 'long', year: 'numeric' })}>
            <div className="mg-head" role="row">
                {days.slice(0, 7).map((d) => (
                    <span key={d} role="columnheader">
                        {clock.weekday(at(d))}
                    </span>
                ))}
            </div>
            <div className="mg-body" style={{ ['--weeks' as string]: days.length / 7 }}>
                {days.map((d) => {
                    const list = byDay[d].sort((a, b) => a.sort - b.sort);
                    const shown = list.slice(0, MAX);
                    const label = clock.date(at(d), { weekday: 'long', day: 'numeric', month: 'long' });
                    return (
                        <div
                            key={d}
                            role="gridcell"
                            className={`mg-day${d.slice(0, 7) !== month ? ' is-out' : ''}${d === today ? ' is-today' : ''}`}
                            onClick={(e) => e.target === e.currentTarget && onDay(d)}
                        >
                            <div className="mg-top">
                                <button
                                    className="mg-num"
                                    onClick={() => onDay(d)}
                                    aria-label={`${label}${list.length ? `, ${list.length} on` : ''}`}
                                >
                                    {Number(d.slice(8))}
                                </button>
                                {list.length > shown.length && (
                                    <button
                                        className={`mg-more pop-anchor${pop?.d === d ? ' is-open' : ''}`}
                                        aria-haspopup="dialog"
                                        aria-expanded={pop?.d === d}
                                        title="See the whole day"
                                        onClick={(e) => {
                                            if (pop?.d === d) return setPop(null);
                                            const box = e.currentTarget.closest('.mg-day')!.getBoundingClientRect();
                                            const tall = Math.min(470, 116 + list.length * 34);
                                            // Beside the box when there's room on its right, else on its left; never off the screen.
                                            const x = box.right + 8 + POP_W < window.innerWidth ? box.right + 8 : box.left - POP_W - 8;
                                            setPop({ d, x: Math.max(8, x), y: Math.max(8, Math.min(box.top, window.innerHeight - tall - 8)) });
                                        }}
                                    >
                                        +{list.length - shown.length} more
                                    </button>
                                )}
                            </div>
                            {shown.length > 0 && (
                                <ul className="mg-items">
                                    {shown.map((en) => (
                                        <li key={en.key}>{en.node}</li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    );
                })}
            </div>
            {pop &&
                (() => {
                    const list = byDay[pop.d] ?? [];
                    return (
                        <Pop
                            x={pop.x}
                            y={pop.y}
                            label={clock.date(at(pop.d), { weekday: 'long', day: 'numeric', month: 'long' })}
                            onClose={() => setPop(null)}
                            width={POP_W}
                        >
                            <div className="bmenu-head">
                                <b>{clock.date(at(pop.d), { weekday: 'long', day: 'numeric', month: 'long' })}</b>
                                <span>{list.length} on this day</span>
                            </div>
                            {/* Opening one of them closes this first (an event's dialog opens over the page). */}
                            <ul className="mg-pop-list" onClick={() => setPop(null)}>
                                {list.map((en) => (
                                    <li key={en.key}>{en.node}</li>
                                ))}
                            </ul>
                            <div className="bmenu-sep" />
                            <button
                                type="button"
                                className="bmenu-item"
                                onClick={() => {
                                    setPop(null);
                                    onDay(pop.d);
                                }}
                            >
                                <Icon name="calendar" />
                                <span className="bmenu-grow">Open the day</span>
                            </button>
                        </Pop>
                    );
                })()}
        </div>
    );
}
