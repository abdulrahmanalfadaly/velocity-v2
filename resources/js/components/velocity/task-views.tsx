import { Clock, dueInfo, fmtHours, goalVar } from '@/lib/velocity';
import type { BaseTask, NotReadyTask, RankedTask } from '@/types/velocity';
import { router } from '@inertiajs/react';
import { type DragEvent, useState } from 'react';
import { Icon } from './icon';

/*
 * Two more ways to look at the same tasks: a board (one column per status) and a
 * timeline (one column per day). Inside every column the tasks keep the formula's
 * order; dragging a card changes its status or due date, never its rank.
 */

type Item = (RankedTask & { ready: true }) | (NotReadyTask & { ready: false }) | (BaseTask & { ready: false; doneToday: true });
type Props = {
    tasks: RankedTask[];
    notReady: NotReadyTask[];
    doneToday: BaseTask[];
    clock: Clock;
    selectedId: number | null;
    onOpen: (id: number) => void;
};

const keep = { preserveScroll: true, preserveState: true };
const KIND_ICON: Record<string, string> = { waiting: 'hourglass', blocked: 'lock', later: 'calendar' };

function items({ tasks, notReady }: Pick<Props, 'tasks' | 'notReady'>): Item[] {
    return [...tasks.map((t) => ({ ...t, ready: true as const })), ...notReady.map((t) => ({ ...t, ready: false as const }))];
}

/** One task as a card: rank (or why it can't start yet), title, project, due date and work. */
function Card({
    t,
    clock,
    selected,
    onOpen,
    onDrag,
}: {
    t: Item;
    clock: Clock;
    selected: boolean;
    onOpen: () => void;
    onDrag: (id: number) => void;
}) {
    const due = dueInfo(t, clock);
    const done = 'doneToday' in t;
    return (
        <div
            className={`vcard${selected ? ' is-selected' : ''}${t.ready ? '' : ' is-quiet'}${done ? ' is-done' : ''}`}
            draggable
            tabIndex={0}
            role="button"
            aria-label={`${t.ready ? `#${t.rank}, ` : ''}${t.title}`}
            style={{ ['--g' as string]: goalVar(t.goal?.color ?? t.project?.goalColor) }}
            onDragStart={(e) => {
                e.dataTransfer.setData('text/plain', String(t.id));
                e.dataTransfer.effectAllowed = 'move';
                onDrag(t.id);
            }}
            onClick={onOpen}
            onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), onOpen())}
        >
            <div className="vcard-top">
                {t.ready ? (
                    <span className="rank-badge">{t.rank}</span>
                ) : done ? (
                    <span className="att-ic">
                        <Icon name="check" />
                    </span>
                ) : (
                    <span className="att-ic" title={'why' in t ? t.why : undefined}>
                        <Icon name={KIND_ICON['kind' in t ? t.kind : ''] ?? 'info'} />
                    </span>
                )}
                <span className="vcard-title">{t.title}</span>
            </div>
            <div className="vcard-meta">
                {t.project && (
                    <span className="vcard-project">
                        <i />
                        {t.project.name}
                    </span>
                )}
                {!done && t.dueAt && <span className={due.late ? 'is-late' : ''}>{due.text}</span>}
                <span>{fmtHours(t.estHours)}</span>
            </div>
        </div>
    );
}

/** A column that cards can be dropped on. */
function Column({
    id,
    title,
    sub,
    over,
    setOver,
    onDrop,
    children,
    empty,
    accepts = true,
    className = '',
}: {
    id: string;
    title: string;
    sub?: string;
    over: string | null;
    setOver: (id: string | null) => void;
    onDrop: () => void;
    children: React.ReactNode;
    empty: string;
    accepts?: boolean;
    className?: string;
}) {
    const allow = (e: DragEvent) => {
        if (!accepts) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (over !== id) setOver(id);
    };
    return (
        <section
            className={`vcol ${className}${over === id ? ' is-over' : ''}`}
            aria-label={title}
            onDragOver={allow}
            onDragEnter={allow}
            onDragLeave={(e) => !e.currentTarget.contains(e.relatedTarget as Node) && setOver(null)}
            onDrop={(e) => {
                e.preventDefault();
                setOver(null);
                if (accepts) onDrop();
            }}
        >
            <header className="vcol-head">
                <b>{title}</b>
                {sub && <span>{sub}</span>}
            </header>
            <div className="vcol-body">{children ?? null}</div>
            {!children || (Array.isArray(children) && children.length === 0) ? <p className="vcol-empty">{empty}</p> : null}
        </section>
    );
}

