import { Clock, dueInfo, fmtHours, goalVar } from '@/lib/velocity';
import type { NotReadyTask, RankedTask } from '@/types/velocity';
import { Link, router } from '@inertiajs/react';
import { type CSSProperties, type DragEvent, type FocusEvent, Fragment, type MouseEvent, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Icon } from './icon';

/*
 * The Timeline: the tasks laid out along time, in the Goals map's look. Each goal is a line with dots
 * flowing along it, a violet line marks now, the #1 task glows, and the landmarks of the year
 * (milestones, project due dates, goal targets) sit above the lines. Five spans, each drawn its own way:
 * 2 weeks (cards by day), 2 and 3 months (stations by week), 6 months (beads by week, with hills of the
 * work due) and 1 year (a ring). Every task sits in its own cell and stacks there, and landmark labels
 * take the first free row, so nothing overlaps. Dragging a task changes its due date, never its rank.
 */

export interface TimelineMark {
    key: string;
    date: string;
    kind: 'milestone' | 'project' | 'goal';
    title: string;
    href: string;
    goalId: number | null;
}
export interface TimelineData {
    goals: { id: number; title: string; color: number }[];
    marks: TimelineMark[];
}

type Item = (RankedTask & { ready: true }) | (NotReadyTask & { ready: false });
type Lane = { key: string; title: string; color: number | null; goalId?: number };
type Mark = TimelineMark & { d: number; color: number | null };
type Span = '2w' | '2m' | '3m' | '6m' | '1y';
type GridSpan = Exclude<Span, '1y'>;

type Props = {
    tasks: RankedTask[];
    notReady: NotReadyTask[];
    clock: Clock;
    selectedId: number | null;
    onOpen: (id: number) => void;
    data?: TimelineData | null;
    /** On a project's page: one line, named after the project. */
    single?: { name: string; color: number | null };
};

const SPANS: [Span, string][] = [
    ['2w', '2 weeks'],
    ['2m', '2 months'],
    ['3m', '3 months'],
    ['6m', '6 months'],
    ['1y', '1 year'],
];
/** Column widths (px), where the goal line runs in a lane, and how many weeks a span shows. */
const SIZE: Record<GridSpan, { col: number; edge: number; track: number; weeks: number }> = {
    '2w': { col: 128, edge: 128, track: 30, weeks: 0 },
    '2m': { col: 150, edge: 128, track: 24, weeks: 9 },
    '3m': { col: 84, edge: 92, track: 24, weeks: 13 },
    '6m': { col: 34, edge: 76, track: 19, weeks: 26 },
};

const keep = { preserveScroll: true, preserveState: true };
const KIND_ICON: Record<string, string> = { waiting: 'hourglass', blocked: 'lock', later: 'calendar', held: 'pause' };
const MARK_NAME: Record<TimelineMark['kind'], string> = { milestone: 'Milestone', project: 'Project due', goal: 'Goal target' };
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const LONG = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const WD = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const css = (o: Record<string, string | number | undefined>) => o as CSSProperties;
const DAY = 864e5;
const utc = (day: string) => Date.parse(`${day}T12:00:00Z`);
const addDays = (day: string, n: number) => new Date(utc(day) + n * DAY).toISOString().slice(0, 10);
const between = (a: string, b: string) => Math.round((utc(b) - utc(a)) / DAY);
const weekday = (day: string) => new Date(utc(day)).getUTCDay();
const fmtDay = (day: string) => `${Number(day.slice(8, 10))} ${MONTHS[Number(day.slice(5, 7)) - 1]}`;
const monthName = (key: string, long = false) => {
    const m = Number(key.slice(5, 7));
    return `${(long ? LONG : MONTHS)[m - 1]}${long || m === 1 ? ` ${key.slice(0, 4)}` : ''}`;
};
const nextMonth = (first: string) => {
    const y = Number(first.slice(0, 4));
    const m = Number(first.slice(5, 7));
    return m === 12 ? `${y + 1}-01-01` : `${y}-${String(m + 1).padStart(2, '0')}-01`;
};
const shortH = (h: number) => `${Math.round(h * 10) / 10} h`;

type Grab = {
    draggable: true;
    onDragStart: (e: DragEvent<HTMLElement>) => void;
    onDragEnd: () => void;
    onClick: () => void;
};
type Hover = {
    onMouseEnter: (e: MouseEvent<Element>) => void;
    onMouseLeave: () => void;
    onFocus: (e: FocusEvent<Element>) => void;
    onBlur: () => void;
};
type Ctx = {
    clock: Clock;
    today: string;
    selectedId: number | null;
    drag: number | null;
    open: (id: number) => void;
    grab: (t: Item) => Grab;
    hover: (t: Item) => Hover;
    late: (t: Item) => boolean;
    off: (t: Item) => number | null;
    lane: (t: Item) => string;
    /** Give the dragged task a new due day (null = no date). */
    move: (day: (t: Item) => string | null) => void;
    /** The same weekday in the week starting on `monday` (a task with no date: the Friday), never before today. */
    inWeek: (t: Item, monday: string) => string;
    /** The same day of the month in the month starting on `first` (no date: the month's last day), never before today. */
    inMonth: (t: Item, first: string) => string;
};

/** The goal lines: goals with dated tasks, in the goals' own order, then "No goal". */
function laneList(dated: Item[], lane: (t: Item) => string, data: TimelineData | null | undefined, single: Props['single']): Lane[] {
    if (single) return [{ key: 's', title: single.name, color: single.color }];
    const used = new Set(dated.map(lane));
    const goals = data?.goals ?? [];
    const out: Lane[] = goals.filter((g) => used.has(`g${g.id}`)).map((g) => ({ key: `g${g.id}`, title: g.title, color: g.color, goalId: g.id }));
    for (const t of dated) {
        if (t.goal && !out.some((l) => l.key === `g${t.goal!.id}`))
            out.push({ key: `g${t.goal.id}`, title: t.goal.title, color: t.goal.color, goalId: t.goal.id });
    }
    if (used.has('none')) out.push({ key: 'none', title: 'No goal', color: null });
    if (out.length) return out;
    return goals.length
        ? goals.map((g) => ({ key: `g${g.id}`, title: g.title, color: g.color, goalId: g.id }))
        : [{ key: 'none', title: 'No goal', color: null }];
}

