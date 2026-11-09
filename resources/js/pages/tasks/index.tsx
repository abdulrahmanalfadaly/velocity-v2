import { Icon } from '@/components/velocity/icon';
import { TaskPanel } from '@/components/velocity/task-panel';
import { Check } from '@/components/velocity/ui';
import VelocityShell from '@/layouts/velocity-shell';
import { adjustWeights, Clock, CRIT, dueInfo, fmt1, fmtHours, rerank, sameWeights } from '@/lib/velocity';
import type { BaseTask, CriterionKey, NotReadyTask, RankedTask, RankingSettings, TaskDetail, VelocityShared } from '@/types/velocity';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

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

    const list = useMemo(
        () => (sameWeights(weights, props.settings.weights) ? props.tasks : rerank(props.tasks, weights)),
        [props.tasks, props.settings.weights, weights],
    );

    const fn = FILTERS.find((f) => f[0] === filter)![2];
    const shown = list.filter((t) => fn(t, clock));
    const open = (id: number | null) =>
        router.get('/tasks', id ? { task: id } : {}, { preserveState: true, preserveScroll: true, only: ['selected'] });

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
            title="Up next"
            section="tasks"
            active="upnext"
            panel={
                props.selected ? <TaskPanel task={props.selected} clock={clock} projects={props.projects} onClose={() => open(null)} /> : undefined
            }
        >
            <div className="main-inner">
                <header className="page-head">
                    <div>
                        <p className="eyebrow is-section">Tasks</p>
                        <h1 className="h1">
                            Up next <span className="count">{list.length} tasks</span>
                        </h1>
                    </div>
                    <div className="page-actions">
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
                                            onClick={() => router.delete(route('weights.reset'), { preserveScroll: true, preserveState: true })}
                                        >
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
                        <input value={text} onChange={(e) => setText(e.target.value)} placeholder="Add a task" aria-label="Add a task" />
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
                    {shown.length === 0 && (
                        <li className="fold-row" style={{ border: 0 }}>
                            Nothing here right now.
                        </li>
                    )}
                    {shown.map((t) => {
                        const due = dueInfo(t, clock);
                        const done = t.steps.filter((s) => s.done).length;
                        return (
                            <li
                                key={t.id}
                                className={`row${props.selected?.id === t.id ? ' is-selected' : ''}`}
                                tabIndex={0}
                                aria-label={`#${t.rank}, ${t.title}, priority ${fmt1(t.score)}`}
                                onClick={() => open(props.selected?.id === t.id ? null : t.id)}
                                onKeyDown={(e) =>
                                    (e.key === 'Enter' || e.key === ' ') && e.target === e.currentTarget && (e.preventDefault(), open(t.id))
                                }
                            >
                                <Check
                                    done={false}
                                    label={`Mark “${t.title}” done`}
                                    onClick={() => router.post(route('tasks.complete', t.id), {}, { preserveScroll: true })}
                                />
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
                                <span className="score-num">{fmt1(t.score)}</span>
                            </li>
                        );
                    })}
                </ol>

                {props.notReady.length > 0 && (
                    <details className="section-fold" open id="not-ready">
                        <summary>
                            <Icon name="chevR" className="chev" />
                            Not ready yet <span className="hint">· {props.notReady.length}</span>
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
                                <Check
                                    done
                                    label={`Mark “${d.title}” not done`}
                                    onClick={() => router.post(route('tasks.reopen', d.id), {}, { preserveScroll: true })}
                                />
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