const hours = (list: { estHours: number }[]) => {
    const h = list.reduce((s, t) => s + t.estHours, 0);
    return h ? fmtHours(h) : '';
};

/** Columns by status: To do, Doing, Waiting, Done today. Drop a card on a column to change its status. */
export function Board(props: Props) {
    const [drag, setDrag] = useState<number | null>(null);
    const [over, setOver] = useState<string | null>(null);
    const all = items(props);
    const doneToday: Item[] = props.doneToday.map((t) => ({ ...t, ready: false as const, doneToday: true as const }));
    const cols: [string, string, Item[]][] = [
        ['todo', 'To do', all.filter((t) => t.status === 'todo')],
        ['doing', 'Doing', all.filter((t) => t.status === 'doing')],
        ['waiting', 'Waiting', all.filter((t) => t.status === 'waiting')],
        ['done', 'Done today', doneToday],
    ];
    const find = (id: number | null) => [...all, ...doneToday].find((t) => t.id === id);

    const dropOn = (col: string) => {
        const t = find(drag);
        setDrag(null);
        if (!t) return;
        const isDone = 'doneToday' in t;
        if (col === 'done') {
            if (!isDone) router.post(route('tasks.complete', t.id), {}, keep);
        } else if (isDone) {
            router.post(route('tasks.reopen', t.id), { status: col }, keep); // open again, in the column it was dropped on
        } else if (t.status !== col) {
            router.patch(route('tasks.update', t.id), { status: col }, keep);
        }
    };

    return (
        <div className="vboard">
            {cols.map(([id, title, list]) => (
                <Column
                    key={id}
                    id={id}
                    title={title}
                    sub={`${list.length}${hours(list) ? ` · ${hours(list)}` : ''}`}
                    over={over}
                    setOver={setOver}
                    onDrop={() => dropOn(id)}
                    empty={id === 'done' ? 'Nothing done yet today.' : 'Nothing here.'}
                    className={`is-${id}`}
                >
                    {list.map((t) => (
                        <Card
                            key={t.id}
                            t={t}
                            clock={props.clock}
                            selected={props.selectedId === t.id}
                            onOpen={() => props.onOpen(t.id)}
                            onDrag={setDrag}
                        />
                    ))}
                </Column>
            ))}
        </div>
    );
}