export function Timeline(props: Props) {
    const { clock } = props;
    const [span, setSpanState] = useState<Span>(() => {
        const saved = typeof localStorage !== 'undefined' ? localStorage.getItem('velocity.timeline') : null;
        return SPANS.some(([k]) => k === saved) ? (saved as Span) : '2w';
    });
    const setSpan = (s: Span) => {
        setSpanState(s);
        localStorage.setItem('velocity.timeline', s);
    };
    const [drag, setDrag] = useState<number | null>(null);
    const [tip, setTip] = useState<{ t: Item; r: DOMRect } | null>(null);

    const now = clock.localInput(clock.now.toISOString());
    const today = now.slice(0, 10);
    const nowFrac = (Number(now.slice(11, 13)) * 60 + Number(now.slice(14, 16))) / 1440;
    const all: Item[] = [
        ...props.tasks.map((t) => ({ ...t, ready: true as const })),
        ...props.notReady.map((t) => ({ ...t, ready: false as const })),
    ];
    const off = (t: Item) => (t.dueAt ? clock.dayDiff(t.dueAt) : null);
    const late = (t: Item) => !!t.dueAt && (dueInfo(t, clock).late || (off(t) ?? 0) < 0);
    const lane = (t: Item) => (props.single ? 's' : t.goal ? `g${t.goal.id}` : 'none');
    const dated = all.filter((t) => t.dueAt);
    const lanes = laneList(dated, lane, props.data, props.single);
    const colorOf = new Map((props.data?.goals ?? []).map((g) => [g.id, g.color]));
    const marks: Mark[] = (props.data?.marks ?? []).map((m) => ({
        ...m,
        d: between(today, m.date),
        color: props.single ? props.single.color : m.goalId ? (colorOf.get(m.goalId) ?? null) : null,
    }));
    const localDay = (t: Item) => (t.dueAt ? clock.localInput(t.dueAt).slice(0, 10) : null);

    const ctx: Ctx = {
        clock,
        today,
        selectedId: props.selectedId,
        drag,
        open: props.onOpen,
        grab: (t) => ({
            draggable: true,
            onDragStart: (e) => {
                e.dataTransfer.setData('text/plain', String(t.id));
                e.dataTransfer.effectAllowed = 'move';
                setTip(null);
                setDrag(t.id);
            },
            onDragEnd: () => setDrag(null),
            onClick: () => props.onOpen(t.id),
        }),
        hover: (t) => ({
            onMouseEnter: (e) => drag === null && setTip({ t, r: e.currentTarget.getBoundingClientRect() }),
            onMouseLeave: () => setTip(null),
            onFocus: (e) => setTip({ t, r: e.currentTarget.getBoundingClientRect() }),
            onBlur: () => setTip(null),
        }),
        late,
        off,
        lane,
        move: (pick) => {
            const t = all.find((x) => x.id === drag);
            setDrag(null);
            if (!t) return;
            const day = pick(t);
            // Keep the time of day it already had; a task with no time gets the end of the day.
            const time = t.dueAt ? clock.localInput(t.dueAt).slice(11) : '23:59';
            const due = day === null ? null : `${day}T${time}`;
            if (due !== (t.dueAt ? clock.localInput(t.dueAt) : null)) router.patch(route('tasks.update', t.id), { due_at: due }, keep);
        },
        inWeek: (t, monday) => {
            const had = localDay(t);
            const day = addDays(monday, had ? (weekday(had) + 6) % 7 : 4);
            return day < today ? today : day;
        },
        inMonth: (t, first) => {
            const had = localDay(t);
            const days = new Date(Date.UTC(Number(first.slice(0, 4)), Number(first.slice(5, 7)), 0)).getUTCDate();
            const day = `${first.slice(0, 8)}${String(Math.min(had ? Number(had.slice(8, 10)) : days, days)).padStart(2, '0')}`;
            return day < today ? today : day;
        },
    };

    return (
        <div className="vt-wrap">
            <div className="seg vt-spans" role="radiogroup" aria-label="How far ahead">
                {SPANS.map(([k, label]) => (
                    <button key={k} type="button" role="radio" aria-checked={span === k} onClick={() => setSpan(k)}>
                        {label}
                    </button>
                ))}
            </div>
            <div className={`vt is-${span}`}>
                {span === '1y' ? (
                    <YearRing ctx={ctx} all={all} lanes={lanes} marks={marks} />
                ) : (
                    <SpanGrid key={span} span={span} ctx={ctx} all={all} lanes={lanes} marks={marks} nowFrac={nowFrac} onSpan={setSpan} />
                )}
                <Dock ctx={ctx} list={all.filter((t) => !t.dueAt)} />
            </div>
            {tip && <TipBox tip={tip} clock={clock} />}
        </div>
    );
}

/* ——— The spans drawn as a grid: 2 weeks, 2 months, 3 months, 6 months ——— */

type Col = {
    from: number;
    to: number;
    start: string;
    kind: 'day' | 'week';
    label: string;
    sub?: string;
    month: string;
    weekend?: boolean;
    now: number | null;
};

/** The days or weeks a span shows, as day counts from today ([from, to)). */
function buckets(span: GridSpan, today: string, nowFrac: number): Col[] {
    if (span === '2w') {
        return Array.from({ length: 14 }, (_, i) => {
            const day = addDays(today, i);
            const wd = weekday(day);
            return {
                from: i,
                to: i + 1,
                start: day,
                kind: 'day' as const,
                label: i === 0 ? 'Today' : i === 1 ? 'Tomorrow' : WD[wd],
                sub: fmtDay(day),
                month: day.slice(0, 7),
                weekend: wd === 0 || wd === 6,
                now: i === 0 ? nowFrac : null,
            };
        });
    }
    // Weeks start on Monday; week 0 is this one. A week belongs to the month its Thursday is in.
    const sinceMonday = (weekday(today) + 6) % 7;
    return Array.from({ length: SIZE[span].weeks }, (_, w) => {
        const monday = addDays(today, 7 * w - sinceMonday);
        return {
            from: 7 * w - sinceMonday,
            to: 7 * w - sinceMonday + 7,
            start: monday,
            kind: 'week' as const,
            label: span !== '2m' ? String(Number(monday.slice(8, 10))) : w === 0 ? 'This week' : w === 1 ? 'Next week' : fmtDay(monday),
            sub: span === '2m' ? `${fmtDay(monday)} – ${fmtDay(addDays(monday, 6))}` : undefined,
            month: addDays(monday, 3).slice(0, 7),
            now: w === 0 ? (sinceMonday + nowFrac) / 7 : null,
        };
    });
}

