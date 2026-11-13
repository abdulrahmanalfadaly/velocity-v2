import { CALENDARS, type CalendarKey, EventDialog, type EventForm } from '@/components/velocity/event-dialog';
import { Icon } from '@/components/velocity/icon';
import VelocityShell from '@/layouts/velocity-shell';
import { Clock } from '@/lib/velocity';
import type { VelocityShared } from '@/types/velocity';
import { router, usePage } from '@inertiajs/react';
import { type MouseEvent, useEffect, useMemo, useRef, useState } from 'react';

interface Occurrence {
    id: number;
    key: string;
    date: string;
    start: string;
    end: string;
    title: string;
    calendar: CalendarKey;
    location: string | null;
    allDay: boolean;
    repeats: boolean;
    form: EventForm;
}
interface Props extends VelocityShared {
    view: 'day' | 'week';
    date: string;
    today: string;
    days: string[];
    events: Occurrence[];
    busy: string[];
    calendars: Record<CalendarKey, number>;
    dueTasks: { id: number; title: string; dueAt: string }[];
}

const HOUR = 48; // px for one hour in the grid
const ICON: Record<CalendarKey, string> = { class: 'cap', work: 'briefcase', personal: 'heart' };
const LABEL: Record<CalendarKey, string> = { class: 'Class', work: 'Work', personal: 'Personal' };

