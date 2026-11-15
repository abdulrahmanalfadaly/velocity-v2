import { BulkSet } from '@/components/velocity/bulk-set';
import { DatePick } from '@/components/velocity/date-pick';
import { Icon } from '@/components/velocity/icon';
import { Select } from '@/components/velocity/select';
import { TaskPanel } from '@/components/velocity/task-panel';
import { Board, Timeline } from '@/components/velocity/task-views';
import { Check } from '@/components/velocity/ui';
import VelocityShell from '@/layouts/velocity-shell';
import { adjustWeights, Clock, CRIT, DEFAULT_WEIGHTS, dueInfo, fmt1, fmtHours, repeatText, rerank, sameWeights } from '@/lib/velocity';
import type { BaseTask, CriterionKey, FollowUpTask, NotReadyTask, RankedTask, RankingSettings, TaskDetail, VelocityShared } from '@/types/velocity';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

type View = 'upnext' | 'inbox' | 'waiting';

interface Props extends VelocityShared {
    view: View;
    show?: Filter;
    tasks: RankedTask[];
    settings: RankingSettings;
    notReady: NotReadyTask[];
    followUps: FollowUpTask[];
    doneToday: BaseTask[];
    selected: TaskDetail | null;
    projects: { id: number; name: string }[];
}

const VIEWS: Record<View, { title: string; href: string }> = {
    upnext: { title: 'Up next', href: '/tasks' },
    inbox: { title: 'Inbox', href: '/tasks/inbox' },
    waiting: { title: 'Not ready yet', href: '/tasks/waiting' },
};

type Layout = 'list' | 'board' | 'timeline';
const LAYOUTS: [Layout, string, string][] = [
    ['list', 'List', 'list'],
    ['board', 'Board', 'columns'],
    ['timeline', 'Timeline', 'calendar'],
];

type Filter = 'all' | 'today' | 'week' | 'overdue' | 'quick' | 'doing' | 'important' | 'repeating' | 'inbox';
/** Views of the list: the same ranking (same numbers) with some tasks left out. */
const FILTERS: [Filter, string, (t: RankedTask, c: Clock) => boolean][] = [
    ['all', 'All', () => true],
    ['today', 'Due today', (t, c) => !!t.dueAt && c.dayDiff(t.dueAt) === 0],
    ['week', 'Due this week', (t, c) => !!t.dueAt && c.dayDiff(t.dueAt) <= 7],
    ['overdue', 'Overdue', (t, c) => !!t.dueAt && Date.parse(t.dueAt) < c.now.getTime()],
    ['quick', 'Quick wins', (t) => t.estHours <= 1],
    ['doing', 'Doing', (t) => t.status === 'doing'],
    ['important', 'Important', (t) => t.importance >= 4],
    ['repeating', 'Repeating', (t) => !!t.repeat],
    ['inbox', 'No project', (t) => !t.project],
];

const KINDS: [NotReadyTask['kind'], string, string][] = [
    ['waiting', 'Waiting', 'hourglass'],
    ['blocked', 'After another task', 'lock'],
    ['later', 'Starts later', 'calendar'],
    ['held', 'Project planned or on hold', 'pause'],
];