/** Landmarks in rows: each takes the first row that is free where it starts, so labels never overlap. */
function placeMarks(marks: Mark[], cols: Col[], colW: number) {
    const n = cols.length;
    const ends: number[] = [];
    const take = (c: number, e: number) => {
        let r = ends.findIndex((end) => end <= c);
        if (r < 0) r = ends.push(0) - 1;
        ends[r] = e;
        return r;
    };
    const placed: { m: Mark; c: number; e: number; r: number }[] = [];
    const later: Mark[] = [];
    for (const m of marks) {
        if (m.d < 0) continue;
        const i = cols.findIndex((c) => m.d >= c.from && m.d < c.to);
        if (i < 0) {
            later.push(m);
            continue;
        }
        const c = i + 3; // grid column: 1 names, 2 overdue, 3… the days or weeks
        const wide = Math.max(1, Math.min(Math.ceil((34 + m.title.length * 6.8) / colW), n - i));
        placed.push({ m, c, e: c + wide, r: take(c, c + wide) });
    }
    const laterRow = later.length ? take(n + 3, n + 4) : -1;
    return { placed, later, laterRow, rows: ends.length };
}

function SpanGrid({
    span,
    ctx,
    all,
    lanes,
    marks,
    nowFrac,
    onSpan,
}: {
    span: GridSpan;
    ctx: Ctx;
    all: Item[];
    lanes: Lane[];
    marks: Mark[];
    nowFrac: number;
    onSpan: (s: Span) => void;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [over, setOver] = useState<number | null>(null);
    const { col, edge, track } = SIZE[span];
    const cols = buckets(span, ctx.today, nowFrac);
    const n = cols.length;
    const laterCol = n + 3;

    // Where each task goes: 0 = overdue, 1…n = a day or week, n + 1 = later.
    const slot = (t: Item) => {
        if (ctx.late(t)) return 0;
        const d = ctx.off(t) ?? 0;
        const i = cols.findIndex((c) => d >= c.from && d < c.to);
        return i >= 0 ? i + 1 : n + 1;
    };
    const cells = new Map(lanes.map((l) => [l.key, Array.from({ length: n + 2 }, () => [] as Item[])]));
    const load = Array<number>(n + 2).fill(0);
    const count = Array<number>(n + 2).fill(0);
    for (const t of all) {
        if (!t.dueAt) continue;
        const s = slot(t);
        cells.get(ctx.lane(t))?.[s].push(t);
        load[s] += t.estHours;
        count[s]++;
    }

    const tight = span === '3m' || span === '6m';
    const lm = placeMarks(marks, cols, col);
    let row = 1;
    const monthRow = tight ? row++ : 0;
    const headRow = row++;
    const topRow = monthRow || headRow;
    const lmRow = row;
    row += lm.rows;
    const laneRow = row;
    row += lanes.length;
    const laneEnd = row;
    const hillsRow = span === '6m' ? row++ : 0;
    const bandRows = `${topRow} / ${laneEnd}`;

    const nowAt = cols.findIndex((c) => c.now !== null);
    const nowCol = nowAt + 3;
    const flowPx = (n - nowAt) * col + edge;
    const flowDur = Math.round(flowPx / 50);
    const flowDots = Math.max(2, Math.round(flowPx / 260));
    const edgeW = (has: boolean) => (has ? edge : Math.min(edge, 76));
    const template = `var(--tl-label) ${edgeW(count[0] > 0)}px repeat(${n}, minmax(${col}px, 1fr)) ${edgeW(count[n + 1] > 0 || lm.later.length > 0)}px`;

    // Months over the weeks (3 and 6 months).
    const months: { key: string; a: number; b: number }[] = [];
    cols.forEach((c, i) => {
        const last = months[months.length - 1];
        if (last && last.key === c.month) last.b = i + 4;
        else months.push({ key: c.month, a: i + 3, b: i + 4 });
    });

    // Which day or week the pointer is over (the column headers mark the columns).
    const colAt = (x: number) => {
        for (const el of Array.from(ref.current?.querySelectorAll<HTMLElement>('[data-col]') ?? [])) {
            const r = el.getBoundingClientRect();
            if (x >= r.left && x < r.right) return Number(el.dataset.col);
        }
        return null;
    };
    const target = (e: DragEvent) => {
        if (ctx.drag === null) return null;
        const c = colAt(e.clientX);
        return c !== null && c >= 3 && c < laterCol ? c : null;
    };

    const nearestLater = lm.later[0];
    const laterSpan = nearestLater
        ? (['2m', '3m', '6m', '1y'] as Span[]).find((s) => s === '1y' || nearestLater.d < SIZE[s as GridSpan].weeks * 7 - 7)
        : undefined;

    return (
        <div className="vt-scroll">
            <div
                ref={ref}
                className="vt-grid"
                style={css({ gridTemplateColumns: template, '--tl-track': `${track}px` })}
                role="region"
                aria-label={span === '2w' ? 'Tasks by day' : 'Tasks by week'}
                onDragOver={(e) => {
                    const c = target(e);
                    if (c === null) return;
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    if (over !== c) setOver(c);
                }}
                onDragLeave={(e) => !e.currentTarget.contains(e.relatedTarget as Node) && setOver(null)}
                onDrop={(e) => {
                    const c = target(e);
                    setOver(null);
                    if (c === null) return;
                    e.preventDefault();
                    const b = cols[c - 3];
                    ctx.move((t) => (b.kind === 'day' ? b.start : ctx.inWeek(t, b.start)));
                }}
            >
                {/* Backgrounds: overdue, weekends or every other month, and the column a task is dragged over. */}
                <div className="vt-band is-late" style={{ gridColumn: 2, gridRow: bandRows }} />
                {span === '2w' &&
                    cols.map((c, i) =>
                        c.weekend || c.now !== null ? (
                            <div
                                key={c.start}
                                className={`vt-band${c.now !== null ? ' is-today' : ' is-weekend'}`}
                                style={{ gridColumn: i + 3, gridRow: bandRows }}
                            />
                        ) : null,
                    )}
                {tight &&
                    months.map((m, i) =>
                        i % 2 ? (
                            <div
                                key={m.key}
                                className="vt-band is-alt"
                                style={{ gridColumn: `${m.a} / ${m.b}`, gridRow: `${topRow} / ${hillsRow ? hillsRow + 1 : laneEnd}` }}
                            />
                        ) : null,
                    )}
                {over !== null && <div className="vt-band is-over" style={{ gridColumn: over, gridRow: bandRows }} />}

                {/* Headers: months (3 and 6 months), then the days or weeks. */}
                <div className="vt-side-cell vt-corner" style={{ gridColumn: 1, gridRow: `${topRow} / ${headRow + 1}` }} />
                {tight &&
                    months.map((m) => (
                        <div key={m.key} className="vt-month" style={{ gridColumn: `${m.a} / ${m.b}`, gridRow: monthRow }}>
                            {m.b - m.a > 1 || span === '3m' ? monthName(m.key) : ''}
                        </div>
                    ))}
                <div className="vt-head is-late" data-col={2} style={{ gridColumn: 2, gridRow: `${topRow} / ${headRow + 1}` }}>
                    <b>Overdue</b>
                    <span>{count[0] || '–'}</span>
                </div>
                {cols.map((c, i) => {
                    const h = load[i + 1];
                    return (
                        <div
                            key={c.start}
                            className={`vt-head${tight ? ' is-tight' : ''}${c.now !== null ? ' is-now' : ''}`}
                            data-col={i + 3}
                            style={{ gridColumn: i + 3, gridRow: headRow }}
                            title={h ? `${fmtHours(h)} due` : undefined}
                        >
                            <b>{c.label}</b>
                            {c.sub && <span>{c.sub}</span>}
                            {!tight && (
                                <span className={`vt-load${h > (c.kind === 'day' ? 6 : 30) ? ' is-heavy' : ''}`}>
                                    <i style={{ width: `${Math.min(100, (h / (c.kind === 'day' ? 8 : 40)) * 100)}%` }} />
                                </span>
                            )}
                        </div>
                    );
                })}
                <div className="vt-head is-later" data-col={laterCol} style={{ gridColumn: laterCol, gridRow: `${topRow} / ${headRow + 1}` }}>
                    <b>Later</b>
                    <span>{count[n + 1] || '–'}</span>
                </div>

                {/* Landmarks: milestones, project due dates and goal targets. */}
                {lm.rows > 0 && (
                    <div className="vt-side-cell vt-side-label" style={{ gridColumn: 1, gridRow: `${lmRow} / ${lmRow + lm.rows}` }}>
                        Landmarks
                    </div>
                )}
                {lm.placed.map(({ m, c, e, r }) => (
                    <MarkChip key={m.key} m={m} style={css({ gridColumn: `${c} / ${e}`, gridRow: lmRow + r, '--g': goalVar(m.color) })} />
                ))}
                {lm.later.length > 0 && (
                    <button
                        type="button"
                        className="vt-mark is-more"
                        style={{ gridColumn: laterCol, gridRow: lmRow + lm.laterRow }}
                        title={lm.later.map((m) => `${fmtDay(m.date)} · ${m.title}`).join('\n')}
                        onClick={() => laterSpan && onSpan(laterSpan)}
                    >
                        <Icon name="flag" className="ic-xs" />
                        <span>{lm.later.length} more</span>
                    </button>
                )}

                {/* The goal lines, each with its tasks. */}
                {lanes.map((l, li) => {
                    const r = laneRow + li;
                    const g = goalVar(l.color);
                    const total = cells.get(l.key)?.reduce((s, list) => s + list.length, 0) ?? 0;
                    return (
                        <Fragment key={l.key}>
                            {li > 0 && <div className="vt-lane-sep" style={{ gridColumn: '1 / -1', gridRow: r }} />}
                            <div className="vt-track" style={css({ gridColumn: '2 / -1', gridRow: r, '--g': g })} />
                            <div className="vt-flow" style={css({ gridColumn: `${nowCol} / -1`, gridRow: r, '--g': g, '--dur': `${flowDur}s` })}>
                                {Array.from({ length: flowDots }, (_, k) => (
                                    <i key={k} style={{ animationDelay: `${(-k * flowDur) / flowDots}s` }} />
                                ))}
                            </div>
                            <div className="vt-side-cell vt-lane" style={css({ gridColumn: 1, gridRow: r, '--g': g })}>
                                <i />
                                <span>
                                    {l.goalId ? (
                                        <Link href={`/goals?focus=g${l.goalId}`} className="vt-lane-t" title={`Open “${l.title}” on the Goals map`}>
                                            {l.title}
                                        </Link>
                                    ) : (
                                        <span className="vt-lane-t">{l.title}</span>
                                    )}
                                    <small>{total || '–'}</small>
                                </span>
                            </div>
                            {cells
                                .get(l.key)
                                ?.map((list, s) =>
                                    list.length ? (
                                        <Cell key={s} span={span} list={list} ctx={ctx} style={css({ gridColumn: s + 2, gridRow: r, '--g': g })} />
                                    ) : null,
                                )}
                        </Fragment>
                    );
                })}

                {/* Now: a glowing line through today (or this week). */}
                <div
                    className="vt-now"
                    style={css({
                        gridColumn: nowCol,
                        gridRow: `${lmRow} / ${hillsRow ? hillsRow + 1 : laneEnd}`,
                        '--x': `${(cols[nowAt].now ?? 0) * 100}%`,
                    })}
                >
                    <i>
                        <b />
                        <b />
                    </i>
                </div>

                {/* 6 months: hills of the work due each week. */}
                {hillsRow > 0 && (
                    <>
                        <div className="vt-side-cell vt-side-label is-hills" style={{ gridColumn: 1, gridRow: hillsRow }}>
                            Work due
                            <small>per week</small>
                        </div>
                        <Hills load={load.slice(1, n + 1)} cols={cols} style={{ gridColumn: `3 / ${laterCol}`, gridRow: hillsRow }} />
                    </>
                )}
            </div>
        </div>
    );
}

/** One lane's tasks in one day or week: cards (2 weeks), stations (2 and 3 months) or beads (6 months). */
function Cell({ span, list, ctx, style }: { span: GridSpan; list: Item[]; ctx: Ctx; style: CSSProperties }) {
    if (span === '2w') {
        return (
            <div className="vt-cell" style={style}>
                {list.map((t) => (
                    <TaskCard key={t.id} t={t} ctx={ctx} />
                ))}
            </div>
        );
    }
    if (span === '6m') {
        return (
            <div className="vt-cell is-beads" style={style}>
                {list.map((t) => (
                    <button
                        key={t.id}
                        type="button"
                        className={`vt-bead${t.ready ? '' : ' is-quiet'}${t.ready && t.rank === 1 ? ' is-first' : ''}${ctx.selectedId === t.id ? ' is-selected' : ''}`}
                        aria-label={`${t.ready ? `#${t.rank}, ` : ''}${t.title}`}
                        {...ctx.grab(t)}
                        {...ctx.hover(t)}
                    />
                ))}
            </div>
        );
    }
    // Stations: in 2 months each with its name tag; in 3 months the weeks are too narrow for names, so the
    // stations sit side by side and the name shows on hover.
    const named = span === '2m' ? list : [];
    const rest = span === '2m' ? [] : list;
    const rows = named.length + (rest.length ? 1 : 0);
    return (
        <div className={`vt-cell is-stops${rows > 1 ? ' is-branch' : ''}`} style={style}>
            {named.map((t) => (
                <Stop key={t.id} t={t} ctx={ctx} named />
            ))}
            {rest.length > 0 && (
                <div className="vt-dots">
                    {rest.map((t) => (
                        <Stop key={t.id} t={t} ctx={ctx} />
                    ))}
                </div>
            )}
        </div>
    );
}

/** Its rank in the goal's colour, or why it can't start yet. */
function Rank({ t }: { t: Item }) {
    return t.ready ? (
        <span className={`vt-rank${t.rank === 1 ? ' is-first' : ''}`}>{t.rank}</span>
    ) : (
        <span className="vt-rank is-icon" title={t.why}>
            <Icon name={KIND_ICON[t.kind] ?? 'info'} />
        </span>
    );
}

function TaskCard({ t, ctx }: { t: Item; ctx: Ctx }) {
    const first = t.ready && t.rank === 1;
    return (
        <div
            className={`vt-card${ctx.selectedId === t.id ? ' is-selected' : ''}${first ? ' is-first' : ''}${t.ready ? '' : ' is-quiet'}`}
            role="button"
            tabIndex={0}
            aria-label={`${t.ready ? `#${t.rank}, ` : ''}${t.title}`}
            {...ctx.grab(t)}
            onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), ctx.open(t.id))}
        >
            <div className="vt-card-top">
                <Rank t={t} />
                <span className="vt-card-title">{t.title}</span>
            </div>
            <div className="vt-card-meta">
                <span>{t.project?.name ?? (t.ready ? '' : t.why)}</span>
                <span>{fmtHours(t.estHours)}</span>
            </div>
        </div>
    );
}