/** "2026-11-13" for the user's today, plus n days. */
function localDay(clock: Clock, plus: number): string {
    const today = clock.localInput(clock.now.toISOString()).slice(0, 10);
    const d = new Date(`${today}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + plus);
    return d.toISOString().slice(0, 10);
}

/** "2026-11-13" plus n days. */
function addDays(day: string, n: number): string {
    const d = new Date(`${day}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
}

const DAYS = 14;
const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/** How far the timeline looks ahead: two weeks by day, or two or three months by week. */
type Span = '2w' | '2m' | '3m';
const SPANS: [Span, string][] = [
    ['2w', '2 weeks'],
    ['2m', '2 months'],
    ['3m', '3 months'],
];
const WEEKS: Record<Exclude<Span, '2w'>, number> = { '2m': 9, '3m': 13 };

/**
 * Columns by day (Overdue, the next two weeks, Later, No date), or by week for two or three
 * months. Drop a card on a day to give it that due date; on a week, the same weekday that week
 * (a task with no date: the Friday).
 */
export function Timeline(props: Props) {
    const { clock } = props;
    const [drag, setDrag] = useState<number | null>(null);
    const [over, setOver] = useState<string | null>(null);
    const [span, setSpanState] = useState<Span>(() => {
        const saved = typeof localStorage !== 'undefined' ? localStorage.getItem('velocity.timeline') : null;
        return saved === '2m' || saved === '3m' ? saved : '2w';
    });
    const setSpan = (s: Span) => {
        setSpanState(s);
        localStorage.setItem('velocity.timeline', s);
    };
    const all = items(props);
    const today = localDay(clock, 0);
    const dayOf = (t: Item) => (t.dueAt ? clock.dayDiff(t.dueAt) : null);
    const isLate = (t: Item) => !!t.dueAt && (dueInfo(t, clock).late || (dayOf(t) ?? 0) < 0);
    // Weeks start on Monday; week 0 is this one.
    const sinceMonday = (new Date(`${today}T12:00:00Z`).getUTCDay() + 6) % 7;
    const weekOf = (t: Item) => {
        const d = dayOf(t);
        return d == null ? null : Math.floor((d + sinceMonday) / 7);
    };

    type Col = { id: string; title: string; sub?: string; list: Item[]; day: string | null | undefined; weekend?: boolean; week?: boolean };
    let middle: Col[];
    let later: (t: Item) => boolean;
    if (span === '2w') {
        middle = Array.from({ length: DAYS }, (_, i) => {
            const day = localDay(clock, i);
            const at = `${day}T12:00:00Z`;
            const weekday = new Date(at).getUTCDay();
            return {
                id: day,
                title: i === 0 ? 'Today' : i === 1 ? 'Tomorrow' : clock.weekday(at),
                sub: clock.date(at),
                list: all.filter((t) => !isLate(t) && dayOf(t) === i),
                day,
                weekend: weekday === 0 || weekday === 6,
            };
        });
        later = (t) => !isLate(t) && (dayOf(t) ?? -1) >= DAYS;
    } else {
        const n = WEEKS[span];
        middle = Array.from({ length: n }, (_, w) => {
            const monday = addDays(today, 7 * w - sinceMonday);
            return {
                id: `w${monday}`,
                title: w === 0 ? 'This week' : w === 1 ? 'Next week' : `Week of ${clock.date(`${monday}T12:00:00Z`)}`,
                sub: `${clock.date(`${monday}T12:00:00Z`)} – ${clock.date(`${addDays(monday, 6)}T12:00:00Z`)}`,
                list: all.filter((t) => !isLate(t) && weekOf(t) === w),
                day: monday,
                week: true,
            };
        });
        later = (t) => !isLate(t) && (weekOf(t) ?? -1) >= n;
    }
    const cols: Col[] = [
        { id: 'late', title: 'Overdue', list: all.filter(isLate), day: undefined },
        ...middle,
        { id: 'later', title: 'Later', list: all.filter(later), day: undefined },
        { id: 'none', title: 'No date', list: all.filter((t) => !t.dueAt), day: null },
    ];

    const dropOn = (c: Col) => {
        const t = all.find((x) => x.id === drag);
        setDrag(null);
        if (!t || c.day === undefined) return;
        let day = c.day;
        if (day && c.week) {
            // The same weekday in that week (a task with no date: the Friday), never before today.
            const offset = t.dueAt ? Math.max(0, WEEKDAYS.indexOf(clock.weekday(t.dueAt))) : 4;
            day = addDays(day, offset);
            if (day < today) day = today;
        }
        // Keep the time of day it already had; a task with no time gets the end of the day.
        const time = t.dueAt ? clock.localInput(t.dueAt).slice(11) : '23:59';
        const due = day === null ? null : `${day}T${time}`;
        if (due !== (t.dueAt ? clock.localInput(t.dueAt) : null)) router.patch(route('tasks.update', t.id), { due_at: due }, keep);
    };

    return (
        <>
            <div className="seg vt-span" role="radiogroup" aria-label="How far ahead">
                {SPANS.map(([k, label]) => (
                    <button key={k} type="button" role="radio" aria-checked={span === k} onClick={() => setSpan(k)}>
                        {label}
                    </button>
                ))}
            </div>
            <div
                className={`vtimeline${span === '2w' ? '' : ' is-weeks'}`}
                role="region"
                aria-label={span === '2w' ? 'Tasks by day' : 'Tasks by week'}
            >
                {cols.map((c) => (
                    <Column
                        key={c.id}
                        id={c.id}
                        title={c.title}
                        sub={[c.sub, hours(c.list)].filter(Boolean).join(' · ')}
                        over={over}
                        setOver={setOver}
                        accepts={c.day !== undefined}
                        onDrop={() => dropOn(c)}
                        empty={c.id === 'late' ? 'Nothing overdue.' : '–'}
                        className={`is-day${c.weekend ? ' is-weekend' : ''}${c.id === 'late' ? ' is-late-col' : ''}${c.title === 'Today' || c.title === 'This week' ? ' is-today' : ''}`}
                    >
                        {c.list.map((t) => (
                            <Card
                                key={t.id}
                                t={t}
                                clock={clock}
                                selected={props.selectedId === t.id}
                                onOpen={() => props.onOpen(t.id)}
                                onDrag={setDrag}
                            />
                        ))}
                    </Column>
                ))}
            </div>
        </>
    );
}