export default function TasksIndex() {
    const { props } = usePage<Props>();
    const view = props.view ?? 'upnext';
    const inInbox = view === 'inbox';
    const clock = Clock.from(props.clock);
    // The view comes from the address (?show=today), so it stays after a reload and the Tasks menu can link to it.
    const [filter, setFilterState] = useState<Filter>((props.show as Filter) ?? 'all');
    useEffect(() => setFilterState((props.show as Filter) ?? 'all'), [props.show]);
    const setFilter = (k: Filter) => {
        setFilterState(k);
        const url = new URL(window.location.href);
        if (k === 'all') url.searchParams.delete('show');
        else url.searchParams.set('show', k);
        router.replace({ url: url.pathname + url.search, preserveScroll: true, preserveState: true, props: (p) => ({ ...p, show: k }) });
    };
    const [weights, setWeights] = useState(props.settings.weights);
    const [popOpen, setPopOpen] = useState(false);
    const [text, setText] = useState('');
    // Select mode: tick several tasks, then act on all of them from the bar at the bottom.
    const [picked, setPicked] = useState<number[] | null>(null);
    const lastPick = useRef<number | null>(null);
    const [bulkDue, setBulkDue] = useState('');
    // List, board or timeline: remembered on this device.
    const [layout, setLayoutState] = useState<Layout>(() => {
        const saved = typeof localStorage !== 'undefined' ? localStorage.getItem('velocity.layout') : null;
        return saved === 'board' || saved === 'timeline' ? saved : 'list';
    });
    const setLayout = (l: Layout) => {
        setLayoutState(l);
        setPicked(null);
        localStorage.setItem('velocity.layout', l);
    };
    const snapshot = useRef(weights);
    const saveTimer = useRef<ReturnType<typeof setTimeout>>(undefined);
    useEffect(() => setWeights(props.settings.weights), [props.settings.weights]);

    const list = useMemo(
        () => (sameWeights(weights, props.settings.weights) ? props.tasks : rerank(props.tasks, weights)),
        [props.tasks, props.settings.weights, weights],
    );

    // The Inbox is the same ranking with project tasks hidden, so every task keeps its number.
    const base = inInbox ? list.filter((t) => !t.project) : list;
    const followUps = inInbox ? props.followUps.filter((f) => !f.project) : props.followUps;
    const notReady = inInbox ? props.notReady.filter((w) => !w.project) : props.notReady;
    const doneToday = inInbox ? props.doneToday.filter((d) => !d.project) : props.doneToday;
    const filters = inInbox ? FILTERS.filter((f) => f[0] !== 'inbox') : FILTERS;
    const fn = FILTERS.find((f) => f[0] === filter)![2];
    const shown = base.filter((t) => fn(t, clock));
    const open = (id: number | null) =>
        router.get(VIEWS[view].href, id ? { task: id } : {}, { preserveState: true, preserveScroll: true, only: ['selected'] });

    const pick = (id: number, range: boolean) => {
        const anchor = lastPick.current; // read now: it changes below, before React runs the update
        setPicked((now) => {
            const cur = now ?? [];
            // Shift-click ticks everything between the last tick and this one.
            if (range && anchor != null) {
                const ids = shown.map((t) => t.id);
                const [a, b] = [ids.indexOf(anchor), ids.indexOf(id)].sort((x, y) => x - y);
                if (a >= 0) return [...new Set([...cur, ...ids.slice(a, b + 1)])];
            }
            return cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id];
        });
        lastPick.current = id;
    };
    const bulk = (action: string, extra: Record<string, string | number | null> = {}) => {
        if (!picked?.length) return;
        router.post(
            route('tasks.bulk'),
            { ids: picked, action, ...extra },
            { preserveScroll: true, preserveState: true, onSuccess: () => (setPicked(null), setBulkDue('')) },
        );
    };

    const setWeight = (k: CriterionKey, v: number) => {
        const next = adjustWeights(snapshot.current, k, v);
        setWeights(next);
        clearTimeout(saveTimer.current);
        saveTimer.current = setTimeout(
            () =>
                router.put(
                    route('weights.update'),
                    { weights: next },
                    { preserveScroll: true, preserveState: true, only: ['tasks', 'settings', 'selected', 'nav'] },
                ),
            500,
        );
    };

    return (
        <VelocityShell
            title={VIEWS[view].title}
            section="tasks"
            active={view === 'upnext' && filter !== 'all' ? `show:${filter}` : view}
            panel={
                props.selected ? (
                    <TaskPanel key={props.selected.id} task={props.selected} clock={clock} projects={props.projects} onClose={() => open(null)} />
                ) : undefined
            }
            onEscape={() => (popOpen ? setPopOpen(false) : picked ? setPicked(null) : props.selected ? open(null) : undefined)}
        >
            <div className="main-inner">
                <header className="page-head">
                    <div>
                        <p className="eyebrow is-section">Tasks</p>
                        <h1 className="h1">
                            {VIEWS[view].title} <span className="count">{view === 'waiting' ? notReady.length : base.length} tasks</span>
                        </h1>
                    </div>
                    {view !== 'waiting' && (
                        <div className="page-actions">
                            <div className="layout-seg" role="radiogroup" aria-label="Show as">
                                {LAYOUTS.map(([k, label, icon]) => (
                                    <button key={k} role="radio" aria-checked={layout === k} onClick={() => setLayout(k)} title={label}>
                                        <Icon name={icon} className="ic-sm" />
                                        <span className="btn-label">{label}</span>
                                    </button>
                                ))}
                            </div>
                            <div className="pop-wrap">
                                <button
                                    className="btn btn-secondary"
                                    aria-expanded={popOpen}
                                    aria-controls="weights-pop"
                                    onClick={() => setPopOpen((o) => !o)}
                                >
                                    <Icon name="sliders" />
                                    <span className="btn-label">Weights</span>
                                </button>
                                {popOpen && (
                                    <div className="popover" id="weights-pop" role="dialog" aria-label="Weights">
                                        <p className="pop-title">Weights</p>
                                        {CRIT.map((c) => (
                                            <div className="w-row" key={c.k}>
                                                <label htmlFor={`w-${c.k}`}>
                                                    <i className={`sw sw-${c.k}`} />
                                                    {c.name}
                                                </label>
                                                <input
                                                    type="range"
                                                    id={`w-${c.k}`}
                                                    min={0}
                                                    max={100}
                                                    value={weights[c.k]}
                                                    onPointerDown={() => (snapshot.current = weights)}
                                                    onFocus={() => (snapshot.current = weights)}
                                                    onChange={(e) => setWeight(c.k, Number(e.target.value))}
                                                />
                                                <output htmlFor={`w-${c.k}`}>{weights[c.k]}%</output>
                                            </div>
                                        ))}
                                        <div className="pop-foot">
                                            <span />
                                            <button
                                                className="link"
                                                onClick={() =>
                                                    router.put(
                                                        route('weights.update'),
                                                        { weights: DEFAULT_WEIGHTS },
                                                        { preserveScroll: true, preserveState: true, only: ['tasks', 'settings', 'selected', 'nav'] },
                                                    )
                                                }
                                            >
                                                Reset
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}
                </header>

                {view === 'waiting' ? (
                    <>
                        {notReady.length === 0 && followUps.length === 0 && <p className="list-empty">Nothing is waiting.</p>}
                        {followUps.length > 0 && (
                            <details className="section-fold" open>
                                <summary>
                                    <Icon name="chevR" className="chev" />
                                    Follow up <span className="hint">· {followUps.length}</span>
                                </summary>
                                {followUps.map((f) => (
                                    <div
                                        className={`fold-row is-link${props.selected?.id === f.id ? ' is-selected' : ''}`}
                                        key={f.id}
                                        tabIndex={0}
                                        onClick={() => open(props.selected?.id === f.id ? null : f.id)}
                                        onKeyDown={(e) =>
                                            (e.key === 'Enter' || e.key === ' ') && e.target === e.currentTarget && (e.preventDefault(), open(f.id))
                                        }
                                    >
                                        <span className="att-ic">
                                            <Icon name="followUp" />
                                        </span>
                                        <div>
                                            <div className="row-title">{f.title}</div>
                                            <div className="row-sub">
                                                {f.rank != null && (
                                                    <>
                                                        <span>#{f.rank}</span>
                                                        <span className="dotsep" />
                                                    </>
                                                )}
                                                <span>{f.project?.name ?? 'No project'}</span>
                                                <span className="dotsep" />
                                                <span>{f.why}</span>
                                            </div>
                                        </div>
                                        <button
                                            className="btn btn-secondary btn-sm"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                router.patch(
                                                    route('tasks.update', f.id),
                                                    { follow_up_on: null },
                                                    { preserveScroll: true, preserveState: true },
                                                );
                                            }}
                                        >
                                            Followed up
                                        </button>
                                    </div>
                                ))}
                            </details>
                        )}
                        {KINDS.map(([kind, label, icon]) => {
                            const rows = notReady.filter((w) => w.kind === kind && !followUps.some((f) => f.id === w.id));
                            return (
                                rows.length > 0 && (
                                    <details className="section-fold" open key={kind}>
                                        <summary>
                                            <Icon name="chevR" className="chev" />
                                            {label} <span className="hint">· {rows.length}</span>
                                        </summary>
                                        {rows.map((w) => (
                                            <div
                                                className={`fold-row is-link${props.selected?.id === w.id ? ' is-selected' : ''}`}
                                                key={w.id}
                                                tabIndex={0}
                                                onClick={() => open(props.selected?.id === w.id ? null : w.id)}
                                                onKeyDown={(e) =>
                                                    (e.key === 'Enter' || e.key === ' ') &&
                                                    e.target === e.currentTarget &&
                                                    (e.preventDefault(), open(w.id))
                                                }
                                            >
                                                <span className="att-ic">
                                                    <Icon name={icon} />
                                                </span>
                                                <div>
                                                    <div className="row-title">{w.title}</div>
                                                    <div className="row-sub">
                                                        <span>{w.project?.name ?? 'No project'}</span>
                                                        <span className="dotsep" />
                                                        <span>{w.why}</span>
                                                    </div>
                                                </div>
                                                {kind === 'waiting' ? (
                                                    <button
                                                        className="btn btn-secondary btn-sm"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            router.patch(
                                                                route('tasks.update', w.id),
                                                                { status: 'todo' },
                                                                { preserveScroll: true, preserveState: true },
                                                            );
                                                        }}
                                                    >
                                                        Ready now
                                                    </button>
                                                ) : kind === 'later' ? (
                                                    <StartNow id={w.id} />
                                                ) : (
                                                    <span />
                                                )}
                                            </div>
                                        ))}
                                    </details>
                                )
                            );
                        })}
                    </>
                ) : (
                    <>
                        <div className="toolbar">
                            <form
                                className="add-row"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    // Clear the box first, so a fast second Enter can't add the task twice.
                                    if (!text.trim()) return;
                                    setText('');
                                    router.post(
                                        route('tasks.store'),
                                        { text },
                                        { preserveScroll: true, preserveState: true, onError: () => setText(text) },
                                    );
                                }}
                            >
                                <Icon name="plus" />
                                <input value={text} onChange={(e) => setText(e.target.value)} placeholder="Add a task" aria-label="Add a task" />
                                <kbd>Enter</kbd>
                            </form>
                            <div className="filters" role="group" aria-label="Show">
                                {filters.map(([k, label, f]) => (
                                    <button key={k} className="filter" aria-pressed={filter === k} onClick={() => setFilter(k)}>
                                        {label} <span className="n">{base.filter((t) => f(t, clock)).length}</span>
                                    </button>
                                ))}
                                {layout === 'list' && (
                                    <button
                                        className="filter-select filter"
                                        aria-pressed={picked !== null}
                                        onClick={() => setPicked(picked ? null : [])}
                                        title="Select several tasks"
                                    >
                                        <Icon name="tasks" className="ic-sm" />
                                        Select
                                    </button>
                                )}
                            </div>
                        </div>

                        {layout !== 'list' ? (
                            (() => {
                                const View = layout === 'board' ? Board : Timeline;
                                return (
                                    <View
                                        tasks={shown}
                                        notReady={notReady}
                                        doneToday={doneToday}
                                        clock={clock}
                                        selectedId={props.selected?.id ?? null}
                                        onOpen={(id) => open(props.selected?.id === id ? null : id)}
                                    />
                                );
                            })()
                        ) : (
                            <>
                                <ol className={`rank-list${picked ? ' is-picking' : ''}`} aria-label="Tasks in priority order">
                                    {shown.length === 0 && <li className="list-empty">Nothing here right now.</li>}
                                    {shown.map((t) => {
                                        const due = dueInfo(t, clock);
                                        const done = t.steps.filter((s) => s.done).length;
                                        return (
                                            <li
                                                key={t.id}
                                                className={`row${props.selected?.id === t.id ? ' is-selected' : ''}${picked?.includes(t.id) ? ' is-picked' : ''}`}
                                                tabIndex={0}
                                                aria-label={`#${t.rank}, ${t.title}, priority ${fmt1(t.score)}`}
                                                aria-selected={picked ? picked.includes(t.id) : undefined}
                                                onClick={(e) => (picked ? pick(t.id, e.shiftKey) : open(props.selected?.id === t.id ? null : t.id))}
                                                onKeyDown={(e) =>
                                                    (e.key === 'Enter' || e.key === ' ') &&
                                                    e.target === e.currentTarget &&
                                                    (e.preventDefault(), picked ? pick(t.id, e.shiftKey) : open(t.id))
                                                }
                                            >
                                                {picked ? (
                                                    <span className={`pick${picked.includes(t.id) ? ' is-on' : ''}`} aria-hidden="true">
                                                        <Icon name="check" />
                                                    </span>
                                                ) : (
                                                    <Check
                                                        done={false}
                                                        label={`Mark “${t.title}” done`}
                                                        onClick={() =>
                                                            router.post(
                                                                route('tasks.complete', t.id),
                                                                {},
                                                                { preserveScroll: true, preserveState: true },
                                                            )
                                                        }
                                                    />
                                                )}
                                                <span className="rank">{t.rank}</span>
                                                <div>
                                                    <div className="row-title">{t.title}</div>
                                                    <div className="row-sub">
                                                        <span>{t.project?.name ?? 'No project'}</span>
                                                        <span className="dotsep" />
                                                        <span>{fmtHours(t.estHours)}</span>
                                                        {t.repeat && (
                                                            <>
                                                                <span className="dotsep" />
                                                                <span className="is-repeat">
                                                                    <Icon name="habits" className="ic-xs" />
                                                                    {repeatText(t.repeat)}
                                                                </span>
                                                            </>
                                                        )}
                                                        {t.steps.length > 0 && (
                                                            <>
                                                                <span className="dotsep" />
                                                                <span>
                                                                    {done}/{t.steps.length} steps
                                                                </span>
                                                            </>
                                                        )}
                                                        {t.parent && (
                                                            <>
                                                                <span className="dotsep" />
                                                                <span className="is-parent">
                                                                    <Icon name="branch" className="ic-xs" />
                                                                    in “{t.parent.title}”
                                                                </span>
                                                            </>
                                                        )}
                                                        {t.status === 'doing' && <span className="chip is-doing">Doing</span>}
                                                        <span className={`chip due-mobile ${due.cls}`}>{due.text}</span>
                                                    </div>
                                                </div>
                                                <span className={`chip ${due.cls}`}>
                                                    <Icon name="clock" />
                                                    {due.text}
                                                </span>
                                                <span className="score-num">{fmt1(t.score)}</span>
                                            </li>
                                        );
                                    })}
                                </ol>

                                {notReady.length > 0 && (
                                    <details className="section-fold" open id="not-ready">
                                        <summary>
                                            <Icon name="chevR" className="chev" />
                                            Not ready yet <span className="hint">· {notReady.length}</span>
                                        </summary>
                                        {notReady.map((w) => (
                                            <div
                                                className={`fold-row is-link${props.selected?.id === w.id ? ' is-selected' : ''}`}
                                                key={w.id}
                                                tabIndex={0}
                                                onClick={() => open(props.selected?.id === w.id ? null : w.id)}
                                                onKeyDown={(e) =>
                                                    (e.key === 'Enter' || e.key === ' ') &&
                                                    e.target === e.currentTarget &&
                                                    (e.preventDefault(), open(w.id))
                                                }
                                            >
                                                <span className="att-ic">
                                                    <Icon name={w.kind === 'waiting' ? 'hourglass' : w.kind === 'blocked' ? 'lock' : 'calendar'} />
                                                </span>
                                                <div>
                                                    <div className="row-title">{w.title}</div>
                                                    <div className="row-sub">
                                                        <span>{w.project?.name ?? 'No project'}</span>
                                                        <span className="dotsep" />
                                                        <span>{w.why}</span>
                                                    </div>
                                                </div>
                                                {w.kind === 'later' ? <StartNow id={w.id} /> : <span />}
                                            </div>
                                        ))}
                                    </details>
                                )}
                                {doneToday.length > 0 && (
                                    <details className="section-fold">
                                        <summary>
                                            <Icon name="chevR" className="chev" />
                                            Done today <span className="hint">· {doneToday.length}</span>
                                        </summary>
                                        {doneToday.map((d) => (
                                            <div className="fold-row" key={d.id}>
                                                <Check
                                                    done
                                                    label={`Mark “${d.title}” not done`}
                                                    onClick={() =>
                                                        router.post(route('tasks.reopen', d.id), {}, { preserveScroll: true, preserveState: true })
                                                    }
                                                />
                                                <div>
                                                    <div className="row-title" style={{ color: 'var(--text-3)' }}>
                                                        {d.title}
                                                    </div>
                                                    <div className="row-sub">
                                                        {d.project?.name ?? 'No project'} <span className="dotsep" />{' '}
                                                        {d.completedAt ? clock.time(d.completedAt) : ''}
                                                    </div>
                                                </div>
                                                <span />
                                            </div>
                                        ))}
                                    </details>
                                )}
                            </>
                        )}
                    </>
                )}
            </div>
            {picked && (
                <div className="bulkbar" role="toolbar" aria-label="Actions for the selected tasks">
                    <b>{picked.length} selected</b>
                    <button className="link" onClick={() => setPicked(picked.length === shown.length ? [] : shown.map((t) => t.id))}>
                        {picked.length === shown.length && shown.length > 0 ? 'None' : 'All'}
                    </button>
                    <span className="bulk-sep" />
                    <button className="btn btn-primary btn-sm" disabled={!picked.length} onClick={() => bulk('complete')}>
                        <Icon name="check" className="ic-sm" />
                        Done
                    </button>
                    <Select
                        className="prop-input"
                        label="Move to project"
                        value=""
                        placeholder="Move to…"
                        disabled={!picked.length}
                        onChange={(v) => bulk('move', { project_id: v === 'inbox' ? null : Number(v) })}
                        options={[
                            { value: 'inbox', label: 'Inbox (no project)' },
                            ...props.projects.map((p) => ({ value: String(p.id), label: p.name })),
                        ]}
                    />
                    <DatePick
                        mode="datetime"
                        className="prop-input"
                        label="Due date for the selected tasks"
                        placeholder="Due…"
                        value={bulkDue}
                        onChange={setBulkDue}
                    />
                    <button
                        className="btn btn-secondary btn-sm"
                        disabled={!picked.length || !bulkDue}
                        onClick={() => bulk('due', { due_at: bulkDue })}
                    >
                        Set due
                    </button>
                    <BulkSet disabled={!picked.length} onSet={bulk} />
                    <button className="btn btn-secondary btn-sm" disabled={!picked.length} onClick={() => bulk('cancel')}>
                        <Icon name="ban" className="ic-sm" />
                        Cancel
                    </button>
                    <button className="btn btn-secondary btn-sm" disabled={!picked.length} onClick={() => bulk('delete')}>
                        <Icon name="trash" className="ic-sm" />
                        Delete
                    </button>
                    <span className="bulk-sep" />
                    <button className="icon-btn" onClick={() => setPicked(null)} aria-label="Stop selecting" title="Stop selecting (Esc)">
                        <Icon name="x" />
                    </button>
                </div>
            )}
        </VelocityShell>
    );
}

/** "Start now" on a task that starts later: it joins the ranking today. */
function StartNow({ id }: { id: number }) {
    return (
        <button
            className="btn btn-secondary btn-sm"
            onClick={(e) => {
                e.stopPropagation();
                router.patch(route('tasks.update', id), { available_from: null }, { preserveScroll: true, preserveState: true });
            }}
        >
            Start now
        </button>
    );
}