function Stop({ t, ctx, named = false }: { t: Item; ctx: Ctx; named?: boolean }) {
    return (
        <button
            type="button"
            className={`vt-stop${t.ready ? '' : ' is-quiet'}${t.ready && t.rank === 1 ? ' is-first' : ''}${ctx.selectedId === t.id ? ' is-selected' : ''}`}
            aria-label={`${t.ready ? `#${t.rank}, ` : ''}${t.title}`}
            {...ctx.grab(t)}
            {...ctx.hover(t)}
        >
            <span className="vt-dot">{t.ready ? t.rank : <Icon name={KIND_ICON[t.kind] ?? 'info'} />}</span>
            {named && <span className="vt-name">{t.title}</span>}
        </button>
    );
}

function MarkIcon({ kind }: { kind: TimelineMark['kind'] }) {
    return kind === 'milestone' ? (
        <i className="vt-mi" aria-hidden="true" />
    ) : (
        <Icon name={kind === 'project' ? 'flag' : 'target'} className="ic-xs" />
    );
}

function MarkChip({ m, style }: { m: Mark; style: CSSProperties }) {
    return (
        <Link href={m.href} className={`vt-mark is-${m.kind}`} style={style} title={`${MARK_NAME[m.kind]} · ${fmtDay(m.date)}\n${m.title}`}>
            <MarkIcon kind={m.kind} />
            <span>{m.title}</span>
        </Link>
    );
}

/** The work due each week as a line of hills: busy weeks are peaks. */
function Hills({ load, cols, style }: { load: number[]; cols: Col[]; style: CSSProperties }) {
    const n = load.length;
    const max = Math.max(4, ...load);
    const pts = load.map((h, i) => [i * 10 + 5, 46 - (h / max) * 38] as const);
    let line = `M0 ${pts[0][1]} L${pts[0][0]} ${pts[0][1]}`;
    for (let i = 1; i < n; i++) {
        const [x0, y0] = pts[i - 1];
        const [x1, y1] = pts[i];
        const mx = (x0 + x1) / 2;
        line += ` C${mx} ${y0} ${mx} ${y1} ${x1} ${y1}`;
    }
    line += ` L${n * 10} ${pts[n - 1][1]}`;
    return (
        <div className="vt-hills" style={style}>
            <svg viewBox={`0 0 ${n * 10} 48`} preserveAspectRatio="none" aria-hidden="true">
                <path className="vt-hill-area" d={`${line} L${n * 10} 48 L0 48 Z`} />
                <path className="vt-hill-line" d={line} vectorEffect="non-scaling-stroke" />
                {load.map((h, i) => (
                    <rect key={i} className="vt-hill-hit" x={i * 10} y="0" width="10" height="48">
                        <title>{`Week of ${fmtDay(cols[i].start)}: ${h ? fmtHours(h) : 'nothing'} due`}</title>
                    </rect>
                ))}
            </svg>
        </div>
    );
}

/* ——— 1 year: a ring ——— */

const RC = 270; // centre of the 540 × 540 drawing
const R_OUT = 196; // the outer goal ring
const R_IN = 104; // the innermost a goal ring may go
const R_MARK = 222; // landmarks
const R_SECTOR = 238; // the months' clickable wedges
const R_MONTH = 252; // month names

type Sel = { kind: 'month'; i: number } | { kind: 'group'; ids: number[]; label: string } | null;

function yearMonths(today: string) {
    const out: { key: string; first: string; from: number; to: number }[] = [];
    let first = `${today.slice(0, 7)}-01`;
    for (;;) {
        const from = Math.max(0, between(today, first));
        if (from >= 365) break;
        const next = nextMonth(first);
        out.push({ key: first.slice(0, 7), first, from, to: Math.min(365, between(today, next)) });
        first = next;
    }
    return out;
}

/** The year runs clockwise from the hand at the top, leaving a small gap either side of it so nothing sits on the hand. */
const GAP = 6 / 360;
const turn = (d: number) => GAP + (d / 365) * (1 - 2 * GAP);
const pt = (r: number, d: number): [number, number] => {
    const a = turn(d) * 2 * Math.PI;
    return [RC + r * Math.sin(a), RC - r * Math.cos(a)];
};
const f1 = (v: number) => Math.round(v * 10) / 10;
function wedge(r1: number, r2: number, d0: number, d1: number) {
    const [ax, ay] = pt(r2, d0);
    const [bx, by] = pt(r2, d1);
    const [cx, cy] = pt(r1, d1);
    const [dx, dy] = pt(r1, d0);
    const large = turn(d1) - turn(d0) > 0.5 ? 1 : 0;
    return `M${f1(ax)} ${f1(ay)} A${r2} ${r2} 0 ${large} 1 ${f1(bx)} ${f1(by)} L${f1(cx)} ${f1(cy)} A${r1} ${r1} 0 ${large} 0 ${f1(dx)} ${f1(dy)}Z`;
}

/** Items close together on a ring share one spot (a count), so dots never sit on each other. */
function groupOnRing<T>(list: T[], day: (x: T) => number, r: number, size: number) {
    const slots = Math.max(12, Math.floor((2 * Math.PI * r * (1 - 2 * GAP)) / size));
    const groups = new Map<number, T[]>();
    for (const x of list) {
        const k = Math.min(slots - 1, Math.floor((day(x) / 365) * slots));
        groups.set(k, [...(groups.get(k) ?? []), x]);
    }
    return [...groups].map(([k, items]) => ({ k, d: ((k + 0.5) / slots) * 365, items }));
}

function YearRing({ ctx, all, lanes, marks }: { ctx: Ctx; all: Item[]; lanes: Lane[]; marks: Mark[] }) {
    const [sel, setSel] = useState<Sel>(null);
    const [over, setOver] = useState<number | null>(null);
    const svg = useRef<SVGSVGElement>(null);
    const months = yearMonths(ctx.today);
    const day = (t: Item) => ctx.off(t) ?? 0;
    const onRing = all.filter((t) => t.dueAt && !ctx.late(t) && day(t) < 365);
    const overdue = all.filter((t) => ctx.late(t));
    const later = all.filter((t) => t.dueAt && !ctx.late(t) && day(t) >= 365);
    const yearMarks = marks.filter((m) => m.d >= 0 && m.d < 365);
    const step = lanes.length > 1 ? Math.min(34, (R_OUT - R_IN) / (lanes.length - 1)) : 0;
    const dotR = lanes.length > 1 ? Math.min(9, Math.max(6, step / 2 - 1)) : 9;
    const radius = (i: number) => R_OUT - i * step;
    const monthOf = (d: number) => months.findIndex((m) => d >= m.from && d < m.to);
    const hours = months.map((m) => onRing.filter((t) => day(t) >= m.from && day(t) < m.to).reduce((s, t) => s + t.estHours, 0));
    const counts = months.map((m) => onRing.filter((t) => day(t) >= m.from && day(t) < m.to).length);
    const maxH = Math.max(1, ...hours);
    // A ring that started mid-month ends with the same month a year on: name that one with its year, and on
    // the ring only name it when the first one was too short to carry a name (they sit side by side).
    const wraps = months.length > 12;
    const firstNamed = months[0].to - months[0].from >= 8;
    const label = (i: number) =>
        wraps && i === months.length - 1 ? `${MONTHS[Number(months[i].key.slice(5, 7)) - 1]} ${months[i].key.slice(0, 4)}` : monthName(months[i].key);
    const named = (i: number) => months[i].to - months[i].from >= 8 && !(wraps && i === months.length - 1 && firstNamed);

    // Which month the pointer is over while dragging a task onto the ring.
    const monthAt = (e: DragEvent) => {
        const box = svg.current?.getBoundingClientRect();
        if (!box || ctx.drag === null) return null;
        const k = 540 / box.width;
        const x = (e.clientX - box.left) * k - RC;
        const y = (e.clientY - box.top) * k - RC;
        const dist = Math.hypot(x, y);
        if (dist < R_IN - 30 || dist > R_SECTOR + 14) return null;
        let a = Math.atan2(x, -y);
        if (a < 0) a += 2 * Math.PI;
        const d = ((a / (2 * Math.PI) - GAP) / (1 - 2 * GAP)) * 365;
        const i = monthOf(Math.min(364, Math.max(0, d)));
        return i >= 0 ? i : null;
    };

    const shown =
        sel?.kind === 'month'
            ? onRing.filter((t) => monthOf(day(t)) === sel.i).sort((a, b) => day(a) - day(b))
            : sel?.kind === 'group'
              ? onRing.filter((t) => sel.ids.includes(t.id)).sort((a, b) => day(a) - day(b))
              : [];
    const shownMarks = sel?.kind === 'month' ? yearMarks.filter((m) => monthOf(m.d) === sel.i) : [];

    return (
        <div className="vt-year">
            <div
                className="vt-ring-box"
                onDragOver={(e) => {
                    const i = monthAt(e);
                    if (i === null) return setOver(null);
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    if (over !== i) setOver(i);
                }}
                onDragLeave={(e) => !e.currentTarget.contains(e.relatedTarget as Node) && setOver(null)}
                onDrop={(e) => {
                    const i = monthAt(e);
                    setOver(null);
                    if (i === null) return;
                    e.preventDefault();
                    ctx.move((t) => ctx.inMonth(t, months[i].first));
                }}
            >
                <svg ref={svg} className="vt-ring" viewBox="0 0 540 540" role="img" aria-label="The next twelve months as a ring, today at the top">
                    {/* The months: clickable wedges, lines between them and their names. */}
                    {months.map((m, i) => (
                        <path
                            key={m.key}
                            className={`vt-sector${sel?.kind === 'month' && sel.i === i ? ' is-sel' : ''}${over === i ? ' is-over' : ''}`}
                            d={wedge(R_IN - 30, R_SECTOR, m.from, m.to)}
                            onClick={() => setSel(sel?.kind === 'month' && sel.i === i ? null : { kind: 'month', i })}
                        >
                            <title>{`${monthName(m.key, true)}: ${counts[i]} ${counts[i] === 1 ? 'task' : 'tasks'}`}</title>
                        </path>
                    ))}
                    {months.map((m) => {
                        if (m.from === 0) return null;
                        const [ax, ay] = pt(R_IN - 30, m.from);
                        const [bx, by] = pt(R_SECTOR, m.from);
                        return <line key={m.key} className="vt-mline" x1={f1(ax)} y1={f1(ay)} x2={f1(bx)} y2={f1(by)} />;
                    })}
                    {months.map((m, i) =>
                        named(i) ? (
                            <text
                                key={m.key}
                                className="vt-mname"
                                x={f1(pt(R_MONTH, (m.from + m.to) / 2)[0])}
                                y={f1(pt(R_MONTH, (m.from + m.to) / 2)[1])}
                            >
                                {MONTHS[Number(m.key.slice(5, 7)) - 1]}
                            </text>
                        ) : null,
                    )}

                    {/* A ring per goal, with dots flowing round it. */}
                    {lanes.map((l, i) => (
                        <g key={l.key} style={css({ '--g': goalVar(l.color) })}>
                            <circle className="vt-rring" cx={RC} cy={RC} r={radius(i)} />
                            {[0, 1, 2].map((k) => (
                                <g key={k} className="vt-orbit" style={{ animationDelay: `${-k * 24 - i * 7}s` }}>
                                    <circle className="vt-orbit-dot" cx={RC} cy={RC - radius(i)} r="2.4" />
                                </g>
                            ))}
                        </g>
                    ))}

                    {/* Now: the hand at the top. */}
                    <line className="vt-hand" x1={RC} y1={RC} x2={RC} y2={RC - R_SECTOR} />
                    {[0, 1].map((k) => (
                        <circle key={k} className="vt-spark" cx={RC} r="2.3">
                            <animate
                                attributeName="cy"
                                from={RC - 40}
                                to={RC - R_SECTOR}
                                dur="2.8s"
                                begin={`${-k * 1.4}s`}
                                repeatCount="indefinite"
                            />
                            <animate attributeName="opacity" values="0;1;1;0" dur="2.8s" begin={`${-k * 1.4}s`} repeatCount="indefinite" />
                        </circle>
                    ))}
                    <circle className="vt-hub" cx={RC} cy={RC} r="5" />

                    {/* Landmarks, just outside the rings. */}
                    {groupOnRing(yearMarks, (m) => m.d, R_MARK, 24).map((g) => {
                        const [x, y] = pt(R_MARK, g.d);
                        const m = g.items[0];
                        return (
                            <g key={m.key} className={`vt-rmark is-${m.kind}`} style={css({ '--g': goalVar(m.color) })}>
                                <title>{g.items.map((x) => `${MARK_NAME[x.kind]} · ${fmtDay(x.date)} · ${x.title}`).join('\n')}</title>
                                {g.items.length > 1 ? (
                                    <>
                                        <circle cx={f1(x)} cy={f1(y)} r="8" />
                                        <text x={f1(x)} y={f1(y)}>
                                            {g.items.length}
                                        </text>
                                    </>
                                ) : m.kind === 'milestone' ? (
                                    <rect x={f1(x) - 5} y={f1(y) - 5} width="10" height="10" rx="1.5" transform={`rotate(45 ${f1(x)} ${f1(y)})`} />
                                ) : m.kind === 'project' ? (
                                    <path d={`M${f1(x) - 3} ${f1(y) + 7}V${f1(y) - 7}l9 3.5-9 3.5`} />
                                ) : (
                                    <>
                                        <circle cx={f1(x)} cy={f1(y)} r="7" className="vt-rmark-ring" />
                                        <circle cx={f1(x)} cy={f1(y)} r="2.6" />
                                    </>
                                )}
                            </g>
                        );
                    })}

                    {/* Tasks on their goal's ring. */}
                    {lanes.flatMap((l, li) =>
                        groupOnRing(
                            onRing.filter((t) => ctx.lane(t) === l.key),
                            day,
                            radius(li),
                            2 * dotR + 5,
                        ).map((g) => {
                            const [x, y] = pt(radius(li), g.d);
                            const one = g.items.length === 1 ? g.items[0] : null;
                            const first = g.items.some((t) => t.ready && t.rank === 1);
                            const label = one
                                ? `${one.ready ? `#${one.rank}, ` : ''}${one.title}`
                                : `${g.items.length} tasks around ${fmtDay(addDays(ctx.today, Math.round(g.d)))}`;
                            const pick = () =>
                                one
                                    ? ctx.open(one.id)
                                    : setSel({
                                          kind: 'group',
                                          ids: g.items.map((t) => t.id),
                                          label: `Around ${fmtDay(addDays(ctx.today, Math.round(g.d)))}`,
                                      });
                            return (
                                <g
                                    key={`${l.key}:${g.k}`}
                                    className={`vt-rdot${one && !one.ready ? ' is-quiet' : ''}${first ? ' is-first' : ''}${g.items.some((t) => t.id === ctx.selectedId) ? ' is-selected' : ''}`}
                                    style={css({ '--g': goalVar(l.color) })}
                                    role="button"
                                    tabIndex={0}
                                    aria-label={label}
                                    onClick={pick}
                                    onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), pick())}
                                    {...(one ? ctx.hover(one) : {})}
                                >
                                    {first && <circle className="vt-rglow" cx={f1(x)} cy={f1(y)} r={(one ? 6 : dotR) + 5} />}
                                    <circle cx={f1(x)} cy={f1(y)} r={one ? 6 : dotR} />
                                    {!one && (
                                        <text x={f1(x)} y={f1(y)}>
                                            {g.items.length}
                                        </text>
                                    )}
                                </g>
                            );
                        }),
                    )}

                    <text className="vt-ring-c" x={RC} y={RC + 28}>
                        Next 12 months
                    </text>
                    <text className="vt-ring-s" x={RC} y={RC + 46}>
                        {onRing.length} {onRing.length === 1 ? 'task' : 'tasks'} · {yearMarks.length}{' '}
                        {yearMarks.length === 1 ? 'landmark' : 'landmarks'}
                    </text>
                </svg>
                <div className="vt-legend">
                    {lanes.map((l) => (
                        <span key={l.key} style={css({ '--g': goalVar(l.color) })}>
                            <i />
                            {l.title}
                        </span>
                    ))}
                </div>
            </div>

            <aside className="vt-side" aria-live="polite">
                <header className="vt-side-head">
                    <b>{sel?.kind === 'month' ? monthName(months[sel.i].key, true) : sel?.kind === 'group' ? sel.label : 'Coming up'}</b>
                    {sel && (
                        <button type="button" className="icon-btn" onClick={() => setSel(null)} aria-label="Back to what's coming up">
                            <Icon name="x" />
                        </button>
                    )}
                </header>
                {sel ? (
                    <>
                        {shownMarks.length > 0 && (
                            <div className="vt-side-sec">
                                {shownMarks.map((m) => (
                                    <MarkRow key={m.key} m={m} />
                                ))}
                            </div>
                        )}
                        <div className="vt-side-sec">
                            {shown.length ? shown.map((t) => <Row key={t.id} t={t} ctx={ctx} />) : <p className="vt-side-empty">Nothing due.</p>}
                        </div>
                    </>
                ) : (
                    <>
                        {overdue.length > 0 && (
                            <div className="vt-side-sec">
                                <h4 className="is-late">Overdue</h4>
                                {overdue.map((t) => (
                                    <Row key={t.id} t={t} ctx={ctx} />
                                ))}
                            </div>
                        )}
                        {yearMarks.length > 0 && (
                            <div className="vt-side-sec">
                                <h4>Landmarks</h4>
                                {yearMarks.map((m) => (
                                    <MarkRow key={m.key} m={m} />
                                ))}
                            </div>
                        )}
                        <div className="vt-side-sec">
                            <h4>Months</h4>
                            {months.map((m, i) => (
                                <button key={m.key} type="button" className="vt-mrow" onClick={() => setSel({ kind: 'month', i })}>
                                    <span className="vt-mrow-n">{label(i)}</span>
                                    <span className="vt-mrow-bar">
                                        <i style={{ width: `${(hours[i] / maxH) * 100}%` }} />
                                    </span>
                                    <span className="vt-mrow-c">{counts[i] ? `${counts[i]} · ${shortH(hours[i])}` : '–'}</span>
                                </button>
                            ))}
                        </div>
                        {later.length > 0 && (
                            <p className="vt-side-empty">
                                Later: {later.length} {later.length === 1 ? 'task' : 'tasks'}
                            </p>
                        )}
                    </>
                )}
            </aside>
        </div>
    );
}