/** "2026-11-13" ± n days. */
const shift = (day: string, n: number) => {
    const d = new Date(`${day}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
};
const at = (day: string) => `${day}T12:00:00Z`; // a moment on that date, for formatting
const hhmm = (min: number) => `${String(Math.floor(min / 60) % 24).padStart(2, '0')}:${String(min % 60).padStart(2, '0')}`;

/** A local date and minute of the day for an ISO moment. */
function local(clock: Clock, iso: string) {
    const v = clock.localInput(iso);
    return { date: v.slice(0, 10), min: Number(v.slice(11, 13)) * 60 + Number(v.slice(14, 16)) };
}

interface Piece {
    ev: Occurrence;
    top: number;
    bottom: number;
    lane: number;
    lanes: number;
}

/** The parts of the timed events that fall on one day, side by side where they overlap. */
function layoutDay(events: Occurrence[], day: string, clock: Clock): Piece[] {
    const pieces: Piece[] = [];
    events
        .filter((e) => !e.allDay)
        .forEach((ev) => {
            const s = local(clock, ev.start);
            const e = local(clock, ev.end);
            if (s.date > day || e.date < day || (e.date === day && e.min === 0)) return;
            const top = s.date === day ? s.min : 0;
            const bottom = e.date === day ? e.min : 1440;
            if (bottom > top) pieces.push({ ev, top, bottom, lane: 0, lanes: 1 });
        });
    pieces.sort((a, b) => a.top - b.top || b.bottom - a.bottom);
    // Groups of events that overlap share the width of the column.
    let group: Piece[] = [];
    let groupEnd = -1;
    const close = () => {
        const lanes = Math.max(1, ...group.map((p) => p.lane + 1));
        group.forEach((p) => (p.lanes = lanes));
    };
    pieces.forEach((p) => {
        if (p.top >= groupEnd && group.length) {
            close();
            group = [];
        }
        const ends: number[] = [];
        group.forEach((q) => (ends[q.lane] = Math.max(ends[q.lane] ?? 0, q.bottom)));
        let lane = 0;
        while (ends[lane] !== undefined && ends[lane] > p.top) lane += 1;
        p.lane = lane;
        group.push(p);
        groupEnd = Math.max(groupEnd, p.bottom);
    });
    if (group.length) close();
    return pieces;
}

const blank = (date: string, start = 9 * 60): EventForm => ({
    title: '',
    calendar: 'class',
    location: null,
    notes: null,
    all_day: false,
    date,
    end_date: null,
    start_time: hhmm(start),
    end_time: hhmm(Math.min(start + 60, 23 * 60 + 59)),
    repeat_weekly: false,
    repeat_until: null,
});

/** The Planner: a day or a week of classes and events. */
export default function Planner() {
    const { props } = usePage<Props>();
    const clock = Clock.from(props.clock);
    const [dialog, setDialog] = useState<null | { initial: EventForm; id?: number; occurrence?: string }>(null);
    const [hidden, setHidden] = useState<CalendarKey[]>(() => {
        try {
            return JSON.parse(localStorage.getItem('velocity.hiddenCalendars') ?? '[]');
        } catch {
            return [];
        }
    });
    const toggleCalendar = (k: CalendarKey) => {
        const next = hidden.includes(k) ? hidden.filter((x) => x !== k) : [...hidden, k];
        setHidden(next);
        localStorage.setItem('velocity.hiddenCalendars', JSON.stringify(next));
    };
    const events = props.events.filter((e) => !hidden.includes(e.calendar));
    const go = (date: string, view = props.view) => router.get('/planner', { date, view }, { preserveScroll: true });
    const step = props.view === 'week' ? 7 : 1;

    // Phones show one day at a time.
    useEffect(() => {
        if (props.view === 'week' && matchMedia('(max-width: 767px)').matches)
            router.get('/planner', { date: props.date, view: 'day' }, { replace: true });
    }, [props.view, props.date]);

    // Start the grid at 7 am (or just before the first thing on), once.
    const scroller = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const first = Math.min(7 * 60, ...events.filter((e) => !e.allDay).map((e) => local(clock, e.start).min));
        scroller.current?.scrollTo({ top: Math.max(0, (first / 60) * HOUR - 12) });
    }, [props.date, props.view]); // eslint-disable-line react-hooks/exhaustive-deps

    // The "now" line, moved every minute.
    const [nowMin, setNowMin] = useState(() => local(clock, clock.now.toISOString()).min);
    useEffect(() => {
        const t = setInterval(() => setNowMin((m) => m + 1), 60_000);
        return () => clearInterval(t);
    }, []);

    const range =
        props.view === 'week'
            ? `${clock.date(at(props.days[0]), { day: 'numeric', month: 'short' })} – ${clock.date(at(props.days[6]), { day: 'numeric', month: 'short', year: 'numeric' })}`
            : clock.date(at(props.date), { weekday: 'long', day: 'numeric', month: 'long' });
    const allDay = events.filter((e) => e.allDay);
    const pieces = useMemo(() => Object.fromEntries(props.days.map((d) => [d, layoutDay(events, d, clock)])), [props.events, hidden, props.days]); // eslint-disable-line react-hooks/exhaustive-deps

    const newAt = (day: string, e: MouseEvent<HTMLDivElement>) => {
        if (e.target !== e.currentTarget) return;
        const y = e.clientY - e.currentTarget.getBoundingClientRect().top;
        const min = Math.max(0, Math.min(23 * 60, Math.floor((y / HOUR) * 2) * 30));
        setDialog({ initial: blank(day, min) });
    };
    const openEvent = (ev: Occurrence) => setDialog({ initial: ev.form, id: ev.id, occurrence: ev.repeats ? ev.date : undefined });

    const menu = () => (
        <PlannerMenu
            props={props}
            clock={clock}
            hidden={hidden}
            onToggle={toggleCalendar}
            onNew={() => setDialog({ initial: blank(props.date) })}
            go={go}
        />
    );

    return (
        <VelocityShell title="Planner" section="planner" active={props.view} menu={menu}>
            <div className="main-inner planner">
                <header className="page-head planner-head">
                    <div>
                        <p className="eyebrow is-section">Planner</p>
                        <h1 className="h1">{range}</h1>
                    </div>
                    <div className="page-actions">
                        <div className="planner-nav">
                            <button
                                className="icon-btn"
                                aria-label={props.view === 'week' ? 'Previous week' : 'Previous day'}
                                onClick={() => go(shift(props.date, -step))}
                            >
                                <Icon name="chevL" />
                            </button>
                            <button className="btn btn-secondary btn-sm" onClick={() => go(props.today)} disabled={props.days.includes(props.today)}>
                                Today
                            </button>
                            <button
                                className="icon-btn"
                                aria-label={props.view === 'week' ? 'Next week' : 'Next day'}
                                onClick={() => go(shift(props.date, step))}
                            >
                                <Icon name="chevR" />
                            </button>
                        </div>
                        <div className="layout-seg planner-views" role="radiogroup" aria-label="Show">
                            {(['day', 'week'] as const).map((v) => (
                                <button key={v} role="radio" aria-checked={props.view === v} onClick={() => go(props.date, v)}>
                                    {v === 'day' ? 'Day' : 'Week'}
                                </button>
                            ))}
                        </div>
                        <button className="btn btn-primary" onClick={() => setDialog({ initial: blank(props.date) })}>
                            <Icon name="plus" />
                            <span className="btn-label">New event</span>
                        </button>
                    </div>
                </header>

                <div className={`pgrid-wrap is-${props.view}`} style={{ ['--days' as string]: props.days.length }}>
                    <div className="pg-head">
                        <span className="pg-gutter" />
                        {props.days.map((d) => (
                            <button
                                key={d}
                                className={`pg-day${d === props.today ? ' is-today' : ''}`}
                                onClick={() => props.view === 'week' && go(d, 'day')}
                                aria-label={clock.date(at(d), { weekday: 'long', day: 'numeric', month: 'long' })}
                            >
                                <span>{clock.weekday(at(d))}</span>
                                <b>{clock.date(at(d), { day: 'numeric' })}</b>
                            </button>
                        ))}
                    </div>
                    {allDay.length > 0 && (
                        <div className="pg-allday">
                            <span className="pg-gutter">all day</span>
                            {props.days.map((d) => (
                                <div key={d} className="pg-allday-col">
                                    {allDay
                                        .filter((e) => {
                                            const s = local(clock, e.start).date;
                                            const end = shift(local(clock, e.end).date, -1);
                                            return s <= d && d <= end;
                                        })
                                        .map((e) => (
                                            <button key={e.key} className={`pev is-${e.calendar}`} onClick={() => openEvent(e)}>
                                                <Icon name={ICON[e.calendar]} className="ic-xs" />
                                                <span className="pev-title">{e.title}</span>
                                            </button>
                                        ))}
                                </div>
                            ))}
                        </div>
                    )}
                    <div className="pg-scroll" ref={scroller}>
                        <div className="pg-body" style={{ height: 24 * HOUR }}>
                            <div className="pg-gutter pg-hours" aria-hidden="true">
                                {Array.from({ length: 24 }, (_, h) => (
                                    <span key={h} style={{ top: h * HOUR }}>
                                        {h === 0 ? '' : clock.time(new Date(Date.UTC(2026, 0, 1, h))).replace(':00', '')}
                                    </span>
                                ))}
                            </div>
                            {props.days.map((d) => (
                                <div
                                    key={d}
                                    className={`pg-col${d === props.today ? ' is-today' : ''}`}
                                    onClick={(e) => newAt(d, e)}
                                    role="presentation"
                                    title="Click to add an event"
                                >
                                    {pieces[d].map((p) => (
                                        <button
                                            key={p.ev.key}
                                            className={`pev is-${p.ev.calendar}${p.bottom - p.top < 45 ? ' is-short' : ''}`}
                                            style={{
                                                top: (p.top / 60) * HOUR,
                                                height: Math.max(20, ((p.bottom - p.top) / 60) * HOUR - 2),
                                                left: `calc(${(p.lane / p.lanes) * 100}% + 2px)`,
                                                width: `calc(${100 / p.lanes}% - 4px)`,
                                            }}
                                            onClick={() => openEvent(p.ev)}
                                            aria-label={`${LABEL[p.ev.calendar]}: ${p.ev.title}, ${hhmm(p.top)} to ${hhmm(p.bottom)}`}
                                        >
                                            <span className="pev-title">
                                                <Icon name={ICON[p.ev.calendar]} className="ic-xs" />
                                                {p.ev.title}
                                            </span>
                                            <span className="pev-sub">
                                                {clock.time(p.ev.start)}–{clock.time(p.ev.end)}
                                                {p.ev.location ? ` · ${p.ev.location}` : ''}
                                            </span>
                                        </button>
                                    ))}
                                    {props.dueTasks
                                        .filter((t) => local(clock, t.dueAt).date === d)
                                        .map((t) => (
                                            <a
                                                key={t.id}
                                                className="pg-due"
                                                href={`/tasks?task=${t.id}`}
                                                style={{ top: (local(clock, t.dueAt).min / 60) * HOUR - 9 }}
                                                title={`Due: ${t.title}`}
                                            >
                                                <Icon name="flag" className="ic-xs" />
                                                {t.title}
                                            </a>
                                        ))}
                                    {d === props.today && <span className="pg-now" style={{ top: (nowMin / 60) * HOUR }} aria-hidden="true" />}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
            {dialog && (
                <EventDialog initial={dialog.initial} eventId={dialog.id} occurrenceDate={dialog.occurrence} onClose={() => setDialog(null)} />
            )}
        </VelocityShell>
    );
}

/** The Planner's side menu: New event, a month calendar, Day / Week, and the calendars to show. */
function PlannerMenu({
    props,
    clock,
    hidden,
    onToggle,
    onNew,
    go,
}: {
    props: Props;
    clock: Clock;
    hidden: CalendarKey[];
    onToggle: (k: CalendarKey) => void;
    onNew: () => void;
    go: (date: string, view?: 'day' | 'week') => void;
}) {
    const first = `${props.date.slice(0, 8)}01`;
    const monthStart = new Date(`${first}T12:00:00Z`);
    const lead = (monthStart.getUTCDay() + 6) % 7; // Monday first
    const cells = Array.from({ length: 42 }, (_, i) => shift(first, i - lead));
    const month = props.date.slice(0, 7);
    const busy = new Set(props.busy);
    const inView = new Set(props.days);
    return (
        <>
            <div className="side-head">
                <span className="side-title">Planner</span>
                <button className="icon-btn" onClick={onNew} aria-label="New event" title="New event">
                    <Icon name="plus" />
                </button>
            </div>
            <div className="mcal" aria-label={clock.date(at(first), { month: 'long', year: 'numeric' })}>
                <div className="mcal-head">
                    <b>{clock.date(at(first), { month: 'long', year: 'numeric' })}</b>
                    <span>
                        <button className="icon-btn" aria-label="Previous month" onClick={() => go(shift(first, -1).slice(0, 8) + '01')}>
                            <Icon name="chevL" className="ic-sm" />
                        </button>
                        <button className="icon-btn" aria-label="Next month" onClick={() => go(shift(first, 32).slice(0, 8) + '01')}>
                            <Icon name="chevR" className="ic-sm" />
                        </button>
                    </span>
                </div>
                <div className="mcal-grid">
                    {['M', 'T', 'W', 'T', 'F', 'S', 'S'].map((d, i) => (
                        <span key={i} className="mcal-dow">
                            {d}
                        </span>
                    ))}
                    {cells.map((d) => (
                        <button
                            key={d}
                            className={`mcal-day${d.slice(0, 7) !== month ? ' is-out' : ''}${d === props.today ? ' is-today' : ''}${busy.has(d) ? ' has-due' : ''}${inView.has(d) ? ' is-shown' : ''}`}
                            onClick={() => go(d)}
                            aria-label={clock.date(at(d), { weekday: 'long', day: 'numeric', month: 'long' })}
                        >
                            {Number(d.slice(8))}
                        </button>
                    ))}
                </div>
            </div>
            <nav className="nav" aria-label="Planner">
                {(['day', 'week'] as const).map((v) => (
                    <button
                        key={v}
                        className={`nav-item${props.view === v ? ' is-active' : ''}`}
                        aria-current={props.view === v ? 'page' : undefined}
                        onClick={() => go(props.date, v)}
                    >
                        <Icon name={v === 'day' ? 'planner' : 'columns'} />
                        <span className="label">{v === 'day' ? 'Day' : 'Week'}</span>
                    </button>
                ))}
                <div className="nav-label">Calendars</div>
                {CALENDARS.map(([k, label, icon]) => (
                    <button
                        key={k}
                        className={`nav-item${hidden.includes(k) ? ' is-off' : ''}`}
                        aria-pressed={!hidden.includes(k)}
                        onClick={() => onToggle(k)}
                    >
                        <Icon name={icon} />
                        <span className="label">{label}</span>
                        <span className="count">{hidden.includes(k) ? 'hidden' : props.calendars[k] || ''}</span>
                    </button>
                ))}
            </nav>
        </>
    );
}
