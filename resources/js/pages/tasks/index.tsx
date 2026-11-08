import { Icon } from '@/components/velocity/icon';
import { TaskPanel } from '@/components/velocity/task-panel';
import { Check, ScoreCell } from '@/components/velocity/ui';
import VelocityShell from '@/layouts/velocity-shell';
import { Clock, CRIT, dueInfo, fmt1, fmtHours } from '@/lib/velocity';
import type { BaseTask, CriterionKey, NotReadyTask, RankedTask, RankingSettings, TaskDetail, VelocityShared } from '@/types/velocity';
import { router, usePage } from '@inertiajs/react';
import { Fragment, useEffect, useMemo, useRef, useState } from 'react';

interface Props extends VelocityShared {
    tasks: RankedTask[];
    settings: RankingSettings;
    notReady: NotReadyTask[];
    doneToday: BaseTask[];
    selected: TaskDetail | null;
    projects: { id: number; name: string }[];
}

type Filter = 'all' | 'week' | 'quick' | 'overdue' | 'inbox';
const FILTERS: [Filter, string, (t: RankedTask, c: Clock) => boolean][] = [
    ['all', 'All', () => true],
    ['week', 'Due this week', (t, c) => !!t.dueAt && c.dayDiff(t.dueAt) <= 7],
    ['quick', '1 hour or less', (t) => t.estHours <= 1],
    ['overdue', 'Overdue', (t) => t.days != null && t.days <= 0],
    ['inbox', 'No project', (t) => !t.project],
];

/** Proportional redistribution with largest-remainder rounding (FR9), as in the report. */
function adjust(base: Record<CriterionKey, number>, key: CriterionKey, v: number): Record<CriterionKey, number> {
    const others = CRIT.map((c) => c.k).filter((k) => k !== key);
    const rest = 100 - v;
    const sum = others.reduce((s, k) => s + base[k], 0);
    const raw = Object.fromEntries(others.map((k) => [k, sum > 0 ? (base[k] / sum) * rest : rest / others.length])) as Record<CriterionKey, number>;
    const out = { [key]: v } as Record<CriterionKey, number>;
    let used = v;
    others.forEach((k) => {
        out[k] = Math.floor(raw[k]);
        used += out[k];
    });
    others
        .slice()
        .sort((a, b) => raw[b] - out[b] - (raw[a] - out[a]))
        .forEach((k) => {
            if (used < 100) {
                out[k] += 1;
                used += 1;
            }
        });
    return out;
}

const same = (a: Record<CriterionKey, number>, b: Record<CriterionKey, number>) => CRIT.every((c) => a[c.k] === b[c.k]);