function Row({ t, ctx }: { t: Item; ctx: Ctx }) {
    return (
        <button
            type="button"
            className={`vt-row${ctx.selectedId === t.id ? ' is-selected' : ''}${t.ready ? '' : ' is-quiet'}`}
            style={css({ '--g': goalVar(t.goal?.color ?? t.project?.goalColor) })}
            {...ctx.grab(t)}
        >
            <Rank t={t} />
            <span className="vt-row-t">{t.title}</span>
            <span className={`vt-row-d${ctx.late(t) ? ' is-late' : ''}`}>{t.dueAt ? ctx.clock.date(t.dueAt) : ''}</span>
        </button>
    );
}

function MarkRow({ m }: { m: Mark }) {
    return (
        <Link href={m.href} className={`vt-row is-mark is-${m.kind}`} style={css({ '--g': goalVar(m.color) })} title={MARK_NAME[m.kind]}>
            <span className="vt-row-ic">
                <MarkIcon kind={m.kind} />
            </span>
            <span className="vt-row-t">{m.title}</span>
            <span className="vt-row-d">{fmtDay(m.date)}</span>
        </Link>
    );
}

/* ——— Shared: tasks with no date, and the hover card ——— */

/** Tasks with no due date: drag one onto the timeline to give it one, or a dated one here to clear it. */
function Dock({ ctx, list }: { ctx: Ctx; list: Item[] }) {
    const [over, setOver] = useState(false);
    return (
        <div
            className={`vt-dock${over ? ' is-over' : ''}`}
            onDragOver={(e) => {
                if (ctx.drag === null) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                setOver(true);
            }}
            onDragLeave={(e) => !e.currentTarget.contains(e.relatedTarget as Node) && setOver(false)}
            onDrop={(e) => {
                e.preventDefault();
                setOver(false);
                ctx.move(() => null);
            }}
        >
            <b className="vt-dock-label">No date</b>
            <div className="vt-dock-list">
                {list.length ? (
                    list.map((t) => (
                        <button
                            key={t.id}
                            type="button"
                            className={`vt-chip${ctx.selectedId === t.id ? ' is-selected' : ''}${t.ready ? '' : ' is-quiet'}`}
                            style={css({ '--g': goalVar(t.goal?.color ?? t.project?.goalColor) })}
                            {...ctx.grab(t)}
                            {...ctx.hover(t)}
                        >
                            <Rank t={t} />
                            <span>{t.title}</span>
                        </button>
                    ))
                ) : (
                    <span className="vt-dock-empty">–</span>
                )}
            </div>
        </div>
    );
}

function TipBox({ tip, clock }: { tip: { t: Item; r: DOMRect }; clock: Clock }) {
    const { t, r } = tip;
    const below = r.bottom + 120 < window.innerHeight;
    const x = Math.min(Math.max(r.left + r.width / 2, 140), window.innerWidth - 140);
    const due = dueInfo(t, clock);
    return createPortal(
        <div
            className="vt-tip"
            role="tooltip"
            style={{ left: x, top: below ? r.bottom + 8 : r.top - 8, transform: `translate(-50%, ${below ? '0' : '-100%'})` }}
        >
            <b>
                {t.ready ? `#${t.rank} · ` : ''}
                {t.title}
            </b>
            <span className={due.late ? 'is-late' : ''}>
                {t.dueAt ? (due.late ? due.text : `${clock.weekday(t.dueAt)} ${clock.date(t.dueAt)}`) : 'No date'} · {fmtHours(t.estHours)}
            </span>
            {t.project && (
                <span className="vt-tip-proj" style={css({ '--g': goalVar(t.goal?.color ?? t.project.goalColor) })}>
                    <i />
                    {t.project.name}
                </span>
            )}
            {!t.ready && <span>{t.why}</span>}
        </div>,
        document.body,
    );
}
