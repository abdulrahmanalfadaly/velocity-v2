import { CALENDARS, type CalendarKey, EventDialog, type EventForm } from '@/components/velocity/event-dialog';
import { Icon } from '@/components/velocity/icon';
import { BlockMenu, type Hours, HoursDialog, type PlanBlock } from '@/components/velocity/planner-parts';
import VelocityShell from '@/layouts/velocity-shell';
import { Clock, fmtHours, round1 } from '@/lib/velocity';
import type { VelocityShared } from '@/types/velocity';
import { router, usePage } from '@inertiajs/react';
import { type MouseEvent, type PointerEvent as ReactPointerEvent, useEffect, useMemo, useRef, useState } from 'react';

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
interface Unbooked {
    taskId: number;
    title: string;
    rank: number | null;
    minutes: number;
    dueAt: string | null;
    dueInPeriod: boolean;
}
interface Props extends VelocityShared {
    view: 'day' | 'week';
    date: string;
    today: string;
    days: string[];
    events: Occurrence[];
    blocks: PlanBlock[];
    unbooked: Unbooked[];
    hours: Hours;
    dayLimit: number;
    busy: string[];
    calendars: Record<CalendarKey, number>;
    dueTasks: { id: number; title: string; dueAt: string }[];
}

const HOUR = 48; // px for one hour in the grid
const SNAP = 15; // minutes a dragged block snaps to
const ICON: Record<CalendarKey, string> = { class: 'cap', work: 'briefcase', personal: 'heart' };
const LABEL: Record<CalendarKey, string> = { class: 'Class', work: 'Work', personal: 'Personal' };
const keep = { preserveScroll: true, preserveState: true };

