import { type CalendarKey, EventDialog, type EventForm } from '@/components/velocity/event-dialog';
import { Modal } from '@/components/velocity/forms';
import { DayEditor, Pop } from '@/components/velocity/habit-parts';
import { Icon } from '@/components/velocity/icon';
import { MARKER_ICON, type Marker, MonthGrid } from '@/components/velocity/planner-month';
import { BlockMenu, type Hours, HoursDialog, KindSeg, type NewKind, type PlanBlock, TimeDialog } from '@/components/velocity/planner-parts';
import VelocityShell from '@/layouts/velocity-shell';
import { Clock, fmtHours, round1 } from '@/lib/velocity';
import type { VelocityShared } from '@/types/velocity';
import { Link, router, usePage } from '@inertiajs/react';
import {
    type CSSProperties,
    type MouseEvent,
    type PointerEvent as ReactPointerEvent,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';

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
    locked: boolean;
    /** This day was cancelled: shown crossed out, takes no time. */
    cancelled: boolean;
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
type View = 'day' | 'week' | 'month';
const VIEWS: [View, string, string][] = [
    ['day', 'Day', 'planner'],
    ['week', 'Week', 'columns'],
    ['month', 'Month', 'calendar'],
];

interface Props extends VelocityShared {
    view: View;
    date: string;
    today: string;
    days: string[];
    events: Occurrence[];
    blocks: PlanBlock[];
    unbooked: Unbooked[];
    hours: Hours;
    /** Hours of task work "Plan" books a day at most (Settings → Planner); null = all the working hours. */
    dayLimit: number | null;
    busy: string[];
    dueTasks: { id: number; title: string; dueAt: string }[];
    habitTimes: PlanHabit[];
    personDates: { key: string; date: string; title: string; kind: 'birthday' | 'anniversary' | 'other'; personId: number }[];
    followUps: { id: number; title: string; date: string }[];
    free: { date: string; slots: { start: string; end: string }[]; minutes: number }[];
    taskChoices: { id: number; title: string; rank: number }[];
    markers: Marker[];
}

/** What "Show" can hide on the Planner, kept on this computer. */
type ShowKey = 'due' | 'projects' | 'goals' | 'habits' | 'people' | 'followUps';
const SHOW: [ShowKey, string, string][] = [
    ['due', 'Task due dates', 'flag'],
    ['projects', 'Projects and milestones', 'folder'],
    ['goals', 'Goal dates', 'target'],
    ['habits', 'Habits', 'habits'],
    ['people', 'Birthdays and catch-ups', 'gift'],
    ['followUps', 'Follow-ups', 'followUp'],
];
const MARKER_SHOW: Record<Marker['kind'], ShowKey> = {
    project: 'projects',
    milestone: 'projects',
    goal: 'goals',
    habit: 'habits',
    catchup: 'people',
};

/** A habit with a set time, on a day it is due. */
interface PlanHabit {
    id: number;
    key: string;
    date: string;
    title: string;
    start: string;
    end: string;
    done: boolean;
    skipped: boolean;
    count: number;
    target: number;
}

/** One hour in the grid: sized so the working day fits the screen, between these (px). */
const HOUR_MIN = 60;
const HOUR_MAX = 76;
const toMin = (hm: string) => Number(hm.slice(0, 2)) * 60 + Number(hm.slice(3, 5));
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
/** "23–29 Nov 2026", "30 Nov – 6 Dec 2026" or "29 Dec 2026 – 4 Jan 2027": short, so the header keeps one line. */
function weekRange(a: string, b: string, clock: Clock): string {
    const end = clock.date(at(b), { day: 'numeric', month: 'short', year: 'numeric' });
    if (a.slice(0, 7) === b.slice(0, 7)) return `${Number(a.slice(8))}–${end}`;
    if (a.slice(0, 4) === b.slice(0, 4)) return `${clock.date(at(a), { day: 'numeric', month: 'short' })} – ${end}`;
    return `${clock.date(at(a), { day: 'numeric', month: 'short', year: 'numeric' })} – ${end}`;
}

/** Minutes from one spot to a later one. */
const between = (a: Spot, b: Spot) => Math.round((Date.parse(b.date) - Date.parse(a.date)) / 60000) + b.min - a.min;

type Item = { key: string; s: Spot; e: Spot } & (
    | { kind: 'event'; ev: Occurrence }
    | { kind: 'block'; bl: PlanBlock }
    | { kind: 'habit'; hb: PlanHabit }
);
interface Piece {
    it: Item;
    top: number;
    bottom: number;
    lane: number;
    lanes: number;
}

/** Minutes from a piece's start to the next piece below it that shares some of its width (Infinity: none). */
function roomBelow(list: Piece[], p: Piece): number {
    const a0 = p.lane / p.lanes;
    const a1 = (p.lane + 1) / p.lanes;
    let room = Infinity;
    for (const q of list) {
        if (q === p || q.top <= p.top) continue;
        if (q.lane / q.lanes < a1 && (q.lane + 1) / q.lanes > a0) room = Math.min(room, q.top - p.top);
    }
    return room;
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

/**
 * A block or event being dragged: one already on the grid (move), its bottom edge (resize),
 * or a task from "Not booked yet" (new).
 */
interface Drag {
    kind: 'move' | 'new' | 'resize';
    length?: number; // resize: the length it had
    blockId?: number;
    eventKey?: string; // a class or event (one day of it)
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
    const [dialog, setDialog] = useState<null | { initial: EventForm; id?: number; occurrence?: string; day?: string; cancelled?: boolean }>(null);
    const [newKind, setNewKind] = useState<NewKind>('event');
    const closeDialog = () => {
        setDialog(null);
        setNewKind('event');
    };
    const [hoursOpen, setHoursOpen] = useState(false);
    const [habitSlot, setHabitSlot] = useState<null | { hb: PlanHabit; x: number; y: number }>(null);
    const [menu, setMenu] = useState<null | { bl: PlanBlock; x: number; y: number; s: Spot; e: Spot }>(null);
    // Every calendar shows (the calendar filters were removed from the side menu, 23 Nov 2026).
    const events = props.events;
    // The Show and Free time drop-downs in the page's header.
    const [pop, setPop] = useState<null | { kind: 'show' | 'free'; x: number; y: number }>(null);
    const openPop = (kind: 'show' | 'free', e: MouseEvent<HTMLElement>, width: number) => {
        if (pop?.kind === kind) return setPop(null);
        const r = e.currentTarget.getBoundingClientRect();
        setPop({ kind, x: r.right - width, y: r.bottom + 6 });
    };
    // "Show": which kinds of markers and times are on the grid (kept on this computer).
    const [off, setOff] = useState<ShowKey[]>(() => {
        try {
            return JSON.parse(localStorage.getItem('velocity.plannerHidden') ?? '[]');
        } catch {
            return [];
        }
    });
    const toggleShow = (k: ShowKey) => {
        const next = off.includes(k) ? off.filter((x) => x !== k) : [...off, k];
        setOff(next);
        localStorage.setItem('velocity.plannerHidden', JSON.stringify(next));
    };
    const on = (k: ShowKey) => !off.includes(k);
    const dueTasks = on('due') ? props.dueTasks : [];
    const personDates = on('people') ? (props.personDates ?? []) : [];
    const followUps = on('followUps') ? (props.followUps ?? []) : [];
    const habitTimes = on('habits') ? (props.habitTimes ?? []) : [];
    const markers = (props.markers ?? []).filter((m) => on(MARKER_SHOW[m.kind]));
    const go = (date: string, view = props.view) => router.get('/planner', { date, view }, { preserveScroll: true });
    const step = props.view === 'week' ? 7 : 1;
    const firstOfMonth = `${props.date.slice(0, 8)}01`;
    // Back or on by one day, week or month (a month: to its first day).
    const move = (dir: -1 | 1) =>
        go(props.view === 'month' ? `${shift(firstOfMonth, dir < 0 ? -1 : 32).slice(0, 8)}01` : shift(props.date, dir * step));
    const unit = props.view === 'month' ? 'month' : props.view === 'week' ? 'week' : 'day';
    const plan = (taskId?: number) => router.post(route('planner.plan'), { date: props.date, span: props.view, task_id: taskId ?? null }, keep);
    // The Plan button's second press: the planner's own blocks still ahead in the days shown go (Undo brings them back).
    const planned = props.blocks.some(
        (b) => !b.pinned && !b.locked && Date.parse(b.start) >= clock.now.getTime() && props.days.includes(local(clock, b.start).date),
    );
    const clearPlan = () => router.post(route('planner.clear'), { date: props.date, span: props.view }, keep);
    // A day's hours of task work at most: the limit in Settings → Planner, or else its working hours.
    const capOf = (d: string) => {
        if (props.dayLimit) return props.dayLimit;
        const h = props.hours[(new Date(at(d)).getUTCDay() + 6) % 7];
        return h ? (toMin(h.end) - toMin(h.start)) / 60 : 0;
    };

    // Phones show one day at a time. (A moment later: on the first draw Inertia isn't ready to move yet.)
    useEffect(() => {
        // Only when no view was asked for: "Week" tapped on a phone stays a week.
        if (props.view !== 'week' || !matchMedia('(max-width: 767px)').matches || new URLSearchParams(location.search).has('view')) return;
        const t = setTimeout(() => router.get('/planner', { date: props.date, view: 'day' }, { replace: true, preserveScroll: true }), 0);
        return () => clearTimeout(t);
    }, [props.view, props.date]);

    // The working day of the week (earliest start to latest end): the grid is sized so it fits the screen.
    const workDays = props.hours.filter((h): h is { start: string; end: string } => h !== null);
    const workFrom = workDays.length ? Math.min(...workDays.map((h) => toMin(h.start))) : 8 * 60;
    const workTo = workDays.length ? Math.max(...workDays.map((h) => toMin(h.end))) : 18 * 60;

    // One scroll only: the grid takes the height left on the screen (the page itself doesn't scroll), and an
    // hour is as tall as lets the working day fill it, within HOUR_MIN–HOUR_MAX.
    const scroller = useRef<HTMLDivElement>(null);
    const [HOUR, setHour] = useState(64);
    useLayoutEffect(() => {
        const el = scroller.current;
        if (!el) return;
        const fit = () => {
            el.style.maxHeight = '';
            const room = Math.max(300, window.innerHeight - el.getBoundingClientRect().top - window.scrollY - 18);
            el.style.maxHeight = `${room}px`;
            const over = document.documentElement.scrollHeight - window.innerHeight;
            if (over > 0) el.style.maxHeight = `${Math.max(300, room - over)}px`;
            const h = el.clientHeight || room;
            setHour(Math.max(HOUR_MIN, Math.min(HOUR_MAX, Math.floor(h / Math.max(6, (workTo - workFrom) / 60)))));
        };
        fit();
        window.addEventListener('resize', fit);
        return () => window.removeEventListener('resize', fit);
    }, [props.view, props.date, workFrom, workTo]);

    // Start the grid at the working day's start (or just before the first thing on).
    useLayoutEffect(() => {
        const first = Math.min(
            workFrom,
            ...events.filter((e) => !e.allDay).map((e) => local(clock, e.start).min),
            ...props.blocks.map((b) => local(clock, b.start).min),
        );
        scroller.current?.scrollTo({ top: Math.max(0, (first / 60) * HOUR - 10) });
    }, [props.date, props.view, HOUR]); // eslint-disable-line react-hooks/exhaustive-deps

    // The "now" line, moved every minute.
    const [nowMin, setNowMin] = useState(() => local(clock, clock.now.toISOString()).min);
    useEffect(() => {
        const t = setInterval(() => setNowMin((m) => m + 1), 60_000);
        return () => clearInterval(t);
    }, []);

    // Moves and drops show at once; the server's answer replaces them (a new `blocks` list).
    const [pending, setPending] = useState<{ base: PlanBlock[]; moves: Record<number, Spot>; sizes: Record<number, number>; added: Item[] }>({
        base: props.blocks,
        moves: {},
        sizes: {},
        added: [],
    });
    const mine = useMemo(
        () => (pending.base === props.blocks ? pending : { base: props.blocks, moves: {}, sizes: {}, added: [] }),
        [pending, props.blocks],
    );

    // The same for events dragged to a new time.
    const [evPending, setEvPending] = useState<{ base: Occurrence[]; moves: Record<string, { s: Spot; e: Spot }> }>({
        base: props.events,
        moves: {},
    });
    const evMoves = evPending.base === props.events ? evPending.moves : {};

    const items = useMemo<Item[]>(() => {
        const evs: Item[] = events
            .filter((e) => !e.allDay)
            .map((ev) => ({
                key: ev.key,
                kind: 'event',
                ev,
                s: evMoves[ev.key]?.s ?? local(clock, ev.start),
                e: evMoves[ev.key]?.e ?? local(clock, ev.end),
            }));
        const bls: Item[] = props.blocks.map((bl) => {
            const moved = mine.moves[bl.id];
            const size = mine.sizes[bl.id];
            const s = moved ?? local(clock, bl.start);
            return { key: `b${bl.id}`, kind: 'block', bl, s, e: moved || size ? later(s, size ?? lengthOf(bl)) : local(clock, bl.end) };
        });
        const hbs: Item[] = habitTimes.map((hb) => ({
            key: hb.key,
            kind: 'habit',
            hb,
            s: local(clock, hb.start),
            e: local(clock, hb.end),
        }));
        return [...evs, ...hbs, ...bls, ...mine.added];
    }, [props.events, props.blocks, props.habitTimes, off, mine, evPending]); // eslint-disable-line react-hooks/exhaustive-deps
    const pieces = useMemo(() => Object.fromEntries(props.days.map((d) => [d, layoutDay(items, d)])), [items, props.days]);
    // Blocks that share their time with something shown (an event, a habit time, another block): marked, never moved.
    const clashes = useMemo(() => {
        const abs = (sp: Spot) => Date.parse(`${sp.date}T00:00:00Z`) / 60000 + sp.min;
        const out = new Set<number>();
        items.forEach((b) => {
            if (b.kind !== 'block') return;
            if (
                items.some(
                    (o) =>
                        o !== b &&
                        !(o.kind === 'habit' && o.hb.done) &&
                        !(o.kind === 'event' && o.ev.cancelled) &&
                        abs(o.s) < abs(b.e) &&
                        abs(o.e) > abs(b.s),
                )
            )
                out.add(b.bl.id);
        });
        return out;
    }, [items]);
    // Time booked for tasks on each day (by the day it starts).
    const load = useMemo(() => {
        const out: Record<string, number> = {};
        items.forEach((it) => {
            if (it.kind === 'block' && it.bl.kind !== 'reserved') out[it.s.date] = (out[it.s.date] ?? 0) + between(it.s, it.e);
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
    // Resizing: the new length, from where the bottom edge is (at least 15 minutes, not past midnight).
    const sizeAt = (y: number, d: Drag): number => {
        const r = d.from && cols.current[d.from.date]?.getBoundingClientRect();
        if (!r || !d.from) return d.minutes;
        const endMin = Math.round((((y - r.top) / HOUR) * 60) / SNAP) * SNAP;
        return Math.max(SNAP, Math.min(1440 - d.from.min, endMin - d.from.min));
    };
    const place = (d: Drag, x: number, y: number): Drag =>
        d.kind === 'resize' ? { ...d, minutes: sizeAt(y, d), to: d.from ?? null } : { ...d, to: spotAt(x, y, d) };

    const begin = (e: ReactPointerEvent, base: Omit<Drag, 'to'>) => {
        if (e.button !== 0) return;
        const start = { x: e.clientX, y: e.clientY };
        let active = false;
        let current: Drag = { ...base, to: null };
        const stopScroll = (ev: TouchEvent) => ev.preventDefault();
        const activate = (x: number, y: number) => {
            active = true;
            current = place(current, x, y);
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
            current = place(current, ev.clientX, ev.clientY);
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

    // A locked block or event asks before it moves or changes length; a weekly event asks "this week or every week?".
    const [lockedDrop, setLockedDrop] = useState<Drag | null>(null);
    const [scopeDrop, setScopeDrop] = useState<{ d: Drag; anyway: boolean } | null>(null);
    const drop = (d: Drag, anyway = false, scope?: 'one' | 'all') => {
        const changes = d.kind === 'resize' ? d.minutes !== d.length : !!d.to && !(d.from && d.from.date === d.to.date && d.from.min === d.to.min);
        if (d.eventKey) {
            const ev = props.events.find((x) => x.key === d.eventKey);
            const to = d.kind === 'resize' ? d.from : d.to;
            if (!ev || !to || !changes) return;
            if (ev.locked && !anyway) {
                setLockedDrop(d);
                return;
            }
            if (ev.repeats && !scope) {
                setScopeDrop({ d, anyway });
                return;
            }
            setEvPending({ base: props.events, moves: { ...evMoves, [ev.key]: { s: to, e: later(to, d.minutes) } } });
            router.patch(
                route('events.move', ev.id),
                { occurrence: ev.date, date: to.date, start: hhmm(to.min), minutes: d.minutes, scope: scope ?? 'all', anyway },
                keep,
            );
            return;
        }
        const isLocked = d.blockId !== undefined && props.blocks.some((b) => b.id === d.blockId && b.locked);
        if (isLocked && !anyway && changes) {
            setLockedDrop(d);
            return;
        }
        if (d.kind === 'resize') {
            if (!d.blockId || !d.from || d.minutes === d.length) return;
            setPending({ ...mine, sizes: { ...mine.sizes, [d.blockId]: d.minutes } });
            router.patch(route('blocks.update', d.blockId), { date: d.from.date, start: hhmm(d.from.min), minutes: d.minutes, anyway }, keep);
            return;
        }
        const to = d.to;
        if (!to || (d.from && d.from.date === to.date && d.from.min === to.min)) return;
        const data = { date: to.date, start: hhmm(to.min) };
        if (d.kind === 'move' && d.blockId) {
            setPending({ ...mine, moves: { ...mine.moves, [d.blockId]: to } });
            router.patch(route('blocks.update', d.blockId), { ...data, anyway }, keep);
        } else {
            const bl: PlanBlock = {
                id: -Date.now(),
                kind: 'task',
                taskId: d.taskId,
                projectId: null,
                title: d.title,
                start: '',
                end: '',
                pinned: true,
                locked: false,
                rank: d.rank,
                dueAt: null,
                next: null,
            };
            setPending({ ...mine, added: [...mine.added, { key: `new${bl.id}`, kind: 'block', bl, s: to, e: later(to, d.minutes) }] });
            router.post(route('blocks.store'), { ...data, task_id: d.taskId, minutes: d.minutes }, keep);
        }
    };

    const grabBlock = (e: ReactPointerEvent<HTMLElement>, bl: PlanBlock, s: Spot) => {
        const top = e.currentTarget.getBoundingClientRect().top;
        const grab = Math.round(((e.clientY - top) / HOUR) * 60);
        begin(e, { kind: 'move', blockId: bl.id, taskId: bl.taskId ?? 0, title: bl.title, rank: bl.rank, minutes: lengthOf(bl), grab, from: s });
    };
    const grabResize = (e: ReactPointerEvent<HTMLElement>, bl: PlanBlock, s: Spot) => {
        e.stopPropagation();
        const length = lengthOf(bl);
        begin(e, {
            kind: 'resize',
            blockId: bl.id,
            taskId: bl.taskId ?? 0,
            title: bl.title,
            rank: bl.rank,
            minutes: length,
            length,
            grab: 0,
            from: s,
        });
    };
    const grabEvent = (e: ReactPointerEvent<HTMLElement>, ev: Occurrence, s: Spot, end: Spot) => {
        const top = e.currentTarget.getBoundingClientRect().top;
        const grab = Math.round(((e.clientY - top) / HOUR) * 60);
        begin(e, { kind: 'move', eventKey: ev.key, taskId: 0, title: ev.title, rank: null, minutes: between(s, end), grab, from: s });
    };
    const grabEventResize = (e: ReactPointerEvent<HTMLElement>, ev: Occurrence, s: Spot, end: Spot) => {
        e.stopPropagation();
        const length = between(s, end);
        begin(e, { kind: 'resize', eventKey: ev.key, taskId: 0, title: ev.title, rank: null, minutes: length, length, grab: 0, from: s });
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
        props.view === 'month'
            ? clock.date(at(firstOfMonth), { month: 'long', year: 'numeric' })
            : props.view === 'week'
              ? weekRange(props.days[0], props.days[6], clock)
              : clock.date(at(props.date), { weekday: 'long', day: 'numeric', month: 'long' });
    const showsToday = props.view === 'month' ? props.date.slice(0, 7) === props.today.slice(0, 7) : props.days.includes(props.today);
    const allDay = events.filter((e) => e.allDay);

    const newAt = (day: string, e: MouseEvent<HTMLDivElement>) => {
        // Not the click that ends a drag (a locked block or event left where it was).
        if (e.target !== e.currentTarget || justDragged.current) return;
        const y = e.clientY - e.currentTarget.getBoundingClientRect().top;
        const min = Math.max(0, Math.min(23 * 60, Math.floor((y / HOUR) * 2) * 30));
        setDialog({ initial: blank(day, min) });
    };
    const openEvent = (ev: Occurrence) =>
        setDialog({ initial: ev.form, id: ev.id, occurrence: ev.repeats ? ev.date : undefined, day: ev.date, cancelled: ev.cancelled });

    const sideMenu = () => <PlannerMenu props={props} clock={clock} onBook={plan} onGrab={grabUnbooked} go={go} />;

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
                            <button className="icon-btn" aria-label={`Previous ${unit}`} onClick={() => move(-1)}>
                                <Icon name="chevL" />
                            </button>
                            <button className="btn btn-secondary btn-sm" onClick={() => go(props.today)} disabled={showsToday}>
                                Today
                            </button>
                            <button className="icon-btn" aria-label={`Next ${unit}`} onClick={() => move(1)}>
                                <Icon name="chevR" />
                            </button>
                        </div>
                        <div className="layout-seg planner-views" role="radiogroup" aria-label="Show">
                            {VIEWS.map(([v, label]) => (
                                <button key={v} role="radio" aria-checked={props.view === v} onClick={() => go(props.date, v)}>
                                    {label}
                                </button>
                            ))}
                        </div>
                        <button
                            className={`btn btn-secondary pl-drop pl-show${pop?.kind === 'show' ? ' is-open' : ''}`}
                            onClick={(e) => openPop('show', e, 270)}
                            aria-haspopup="dialog"
                            aria-expanded={pop?.kind === 'show'}
                            title="What shows on the planner"
                        >
                            <Icon name="eye" />
                            <span className="btn-label">Show</span>
                            <Icon name="chevD" className="ic-sm pl-drop-chev" />
                        </button>
                        {props.view !== 'month' && (
                            <button
                                className={`btn btn-secondary pl-drop pl-freebtn${pop?.kind === 'free' ? ' is-open' : ''}`}
                                onClick={(e) => openPop('free', e, 310)}
                                aria-haspopup="dialog"
                                aria-expanded={pop?.kind === 'free'}
                                title="Free time in working hours"
                            >
                                <Icon name="clock" />
                                <span className="btn-label">Free time</span>
                                <Icon name="chevD" className="ic-sm pl-drop-chev" />
                            </button>
                        )}
                        <button className="btn btn-secondary pl-hours" onClick={() => setHoursOpen(true)} title="Working hours">
                            <Icon name="calClock" />
                            <span className="btn-label">Working hours</span>
                        </button>
                        <button
                            className="btn btn-secondary pl-new"
                            onClick={() => setDialog({ initial: blank(props.date) })}
                            aria-label="New event"
                            title="New event"
                        >
                            <Icon name="plus" />
                        </button>
                        {props.view !== 'month' &&
                            (planned ? (
                                <button className="btn btn-secondary" onClick={clearPlan} title="Remove the time the planner booked (yours stays)">
                                    <Icon name="eraser" />
                                    <span className="btn-label">Clear plan</span>
                                </button>
                            ) : (
                                <button className="btn btn-primary" onClick={() => plan()}>
                                    <Icon name="wand" />
                                    <span className="btn-label">{props.view === 'week' ? 'Plan week' : 'Plan day'}</span>
                                </button>
                            ))}
                    </div>
                </header>

                {props.view === 'month' ? (
                    <MonthGrid
                        days={props.days}
                        month={props.date.slice(0, 7)}
                        today={props.today}
                        events={events}
                        blocks={props.blocks}
                        dueTasks={dueTasks}
                        personDates={personDates}
                        followUps={followUps}
                        markers={markers}
                        clock={clock}
                        onEvent={openEvent}
                        onDay={(d) => go(d, 'day')}
                    />
                ) : (
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
                                    <i className={`pg-load${capOf(d) > 0 && (load[d] ?? 0) > capOf(d) * 60 ? ' is-over' : ''}`}>
                                        {load[d]
                                            ? capOf(d) > 0
                                                ? `${round1(load[d] / 60)} / ${round1(capOf(d))} h`
                                                : `${round1(load[d] / 60)} h`
                                            : ''}
                                    </i>
                                </button>
                            ))}
                        </div>
                        {(allDay.length > 0 || personDates.length > 0 || followUps.length > 0 || markers.length > 0) && (
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
                                        {personDates
                                            .filter((pd) => pd.date === d)
                                            .map((pd) => (
                                                <Link key={pd.key} className="pev is-date" href={`/people?person=${pd.personId}`}>
                                                    <Icon
                                                        name={pd.kind === 'birthday' ? 'gift' : pd.kind === 'anniversary' ? 'heart' : 'calendar'}
                                                        className="ic-xs"
                                                    />
                                                    <span className="pev-title">{pd.title}</span>
                                                </Link>
                                            ))}
                                        {followUps
                                            .filter((f) => f.date === d)
                                            .map((f) => (
                                                <Link
                                                    key={`f${f.id}`}
                                                    className="pev is-date"
                                                    href={`/tasks/waiting?task=${f.id}`}
                                                    title={`Follow up: ${f.title}`}
                                                >
                                                    <Icon name="followUp" className="ic-xs" />
                                                    <span className="pev-title">{f.title}</span>
                                                </Link>
                                            ))}
                                        {markers
                                            .filter((m) => m.date === d && m.kind !== 'habit')
                                            .map((m) => (
                                                <Link
                                                    key={m.key}
                                                    className={`pev is-date is-${m.kind}${m.done ? ' is-done' : ''}`}
                                                    href={m.href}
                                                    title={m.title}
                                                >
                                                    <Icon name={MARKER_ICON[m.kind]} className="ic-xs" />
                                                    <span className="pev-title">{m.title}</span>
                                                </Link>
                                            ))}
                                        {(() => {
                                            // Habits with no set time: one chip a day ("Habits 2/5"), so they don't push the grid down.
                                            const hs = markers.filter((m) => m.date === d && m.kind === 'habit');
                                            if (hs.length === 0) return null;
                                            const done = hs.filter((m) => m.done).length;
                                            return (
                                                <Link
                                                    key={`habits-${d}`}
                                                    className={`pev is-date is-habit${done === hs.length ? ' is-done' : ''}`}
                                                    href="/habits"
                                                    title={hs.map((m) => `${m.done ? '✓ ' : ''}${m.title}`).join('\n')}
                                                    aria-label={`Habits: ${done} of ${hs.length} done`}
                                                >
                                                    <Icon name={done === hs.length ? 'check' : 'habits'} className="ic-xs" />
                                                    <span className="pev-title">
                                                        Habits {done}/{hs.length}
                                                    </span>
                                                </Link>
                                            );
                                        })()}
                                    </div>
                                ))}
                            </div>
                        )}
                        <div className="pg-scroll" ref={scroller}>
                            <div className="pg-body" style={{ height: 24 * HOUR, ['--hour' as string]: `${HOUR}px` }}>
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
                                            // Its size on screen decides its layout: a tall block shows as many title lines
                                            // as fit above the time (never a half-cut line), a short one puts the title and
                                            // time side by side, a tiny one (15–25 min) shows the title alone. A short one is
                                            // drawn at least 26 px tall, but never into the next thing below it.
                                            const h = Math.min(
                                                Math.max(26, ((p.bottom - p.top) / 60) * HOUR - 3),
                                                (roomBelow(pieces[d], p) / 60) * HOUR - 3,
                                            );
                                            const next = p.it.kind === 'block' && p.it.bl.kind === 'project' ? 16 : 0;
                                            const style = {
                                                top: (p.top / 60) * HOUR + 1,
                                                height: h,
                                                left: `calc(${(p.lane / p.lanes) * 100}% + 3px)`,
                                                width: `calc(${100 / p.lanes}% - 6px)`,
                                                // 12 px padding, the 12.5 px time line (15.6) and 1 px gap; a 13.5 px title line is 17.55 px.
                                                ['--lines' as string]: Math.max(1, Math.min(4, Math.floor((h - 29 - next) / 17.55))),
                                            } as CSSProperties;
                                            const short = h < 47;
                                            const tiny = h < 32;
                                            if (p.it.kind === 'block') {
                                                const { bl, s, e } = p.it;
                                                const when = `${minLabel(s.min)}–${minLabel(e.min)}`;
                                                return (
                                                    <button
                                                        key={p.it.key}
                                                        className={`pblock is-${bl.kind}${short ? ' is-short' : ''}${tiny ? ' is-tiny' : ''}${drag?.blockId === bl.id ? ' is-moving' : ''}${bl.id < 0 ? ' is-saving' : ''}${clashes.has(bl.id) ? ' is-clash' : ''}${bl.blocked ? ' is-blocked' : ''}`}
                                                        style={style}
                                                        onPointerDown={(ev) => bl.id > 0 && grabBlock(ev, bl, s)}
                                                        onClick={(ev) => openBlock(ev, bl, s, e)}
                                                        onContextMenu={(ev) => ev.preventDefault()}
                                                        title={[bl.title, when, bl.blocked?.why, clashes.has(bl.id) ? 'overlaps something' : '']
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                        aria-label={`${bl.title}, ${when}${bl.blocked ? `, ${bl.blocked.why}` : ''}${clashes.has(bl.id) ? ', overlaps something' : ''}`}
                                                        aria-haspopup="menu"
                                                    >
                                                        <span className="pblock-title">
                                                            {bl.kind !== 'task' && (
                                                                <Icon name={bl.kind === 'project' ? 'folder' : 'ban'} className="ic-xs pblock-kind" />
                                                            )}
                                                            {bl.rank && <span className="pblock-rank">#{bl.rank}</span>}
                                                            {bl.title}
                                                            {bl.locked ? (
                                                                <Icon name="lock" className="ic-xs pblock-pin" label="Locked" />
                                                            ) : (
                                                                bl.pinned &&
                                                                bl.kind === 'task' && (
                                                                    <Icon name="pin" className="ic-xs pblock-pin" label="Placed by you" />
                                                                )
                                                            )}
                                                        </span>
                                                        <span className="pblock-sub">
                                                            {(clashes.has(bl.id) || bl.blocked) && (
                                                                <Icon name="alert" className="ic-xs pblock-clash" />
                                                            )}
                                                            {bl.blocked && !short ? bl.blocked.why : when}
                                                        </span>
                                                        {bl.kind === 'project' && !short && (
                                                            <span className="pblock-next">
                                                                {bl.next ? `Next: ${bl.next.title}` : 'No open tasks'}
                                                            </span>
                                                        )}
                                                        {bl.id > 0 && (
                                                            <span
                                                                className="pblock-resize"
                                                                aria-hidden="true"
                                                                title="Drag to change the length"
                                                                onPointerDown={(ev) => grabResize(ev, bl, s)}
                                                            />
                                                        )}
                                                    </button>
                                                );
                                            }
                                            if (p.it.kind === 'habit') {
                                                const hb = p.it.hb;
                                                return (
                                                    <button
                                                        key={p.it.key}
                                                        className={`pev is-habit${hb.done ? ' is-done' : ''}${short ? ' is-short' : ''}${tiny ? ' is-tiny' : ''}`}
                                                        style={style}
                                                        onClick={(ev) => {
                                                            const r = ev.currentTarget.getBoundingClientRect();
                                                            setHabitSlot({ hb, x: r.left, y: r.bottom + 4 });
                                                        }}
                                                        aria-label={`Habit: ${hb.title}, ${hhmm(p.top)} to ${hhmm(p.bottom)}${hb.done ? ', done' : ''}`}
                                                    >
                                                        <span className="pev-title">
                                                            <Icon name={hb.done ? 'check' : 'habits'} className="ic-xs" />
                                                            {hb.title}
                                                        </span>
                                                        <span className="pev-sub">
                                                            {clock.time(hb.start)}–{clock.time(hb.end)}
                                                            {hb.target > 1 ? ` · ${hb.count} of ${hb.target}` : ''}
                                                        </span>
                                                    </button>
                                                );
                                            }
                                            const ev = p.it.ev;
                                            const { s: evS, e: evE } = p.it;
                                            const movable = !ev.cancelled;
                                            return (
                                                <button
                                                    key={p.it.key}
                                                    className={`pev is-${ev.calendar}${short ? ' is-short' : ''}${tiny ? ' is-tiny' : ''}${ev.cancelled ? ' is-cancelled' : ''}${movable ? ' is-movable' : ''}${drag?.eventKey === ev.key ? ' is-moving' : ''}`}
                                                    style={style}
                                                    onPointerDown={(e2) => movable && grabEvent(e2, ev, evS, evE)}
                                                    onClick={() => !justDragged.current && openEvent(ev)}
                                                    onContextMenu={(e2) => movable && e2.preventDefault()}
                                                    title={`${ev.title} · ${minLabel(evS.min)}–${minLabel(evE.min)}`}
                                                    aria-label={`${LABEL[ev.calendar]}: ${ev.title}, ${hhmm(p.top)} to ${hhmm(p.bottom)}${ev.cancelled ? ', cancelled' : ''}${ev.locked ? ', locked' : ''}`}
                                                >
                                                    <span className="pev-title">
                                                        <Icon name={ICON[ev.calendar]} className="ic-xs" />
                                                        {ev.title}
                                                        {ev.locked && <Icon name="lock" className="ic-xs pev-lock" label="Locked" />}
                                                    </span>
                                                    <span className="pev-sub">
                                                        {ev.cancelled ? 'Cancelled · ' : ''}
                                                        {minLabel(evS.min)}–{minLabel(evE.min)}
                                                        {ev.location ? ` · ${ev.location}` : ''}
                                                    </span>
                                                    {movable && (
                                                        <span
                                                            className="pblock-resize"
                                                            aria-hidden="true"
                                                            title="Drag to change the length"
                                                            onPointerDown={(e2) => grabEventResize(e2, ev, evS, evE)}
                                                        />
                                                    )}
                                                </button>
                                            );
                                        })}
                                        {dueTasks
                                            .filter((t) => local(clock, t.dueAt).date === d)
                                            .map((t) => {
                                                const m = local(clock, t.dueAt).min;
                                                // On top of a class or booked time: only a small flag at the edge, so it stays readable.
                                                const over = pieces[d].some((p) => p.top < m + 15 && p.bottom > m - 15);
                                                return (
                                                    <a
                                                        key={t.id}
                                                        className={`pg-due${over ? ' is-compact' : ''}`}
                                                        href={`/tasks?task=${t.id}`}
                                                        style={{ top: (m / 60) * HOUR - 10 }}
                                                        title={`Due: ${t.title}`}
                                                        aria-label={`Due: ${t.title}`}
                                                    >
                                                        <Icon name="flag" className="ic-xs" />
                                                        {!over && t.title}
                                                    </a>
                                                );
                                            })}
                                        {drag?.to?.date === d && (
                                            <span
                                                className={`pblock is-ghost${drag.eventKey ? ' is-event' : ''}`}
                                                style={{
                                                    top: (drag.to.min / 60) * HOUR + 1,
                                                    height: Math.max(26, (Math.min(drag.minutes, 1440 - drag.to.min) / 60) * HOUR - 3),
                                                    left: 3,
                                                    right: 3,
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
                )}
            </div>
            {dialog &&
                (dialog.id || newKind === 'event' ? (
                    <EventDialog
                        initial={dialog.initial}
                        eventId={dialog.id}
                        occurrenceDate={dialog.occurrence}
                        day={dialog.day}
                        cancelled={dialog.cancelled}
                        top={dialog.id ? undefined : <KindSeg value="event" onChange={setNewKind} />}
                        onClose={closeDialog}
                    />
                ) : (
                    <TimeDialog
                        kind={newKind}
                        date={dialog.initial.date}
                        start={dialog.initial.start_time ?? '09:00'}
                        end={dialog.initial.end_time ?? '10:00'}
                        top={<KindSeg value={newKind} onChange={setNewKind} />}
                        onClose={closeDialog}
                    />
                ))}
            {hoursOpen && <HoursDialog hours={props.hours} onClose={() => setHoursOpen(false)} />}
            {pop?.kind === 'show' && (
                <Pop x={pop.x} y={pop.y} label="Show on the planner" onClose={() => setPop(null)} width={270}>
                    <div className="bmenu-head">
                        <b>Show</b>
                    </div>
                    {SHOW.map(([k, label, icon]) => (
                        <button
                            key={k}
                            type="button"
                            className="bmenu-item pl-check"
                            role="menuitemcheckbox"
                            aria-checked={on(k)}
                            onClick={() => toggleShow(k)}
                        >
                            <Icon name={icon} />
                            <span className="bmenu-grow">{label}</span>
                            <span className="pl-check-box" aria-hidden="true">
                                {on(k) && <Icon name="check" className="ic-xs" />}
                            </span>
                        </button>
                    ))}
                </Pop>
            )}
            {pop?.kind === 'free' && (
                <Pop x={pop.x} y={pop.y} label="Free time" onClose={() => setPop(null)} width={310}>
                    <FreeTime free={props.free ?? []} clock={clock} />
                </Pop>
            )}
            {habitSlot && (
                <DayEditor
                    habit={{ id: habitSlot.hb.id, name: habitSlot.hb.title, target: habitSlot.hb.target, unit: null }}
                    day={{
                        date: habitSlot.hb.date,
                        state: habitSlot.hb.done ? 'done' : habitSlot.hb.count > 0 ? 'partial' : habitSlot.hb.date > props.today ? 'future' : 'todo',
                        count: habitSlot.hb.count,
                    }}
                    x={habitSlot.x}
                    y={habitSlot.y}
                    clock={clock}
                    openHref={`/habits?habit=${habitSlot.hb.id}`}
                    onClose={() => setHabitSlot(null)}
                />
            )}
            {menu && (
                <BlockMenu
                    block={menu.bl}
                    x={menu.x}
                    y={menu.y}
                    minutes={lengthOf(menu.bl)}
                    when={`${clock.weekday(at(menu.s.date))} ${minLabel(menu.s.min)}–${minLabel(menu.e.min)}`}
                    date={menu.s.date}
                    startMin={menu.s.min}
                    endMin={menu.e.min}
                    choices={props.taskChoices}
                    nextUp={props.unbooked.find((u) => u.taskId !== menu.bl.taskId && u.rank !== null) ?? null}
                    onClose={() => setMenu(null)}
                />
            )}
            {scopeDrop && (
                <Modal title={`Move “${scopeDrop.d.title}”`} onClose={() => setScopeDrop(null)}>
                    <div className="vform">
                        <p className="muted">It happens every week.</p>
                        <div className="vform-foot">
                            <button type="button" className="btn btn-ghost" onClick={() => setScopeDrop(null)}>
                                Cancel
                            </button>
                            <span className="vform-foot-right">
                                <button
                                    type="button"
                                    className="btn btn-secondary"
                                    onClick={() => {
                                        drop(scopeDrop.d, scopeDrop.anyway, 'one');
                                        setScopeDrop(null);
                                    }}
                                >
                                    Only this week
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-primary"
                                    onClick={() => {
                                        drop(scopeDrop.d, scopeDrop.anyway, 'all');
                                        setScopeDrop(null);
                                    }}
                                >
                                    Every week
                                </button>
                            </span>
                        </div>
                    </div>
                </Modal>
            )}
            {lockedDrop && (
                <Modal title={`“${lockedDrop.title}” is locked`} onClose={() => setLockedDrop(null)}>
                    <div className="vform">
                        <p className="muted">{lockedDrop.kind === 'resize' ? 'Change its length anyway?' : 'Move it anyway?'}</p>
                        <div className="vform-foot">
                            <span />
                            <span className="vform-foot-right">
                                <button type="button" className="btn btn-secondary" onClick={() => setLockedDrop(null)}>
                                    Keep it
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-primary"
                                    onClick={() => {
                                        drop(lockedDrop, true);
                                        setLockedDrop(null);
                                    }}
                                >
                                    {lockedDrop.kind === 'resize' ? 'Change anyway' : 'Move anyway'}
                                </button>
                            </span>
                        </div>
                    </div>
                </Modal>
            )}
        </VelocityShell>
    );
}

/** Free time in working hours for the days shown (around events, habit times and booked blocks), and Copy to send it to someone. */
function FreeTime({ free, clock }: { free: Props['free']; clock: Clock }) {
    const [copied, setCopied] = useState(false);
    const total = free.reduce((n, d) => n + d.minutes, 0);
    const dayName = (d: string) => clock.date(at(d), { weekday: 'short', day: 'numeric', month: 'short' });
    const slots = (d: Props['free'][number]) => d.slots.map((s) => `${clock.time(s.start)} – ${clock.time(s.end)}`).join(', ');
    const copy = async () => {
        const text = free.map((d) => `${dayName(d.date)}: ${d.slots.length ? slots(d) : 'no free time'}`).join('\n');
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            const box = document.createElement('textarea');
            box.value = text;
            document.body.appendChild(box);
            box.select();
            document.execCommand('copy');
            box.remove();
        }
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };
    return (
        <>
            <div className="bmenu-head">
                <b>Free time</b>
                <span>{total ? `${fmtHours(total / 60)} in working hours` : 'None in working hours'}</span>
            </div>
            <ul className="free-list pl-free">
                {free.map((d) => (
                    <li key={d.date}>
                        <b>{dayName(d.date)}</b>
                        <span>{d.slots.length ? slots(d) : 'None'}</span>
                    </li>
                ))}
            </ul>
            <div className="bmenu-sep" />
            <button type="button" className="bmenu-item" onClick={copy}>
                <Icon name={copied ? 'check' : 'copy'} />
                <span className="bmenu-grow">{copied ? 'Copied' : 'Copy free time'}</span>
            </button>
        </>
    );
}

/**
 * The Planner's side menu: a month calendar and what is not booked yet. (Day / Week / Month, Show, Free time,
 * Working hours and New event are in the page's header; the calendar filters went, 23 Nov 2026.)
 */
function PlannerMenu({
    props,
    clock,
    onBook,
    onGrab,
    go,
}: {
    props: Props;
    clock: Clock;
    onBook: (taskId: number) => void;
    onGrab: (e: ReactPointerEvent<HTMLElement>, u: Unbooked) => void;
    go: (date: string, view?: View) => void;
}) {
    const first = `${props.date.slice(0, 8)}01`;
    const monthStart = new Date(`${first}T12:00:00Z`);
    const lead = (monthStart.getUTCDay() + 6) % 7; // Monday first
    const cells = Array.from({ length: 42 }, (_, i) => shift(first, i - lead));
    const month = props.date.slice(0, 7);
    const busy = new Set(props.busy);
    // The month view shows the whole month: no need to mark its days.
    const inView = new Set(props.view === 'month' ? [] : props.days);
    return (
        <>
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
