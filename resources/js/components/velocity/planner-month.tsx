import { Clock } from '@/lib/velocity';
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
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
}

interface Entry {
    key: string;
    sort: number; // minute of the day; things with no time first
    node: ReactNode;
}

const MAX = 3; // lines in one day box before "+2 more"
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
    clock: Clock;
    onEvent: (e: E) => void;
    onDay: (day: string) => void;
}) {
    const local = (iso: string) => {
        const v = clock.localInput(iso);
        return { date: v.slice(0, 10), min: Number(v.slice(11, 13)) * 60 + Number(v.slice(14, 16)) };
    };
    const byDay: Record<string, Entry[]> = Object.fromEntries(days.map((d) => [d, []]));
    const add = (day: string, e: Entry) => byDay[day]?.push(e);
    const now = clock.now.getTime();

    events.forEach((ev) => {
        const s = local(ev.start);
        const node = (time: string | null) => (
            <button className={`mg-item is-event is-${ev.calendar}`} onClick={() => onEvent(ev)} title={ev.title}>
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
                    const shown = list.length > MAX ? list.slice(0, MAX - 1) : list;
                    const label = clock.date(at(d), { weekday: 'long', day: 'numeric', month: 'long' });
                    return (
                        <div
                            key={d}
                            role="gridcell"
                            className={`mg-day${d.slice(0, 7) !== month ? ' is-out' : ''}${d === today ? ' is-today' : ''}`}
                            onClick={(e) => e.target === e.currentTarget && onDay(d)}
                        >
                            <button className="mg-num" onClick={() => onDay(d)} aria-label={`${label}${list.length ? `, ${list.length} on` : ''}`}>
                                {Number(d.slice(8))}
                            </button>
                            {shown.length > 0 && (
                                <ul className="mg-items">
                                    {shown.map((en) => (
                                        <li key={en.key}>{en.node}</li>
                                    ))}
                                </ul>
                            )}
                            {list.length > shown.length && (
                                <button className="mg-more" onClick={() => onDay(d)}>
                                    +{list.length - shown.length} more
                                </button>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