export default function TasksIndex() {
    const { props } = usePage<Props>();
    const clock = Clock.from(props.clock);
    const [filter, setFilter] = useState<Filter>('all');
    const [weights, setWeights] = useState(props.settings.weights);
    const [popOpen, setPopOpen] = useState(false);
    const [text, setText] = useState('');
    const snapshot = useRef(weights);
    const saveTimer = useRef<ReturnType<typeof setTimeout>>(undefined);
    useEffect(() => setWeights(props.settings.weights), [props.settings.weights]);

    // While a slider moves, re-rank in the browser with S = Σ w·c; the server confirms on save.
    const list = useMemo(() => {
        if (same(weights, props.settings.weights)) return props.tasks;
        const score = (t: RankedTask) => CRIT.reduce((s, c) => s + weights[c.k] * t.criteria[c.k], 0);
        return props.tasks
            .map((t) => ({ ...t, score: score(t), contrib: Object.fromEntries(CRIT.map((c) => [c.k, weights[c.k] * t.criteria[c.k]])) as Record<CriterionKey, number> }))
            .sort((a, b) => b.score - a.score || (a.dueAt ?? '9999').localeCompare(b.dueAt ?? '9999') || a.estHours - b.estHours || a.id - b.id)
            .map((t, i, all) => ({ ...t, rank: i + 1, nearTie: i < all.length - 1 && Math.round((t.score - all[i + 1].score) * 10) / 10 < 1 }));
    }, [props.tasks, props.settings.weights, weights]);

    const fn = FILTERS.find((f) => f[0] === filter)![2];
    const shown = list.filter((t) => fn(t, clock));
    const open = (id: number | null) =>
        router.get('/tasks', id ? { task: id } : {}, { preserveState: true, preserveScroll: true, only: ['selected'] });

    const setWeight = (k: CriterionKey, v: number) => {
        const next = adjust(snapshot.current, k, v);
        setWeights(next);
        clearTimeout(saveTimer.current);
        saveTimer.current = setTimeout(() => router.put(route('weights.update'), { weights: next }, { preserveScroll: true, preserveState: true, only: ['tasks', 'settings', 'selected', 'nav'] }), 500);
    };

    return (
        <VelocityShell
            title="Up next"
            section="tasks"
            active="upnext"
            panel={props.selected ? <TaskPanel task={props.selected} clock={clock} projects={props.projects} onClose={() => open(null)} /> : undefined}
        >
            <div className="main-inner">
                <header className="page-head">
                    <div>
                        <p className="eyebrow is-section">Tasks</p>
                        <h1 className="h1">
                            Up next <span className="count">{list.length} tasks</span>
                        </h1>
                        <p className="page-sub">
                            Ranked by your priority formula:{' '}
                            {CRIT.map((c, i) => (
                                <Fragment key={c.k}>
                                    {i > 0 && ' · '}
                                    <span style={{ whiteSpace: 'nowrap' }}>
                                        <i className={`sw sw-${c.k}`} /> {c.name.toLowerCase()} {weights[c.k]}%
                                    </span>
                                </Fragment>
                            ))}
                            {!same(weights, { I: 42, U: 23, D: 23, P: 12 }) && (
                                <span className="chip is-doing" style={{ marginLeft: 6 }}>
                                    Custom
                                </span>
                            )}
                        </p>
                    </div>
                    <div className="page-actions">
                        <div className="pop-wrap">
                            <button className="btn btn-secondary" aria-expanded={popOpen} aria-controls="weights-pop" onClick={() => setPopOpen((o) => !o)}>
                                <Icon name="sliders" />
                                <span className="btn-label">Weights</span>
                            </button>
                            {popOpen && (
                                <div className="popover" id="weights-pop" role="dialog" aria-label="Priority weights">
                                    <p className="pop-title">What matters most to you?</p>
                                    <p className="pop-sub">Move one slider and the others adjust, so the total stays 100%. The list re-ranks as you drag.</p>
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
                                        <span>Defaults come from your report (AHP)</span>
                                        <button className="link" onClick={() => router.delete(route('weights.reset'), { preserveScroll: true, preserveState: true })}>
                                            Reset
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </header>

                <div className="toolbar">
                    <form
                        className="add-row"
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (text.trim()) router.post(route('tasks.store'), { text }, { preserveScroll: true, onSuccess: () => setText('') });
                        }}
                    >
                        <Icon name="plus" />
                        <input value={text} onChange={(e) => setText(e.target.value)} placeholder="Add a task — try “Ethics essay fri 3h #fyp”" aria-label="Add a task" />
                        <kbd>Enter</kbd>
                    </form>
                    <div className="filters" role="group" aria-label="Show">
                        {FILTERS.map(([k, label, f]) => (
                            <button key={k} className="filter" aria-pressed={filter === k} onClick={() => setFilter(k)}>
                                {label} <span className="n">{list.filter((t) => f(t, clock)).length}</span>
                            </button>
                        ))}
                    </div>
                </div>

                <ol className="rank-list" aria-label="Tasks in priority order">
                    {shown.length === 0 && <li className="fold-row" style={{ border: 0 }}>Nothing here right now.</li>}
                    {shown.map((t, i) => {
                        const due = dueInfo(t, clock);
                        const nx = shown[i + 1];
                        const done = t.steps.filter((s) => s.done).length;
                        return (
                            <Fragment key={t.id}>
                                <li
                                    className={`row${props.selected?.id === t.id ? ' is-selected' : ''}`}
                                    tabIndex={0}
                                    aria-label={`#${t.rank}, ${t.title}, priority ${fmt1(t.score)}`}
                                    onClick={() => open(props.selected?.id === t.id ? null : t.id)}
                                    onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && e.target === e.currentTarget && (e.preventDefault(), open(t.id))}
                                >
                                    <Check done={false} label={`Mark “${t.title}” done`} onClick={() => router.post(route('tasks.complete', t.id), {}, { preserveScroll: true })} />
                                    <span className="rank">{t.rank}</span>
                                    <div>
                                        <div className="row-title">{t.title}</div>
                                        <div className="row-sub">
                                            <span>{t.project?.name ?? 'No project'}</span>
                                            <span className="dotsep" />
                                            <span>{fmtHours(t.estHours)}</span>
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
                                    <ScoreCell score={t.score} contrib={t.contrib} />
                                </li>
                                {t.nearTie && nx && nx.rank === t.rank + 1 && (
                                    <li className="tie" aria-hidden="true">
                                        <Icon name="info" />
                                        <span>
                                            <b>
                                                #{t.rank} and #{nx.rank} are almost tied
                                            </b>{' '}
                                            ({fmt1(t.score - nx.score)} apart). Either is a good choice.
                                        </span>
                                    </li>
                                )}
                            </Fragment>
                        );
                    })}
                </ol>

                {props.notReady.length > 0 && (
                    <details className="section-fold" open id="not-ready">
                        <summary>
                            <Icon name="chevR" className="chev" />
                            Not ready yet <span className="hint">· {props.notReady.length} · not ranked until they can start</span>
                        </summary>
                        {props.notReady.map((w) => (
                            <div className="fold-row" key={w.id} onClick={() => open(w.id)} style={{ cursor: 'pointer' }}>
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
                                <span />
                            </div>
                        ))}
                    </details>
                )}
                {props.doneToday.length > 0 && (
                    <details className="section-fold">
                        <summary>
                            <Icon name="chevR" className="chev" />
                            Done today <span className="hint">· {props.doneToday.length}</span>
                        </summary>
                        {props.doneToday.map((d) => (
                            <div className="fold-row" key={d.id}>
                                <Check done label={`Mark “${d.title}” not done`} onClick={() => router.post(route('tasks.reopen', d.id), {}, { preserveScroll: true })} />
                                <div>
                                    <div className="row-title" style={{ color: 'var(--text-3)' }}>
                                        {d.title}
                                    </div>
                                    <div className="row-sub">
                                        {d.project?.name ?? 'No project'} <span className="dotsep" /> {d.completedAt ? clock.time(d.completedAt) : ''}
                                    </div>
                                </div>
                                <span />
                            </div>
                        ))}
                    </details>
                )}
            </div>
        </VelocityShell>
    );
}