/** "2026-11-13" ± n days. */
const shift = (day: string, n: number) => {
    const d = new Date(`${day}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
};
const at = (day: string) => `${day}T12:00:00Z`; // a moment on that date, for formatting
const hhmm = (min: number) => `${String(Math.floor(min / 60) % 24).padStart(2, '0')}:${String(min % 60).padStart(2, '0')}`;
/** A minute of the day as the app shows times: "9:30 am", or "9 am" when short. */
const minLabel = (min: number, short = false) => {
    const h = Math.floor(min / 60) % 24;
    const m = min % 60;
    return `${((h + 11) % 12) + 1}${m || !short ? `:${String(m).padStart(2, '0')}` : ''} ${h < 12 ? 'am' : 'pm'}`;
};

/** A local date and minute of the day. */
interface Spot {
    date: string;
    min: number;
}
function local(clock: Clock, iso: string): Spot {
    const v = clock.localInput(iso);
    return { date: v.slice(0, 10), min: Number(v.slice(11, 13)) * 60 + Number(v.slice(14, 16)) };
}
const later = (s: Spot, minutes: number): Spot => ({ date: shift(s.date, Math.floor((s.min + minutes) / 1440)), min: (s.min + minutes) % 1440 });
const lengthOf = (b: PlanBlock) => Math.round((Date.parse(b.end) - Date.parse(b.start)) / 60000);
/** Minutes from one spot to a later one. */
const between = (a: Spot, b: Spot) => Math.round((Date.parse(b.date) - Date.parse(a.date)) / 60000) + b.min - a.min;

type Item = { key: string; s: Spot; e: Spot } & ({ kind: 'event'; ev: Occurrence } | { kind: 'block'; bl: PlanBlock });
interface Piece {
    it: Item;
    top: number;
    bottom: number;
    lane: number;
    lanes: number;
}

/** The parts of events and blocks that fall on one day, side by side where they overlap. */
function layoutDay(items: Item[], day: string): Piece[] {
    const pieces: Piece[] = [];
    items.forEach((it) => {
        if (it.s.date > day || it.e.date < day || (it.e.date === day && it.e.min === 0)) return;
        const top = it.s.date === day ? it.s.min : 0;
        const bottom = it.e.date === day ? it.e.min : 1440;
        if (bottom > top) pieces.push({ it, top, bottom, lane: 0, lanes: 1 });
    });
    pieces.sort((a, b) => a.top - b.top || b.bottom - a.bottom);
    // Groups of things that overlap share the width of the column.
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

/** A block being dragged: one already on the grid (move) or a task from "Not booked yet" (new). */
interface Drag {
    kind: 'move' | 'new';
    blockId?: number;
    taskId: number;
    title: string;
    rank: number | null;
    minutes: number;
    grab: number; // minutes from the block's top to the pointer
    from?: Spot;
    to: Spot | null; // where it would land now (null: not over the grid)
}

/** The Planner: a day or a week of classes, events and the time booked for tasks. */
export default function Planner() {
    const { props } = usePage<Props>();
    const clock = Clock.from(props.clock);
    const [dialog, setDialog] = useState<null | { initial: EventForm; id?: number; occurrence?: string; day?: string }>(null);
    const [hoursOpen, setHoursOpen] = useState(false);
    const [menu, setMenu] = useState<null | { bl: PlanBlock; x: number; y: number; s: Spot; e: Spot }>(null);
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
    const plan = (taskId?: number) => router.post(route('planner.plan'), { date: props.date, span: props.view, task_id: taskId ?? null }, keep);

    // Phones show one day at a time.
    useEffect(() => {
        if (props.view === 'week' && matchMedia('(max-width: 767px)').matches)
            router.get('/planner', { date: props.date, view: 'day' }, { replace: true });
    }, [props.view, props.date]);

    // Start the grid at 7 am (or just before the first thing on), once.
    const scroller = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const first = Math.min(
            7 * 60,
            ...events.filter((e) => !e.allDay).map((e) => local(clock, e.start).min),
            ...props.blocks.map((b) => local(clock, b.start).min),
        );
        scroller.current?.scrollTo({ top: Math.max(0, (first / 60) * HOUR - 12) });
    }, [props.date, props.view]); // eslint-disable-line react-hooks/exhaustive-deps

    // The "now" line, moved every minute.
    const [nowMin, setNowMin] = useState(() => local(clock, clock.now.toISOString()).min);
    useEffect(() => {
        const t = setInterval(() => setNowMin((m) => m + 1), 60_000);
        return () => clearInterval(t);
    }, []);

    // Moves and drops show at once; the server's answer replaces them (a new `blocks` list).
    const [pending, setPending] = useState<{ base: PlanBlock[]; moves: Record<number, Spot>; added: Item[] }>({
        base: props.blocks,
        moves: {},
        added: [],
    });
    const mine = useMemo(() => (pending.base === props.blocks ? pending : { base: props.blocks, moves: {}, added: [] }), [pending, props.blocks]);

    const items = useMemo<Item[]>(() => {
        const evs: Item[] = events
            .filter((e) => !e.allDay)
            .map((ev) => ({ key: ev.key, kind: 'event', ev, s: local(clock, ev.start), e: local(clock, ev.end) }));
        const bls: Item[] = props.blocks.map((bl) => {
            const moved = mine.moves[bl.id];
            return moved
                ? { key: `b${bl.id}`, kind: 'block', bl, s: moved, e: later(moved, lengthOf(bl)) }
                : { key: `b${bl.id}`, kind: 'block', bl, s: local(clock, bl.start), e: local(clock, bl.end) };
        });
        return [...evs, ...bls, ...mine.added];
    }, [props.events, props.blocks, hidden, mine]); // eslint-disable-line react-hooks/exhaustive-deps
    const pieces = useMemo(() => Object.fromEntries(props.days.map((d) => [d, layoutDay(items, d)])), [items, props.days]);
    // Time booked for tasks on each day (by the day it starts).
    const load = useMemo(() => {
        const out: Record<string, number> = {};
        items.forEach((it) => {
            if (it.kind === 'block') out[it.s.date] = (out[it.s.date] ?? 0) + between(it.s, it.e);
        });
        return out;
    }, [items]);

    // ---- Dragging blocks (mouse at once; touch after a short hold, so a swipe still scrolls) ----
    const cols = useRef<Record<string, HTMLDivElement | null>>({});
    const [drag, setDrag] = useState<Drag | null>(null);
    const justDragged = useRef(false);

    const spotAt = (x: number, y: number, d: Drag): Spot | null => {
        for (const day of props.days) {
            const r = cols.current[day]?.getBoundingClientRect();
            if (r && x >= r.left && x < r.right) {
                const raw = ((y - r.top) / HOUR) * 60 - d.grab;
                return { date: day, min: Math.max(0, Math.min(1440 - d.minutes, Math.round(raw / SNAP) * SNAP)) };
            }
        }
        return null;
    };

    const begin = (e: ReactPointerEvent, base: Omit<Drag, 'to'>) => {
        if (e.button !== 0) return;
        const start = { x: e.clientX, y: e.clientY };
        let active = false;
        let current: Drag = { ...base, to: null };
        const stopScroll = (ev: TouchEvent) => ev.preventDefault();
        const activate = (x: number, y: number) => {
            active = true;
            current = { ...current, to: spotAt(x, y, current) };
            setDrag(current);
            window.addEventListener('touchmove', stopScroll, { passive: false });
            if (e.pointerType === 'touch') navigator.vibrate?.(8);
        };
        const timer = e.pointerType === 'touch' ? window.setTimeout(() => activate(start.x, start.y), 350) : undefined;
        const onMove = (ev: PointerEvent) => {
            const far = Math.hypot(ev.clientX - start.x, ev.clientY - start.y);
            if (!active) {
                if (e.pointerType === 'touch') {
                    if (far > 8) end(); // a swipe: let the page scroll
                    return;
                }
                if (far < 4) return;
                activate(ev.clientX, ev.clientY);
            }
            // Near the top or bottom of the grid: scroll it along.
            const box = scroller.current?.getBoundingClientRect();
            if (box && ev.clientY > box.bottom - 30) scroller.current?.scrollBy({ top: 12 });
            if (box && ev.clientY < box.top + 30) scroller.current?.scrollBy({ top: -12 });
            current = { ...current, to: spotAt(ev.clientX, ev.clientY, current) };
            setDrag(current);
        };
        const onUp = () => {
            if (active) {
                justDragged.current = true;
                setTimeout(() => (justDragged.current = false), 50);
                drop(current);
            }
            end();
        };
        const end = () => {
            clearTimeout(timer);
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onUp);
            window.removeEventListener('pointercancel', end);
            window.removeEventListener('touchmove', stopScroll);
            setDrag(null);
        };
        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onUp);
        window.addEventListener('pointercancel', end);
    };

    const drop = (d: Drag) => {
        const to = d.to;
        if (!to || (d.from && d.from.date === to.date && d.from.min === to.min)) return;
        const data = { date: to.date, start: hhmm(to.min) };
        if (d.kind === 'move' && d.blockId) {
            setPending({ ...mine, moves: { ...mine.moves, [d.blockId]: to } });
            router.patch(route('blocks.update', d.blockId), data, keep);
        } else {
            const bl: PlanBlock = { id: -Date.now(), taskId: d.taskId, title: d.title, start: '', end: '', pinned: true, rank: d.rank, dueAt: null };
            setPending({ ...mine, added: [...mine.added, { key: `new${bl.id}`, kind: 'block', bl, s: to, e: later(to, d.minutes) }] });
            router.post(route('blocks.store'), { ...data, task_id: d.taskId, minutes: d.minutes }, keep);
        }
    };

    const grabBlock = (e: ReactPointerEvent<HTMLElement>, bl: PlanBlock, s: Spot) => {
        const top = e.currentTarget.getBoundingClientRect().top;
        const grab = Math.round(((e.clientY - top) / HOUR) * 60);
        begin(e, { kind: 'move', blockId: bl.id, taskId: bl.taskId, title: bl.title, rank: bl.rank, minutes: lengthOf(bl), grab, from: s });
    };
    const grabUnbooked = (e: ReactPointerEvent<HTMLElement>, u: Unbooked) => {
        if ((e.target as HTMLElement).closest('button')) return;
        begin(e, { kind: 'new', taskId: u.taskId, title: u.title, rank: u.rank, minutes: Math.min(u.minutes, 120), grab: 15 });
    };
    const openBlock = (e: MouseEvent<HTMLElement>, bl: PlanBlock, s: Spot, end: Spot) => {
        if (justDragged.current || bl.id < 0) return;
        const r = e.currentTarget.getBoundingClientRect();
        setMenu({ bl, x: e.clientX || r.left, y: (e.clientY || r.top) + 8, s, e: end });
    };

    const range =
        props.view === 'week'
            ? `${clock.date(at(props.days[0]), { day: 'numeric', month: 'short' })} – ${clock.date(at(props.days[6]), { day: 'numeric', month: 'short', year: 'numeric' })}`
            : clock.date(at(props.date), { weekday: 'long', day: 'numeric', month: 'long' });
    const allDay = events.filter((e) => e.allDay);

    const newAt = (day: string, e: MouseEvent<HTMLDivElement>) => {
        if (e.target !== e.currentTarget) return;
        const y = e.clientY - e.currentTarget.getBoundingClientRect().top;
        const min = Math.max(0, Math.min(23 * 60, Math.floor((y / HOUR) * 2) * 30));
        setDialog({ initial: blank(day, min) });
    };
    const openEvent = (ev: Occurrence) => setDialog({ initial: ev.form, id: ev.id, occurrence: ev.repeats ? ev.date : undefined, day: ev.date });

    const sideMenu = () => (
        <PlannerMenu
            props={props}
            clock={clock}
            hidden={hidden}
            onToggle={toggleCalendar}
            onNew={() => setDialog({ initial: blank(props.date) })}
            onHours={() => setHoursOpen(true)}
            onBook={plan}
            onGrab={grabUnbooked}
            go={go}
        />
    );

    return (
        <VelocityShell title="Planner" section="planner" active={props.view} menu={sideMenu}>
            <div className={`main-inner planner${drag ? ' is-dragging' : ''}`}>
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
                        <button className="btn btn-secondary" onClick={() => setDialog({ initial: blank(props.date) })} aria-label="New event">
                            <Icon name="plus" />
                            <span className="btn-label">Event</span>
                        </button>
                        <button className="btn btn-primary" onClick={() => plan()}>
                            <Icon name="wand" />
                            <span className="btn-label">{props.view === 'week' ? 'Plan week' : 'Plan day'}</span>
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
                                <i className={`pg-load${(load[d] ?? 0) > props.dayLimit * 60 ? ' is-over' : ''}`}>
                                    {load[d] ? `${round1(load[d] / 60)} / ${round1(props.dayLimit)} h` : ''}
                                </i>
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
                                        {h === 0 ? '' : minLabel(h * 60, true)}
                                    </span>
                                ))}
                            </div>
                            {props.days.map((d) => (
                                <div
                                    key={d}
                                    ref={(el) => {
                                        cols.current[d] = el;
                                    }}
                                    className={`pg-col${d === props.today ? ' is-today' : ''}`}
                                    onClick={(e) => newAt(d, e)}
                                    role="presentation"
                                    title="Click to add an event"
                                >
                                    {pieces[d].map((p) => {
                                        const style = {
                                            top: (p.top / 60) * HOUR,
                                            height: Math.max(20, ((p.bottom - p.top) / 60) * HOUR - 2),
                                            left: `calc(${(p.lane / p.lanes) * 100}% + 2px)`,
                                            width: `calc(${100 / p.lanes}% - 4px)`,
                                        };
                                        const short = p.bottom - p.top < 45;
                                        if (p.it.kind === 'block') {
                                            const { bl, s, e } = p.it;
                                            const when = `${minLabel(s.min)}–${minLabel(e.min)}`;
                                            return (
                                                <button
                                                    key={p.it.key}
                                                    className={`pblock${short ? ' is-short' : ''}${drag?.blockId === bl.id ? ' is-moving' : ''}${bl.id < 0 ? ' is-saving' : ''}`}
                                                    style={style}
                                                    onPointerDown={(ev) => bl.id > 0 && grabBlock(ev, bl, s)}
                                                    onClick={(ev) => openBlock(ev, bl, s, e)}
                                                    onContextMenu={(ev) => ev.preventDefault()}
                                                    title={bl.title}
                                                    aria-label={`${bl.title}, ${when}`}
                                                    aria-haspopup="menu"
                                                >
                                                    <span className="pblock-title">
                                                        {bl.rank && <span className="pblock-rank">#{bl.rank}</span>}
                                                        {bl.title}
                                                        {bl.pinned && <Icon name="pin" className="ic-xs pblock-pin" label="Placed by you" />}
                                                    </span>
                                                    <span className="pblock-sub">{when}</span>
                                                </button>
                                            );
                                        }
                                        const ev = p.it.ev;
                                        return (
                                            <button
                                                key={p.it.key}
                                                className={`pev is-${ev.calendar}${short ? ' is-short' : ''}`}
                                                style={style}
                                                onClick={() => openEvent(ev)}
                                                aria-label={`${LABEL[ev.calendar]}: ${ev.title}, ${hhmm(p.top)} to ${hhmm(p.bottom)}`}
                                            >
                                                <span className="pev-title">
                                                    <Icon name={ICON[ev.calendar]} className="ic-xs" />
                                                    {ev.title}
                                                </span>
                                                <span className="pev-sub">
                                                    {clock.time(ev.start)}–{clock.time(ev.end)}
                                                    {ev.location ? ` · ${ev.location}` : ''}
                                                </span>
                                            </button>
                                        );
                                    })}
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
                                    {drag?.to?.date === d && (
                                        <span
                                            className="pblock is-ghost"
                                            style={{
                                                top: (drag.to.min / 60) * HOUR,
                                                height: Math.max(20, (Math.min(drag.minutes, 1440 - drag.to.min) / 60) * HOUR - 2),
                                                left: 2,
                                                right: 2,
                                            }}
                                            aria-hidden="true"
                                        >
                                            <span className="pblock-title">{drag.title}</span>
                                            <span className="pblock-sub">
                                                {minLabel(drag.to.min)}–{minLabel(later(drag.to, drag.minutes).min)}
                                            </span>
                                        </span>
                                    )}
                                    {d === props.today && <span className="pg-now" style={{ top: (nowMin / 60) * HOUR }} aria-hidden="true" />}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
            {dialog && (
                <EventDialog
                    initial={dialog.initial}
                    eventId={dialog.id}
                    occurrenceDate={dialog.occurrence}
                    day={dialog.day}
                    onClose={() => setDialog(null)}
                />
            )}
            {hoursOpen && <HoursDialog hours={props.hours} onClose={() => setHoursOpen(false)} />}
            {menu && (
                <BlockMenu
                    block={menu.bl}
                    x={menu.x}
                    y={menu.y}
                    minutes={lengthOf(menu.bl)}
                    when={`${clock.weekday(at(menu.s.date))} ${minLabel(menu.s.min)}–${minLabel(menu.e.min)}`}
                    onClose={() => setMenu(null)}
                />
            )}
        </VelocityShell>
    );
}

/** The Planner's side menu: New event, a month calendar, Day / Week, calendars, working hours and what is not booked yet. */
function PlannerMenu({
    props,
    clock,
    hidden,
    onToggle,
    onNew,
    onHours,
    onBook,
    onGrab,
    go,
}: {
    props: Props;
    clock: Clock;
    hidden: CalendarKey[];
    onToggle: (k: CalendarKey) => void;
    onNew: () => void;
    onHours: () => void;
    onBook: (taskId: number) => void;
    onGrab: (e: ReactPointerEvent<HTMLElement>, u: Unbooked) => void;
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
                <button className="nav-item" onClick={onHours}>
                    <Icon name="calClock" />
                    <span className="label">Working hours</span>
                </button>
                {props.unbooked.length > 0 && (
                    <>
                        <div className="nav-label">
                            Not booked yet
                            <span className="count">{props.unbooked.length}</span>
                        </div>
                        <ul className="ub-list">
                            {props.unbooked.map((u) => {
                                const overdue = u.dueAt !== null && Date.parse(u.dueAt) < clock.now.getTime();
                                return (
                                    <li key={u.taskId} className="ub-item" onPointerDown={(e) => onGrab(e, u)} title={u.title}>
                                        <span className={`rank-badge${u.rank ? '' : ' is-none'}`}>{u.rank ?? '–'}</span>
                                        <span className="ub-main">
                                            <span className="ub-title">{u.title}</span>
                                            <span className="ub-sub">
                                                {fmtHours(u.minutes / 60)}
                                                {u.dueAt && (u.dueInPeriod || overdue) && (
                                                    <span className={overdue ? 'is-late' : 'ub-due'}>
                                                        <Icon name="flag" className="ic-xs" />
                                                        {clock.weekday(u.dueAt)} {clock.time(u.dueAt)}
                                                    </span>
                                                )}
                                            </span>
                                        </span>
                                        <button className="icon-btn" onClick={() => onBook(u.taskId)} aria-label={`Book “${u.title}”`} title="Book">
                                            <Icon name="calPlus" />
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    </>
                )}
            </nav>
        </>
    );
}
