import { ExplainPanel } from '@/components/fyp/explain-panel';
import { TaskFormOverlay, type FormTask } from '@/components/fyp/task-form';
import { CRITERION } from '@/components/fyp/terms';
import { Icon } from '@/components/velocity/icon';
import { Check, Dots, PriorityBar, ScoreCell } from '@/components/velocity/ui';
import { useMediaQuery } from '@/hooks/use-media-query';
import FypShell from '@/layouts/fyp-shell';
import { adjustWeights, Clock, CRIT, DEFAULT_WEIGHTS, dueInfo, fmt1, fmtHours, rerank, sameWeights, type WeightSet } from '@/lib/velocity';
import type { BaseTask, CriterionKey, RankedTask, RankingSettings, VelocityShared } from '@/types/velocity';
import { router, usePage } from '@inertiajs/react';
import { Fragment, useEffect, useMemo, useRef, useState } from 'react';

interface Props extends VelocityShared {
    tasks: RankedTask[];
    settings: RankingSettings;
    completed: BaseTask[];
}

/**
 * My tasks (FR4–FR9): the top three, every open task in one ranked list,
 * the explanation of any task, and weight sliders that re-rank as you drag.
 */
export default function FypTasks() {
    const { props } = usePage<Props>();
    const clock = Clock.from(props.clock);
    const wide = useMediaQuery('(min-width: 1360px)');
    const [weights, setWeights] = useState<WeightSet>(props.settings.weights);
    const [picked, setPicked] = useState<number | null | undefined>(() => {
        const id = Number(new URLSearchParams(window.location.search).get('task'));
        return id || undefined;
    });
    const [form, setForm] = useState<FormTask | 'new' | null>(null);
    const [popOpen, setPopOpen] = useState(false);
    const snapshot = useRef(weights);
    const saveTimer = useRef<ReturnType<typeof setTimeout>>(undefined);
    useEffect(() => setWeights(props.settings.weights), [props.settings.weights]);

    const fresh = sameWeights(weights, props.settings.weights);
    const list = useMemo(() => (fresh ? props.tasks : rerank(props.tasks, weights)), [fresh, props.tasks, weights]);
    // On wide screens the explanation of #1 is open until you pick another task (or close it).
    const selectedId = picked === undefined ? (wide ? (list[0]?.id ?? null) : null) : picked;
    const k = list.findIndex((t) => t.id === selectedId);
    const selected = k >= 0 ? list[k] : null;

    const setWeight = (key: CriterionKey, v: number) => {
        const next = adjustWeights(snapshot.current, key, v);
        setWeights(next);
        clearTimeout(saveTimer.current);
        saveTimer.current = setTimeout(
            () => router.put(route('weights.update'), { weights: next }, { preserveScroll: true, preserveState: true, only: ['tasks', 'settings'] }),
            500,
        );
    };

    const first = props.auth.user?.name.split(' ')[0] ?? '';
    const overdue = list.filter((t) => t.days != null && t.days <= 0).length;
    const today = list.filter((t) => t.dueAt && !(t.days != null && t.days <= 0) && clock.dayDiff(t.dueAt) === 0).length;
    const hours = list.reduce((s, t) => s + t.estHours, 0);
    const isNew = list.length === 0 && props.completed.length === 0;

    return (
        <FypShell
            title="My tasks"
            page="tasks"
            panel={
                selected ? (
                    <ExplainPanel
                        task={selected}
                        below={list[k + 1] ?? null}
                        total={list.length}
                        params={{ weights, horizonDays: props.settings.horizonDays, hoursPerDay: props.settings.hoursPerDay }}
                        fresh={fresh}
                        onClose={() => setPicked(null)}
                        onEdit={() => setForm(selected)}
                    />
                ) : undefined
            }
        >
            <div className="main-inner">
                <header className="page-head">
                    <div>
                        <p className="eyebrow">
                            {clock.today()}
                            {props.clock.frozen && <span className="chip fyp-frozen">clock frozen for testing</span>}
                        </p>
                        <h1 className="h-display">
                            {clock.greeting()}, {first}
                        </h1>
                    </div>
                    <ul className="fyp-summary" aria-label="Summary">
                        <li>
                            <b>{list.length}</b>
                            <span>open {list.length === 1 ? 'task' : 'tasks'}</span>
                        </li>
                        <li className={overdue ? 'is-critical' : ''}>
                            <b>{overdue}</b>
                            <span>
                                {overdue > 0 && <Icon name="alert" className="ic-xs" />}
                                overdue
                            </span>
                        </li>
                        <li>
                            <b>{today}</b>
                            <span>due today</span>
                        </li>
                        <li>
                            <b>{Number(hours.toFixed(1))}</b>
                            <span>hours planned</span>
                        </li>
                    </ul>
                </header>

                {isNew ? (
                    <section className="card fyp-welcome">
                        <h2>Your ranked list starts here</h2>
                        <p>
                            Add your coursework with its importance, urgency, due date and estimated hours. Velocity combines the four into a priority
                            score out of 100, ranks everything into one list and explains every position.
                        </p>
                        <div className="fyp-welcome-actions">
                            <button className="btn btn-primary btn-lg" onClick={() => setForm('new')}>
                                <Icon name="plus" />
                                Add your first task
                            </button>
                            <button className="btn btn-secondary btn-lg" onClick={() => router.post(route('fyp.scenario'))}>
                                Try the 10-task scenario
                            </button>
                        </div>
                        <ol className="fyp-steps">
                            <li>Rate importance and urgency from 1 to 5</li>
                            <li>Add a due date and how long it will take</li>
                            <li>Work top-down; tap any task to see why it ranks there</li>
                        </ol>
                    </section>
                ) : (
                    <>
                        {list.length > 0 && (
                            <section className="fyp-focus" aria-labelledby="focus-title">
                                <div className="fyp-focus-head">
                                    <h2 id="focus-title">
                                        <Icon name="target" />
                                        Focus now
                                    </h2>
                                    <p>Your top {Math.min(3, list.length) === 1 ? 'task' : `${Math.min(3, list.length)} tasks`} by priority score</p>
                                </div>
                                <ol className="fyp-focus-list">
                                    {list.slice(0, 3).map((t) => {
                                        const due = dueInfo(t, clock);
                                        return (
                                            <li key={t.id}>
                                                <button
                                                    className={`fyp-focus-card${selected?.id === t.id ? ' is-selected' : ''}`}
                                                    onClick={() => setPicked(t.id)}
                                                    aria-label={`Rank ${t.rank}: ${t.title}, score ${fmt1(t.score)}. Show explanation`}
                                                >
                                                    <span className="fyp-focus-top">
                                                        <span className="fyp-focus-rank">#{t.rank}</span>
                                                        <span className="fyp-focus-score">{fmt1(t.score)}</span>
                                                    </span>
                                                    <span className="fyp-focus-title">{t.title}</span>
                                                    <span className="fyp-focus-meta">
                                                        <span className={due.late ? 'is-late' : ''}>
                                                            <Icon name="clock" className="ic-xs" />
                                                            {due.text}
                                                        </span>
                                                        <span>
                                                            <Icon name="hourglass" className="ic-xs" />
                                                            {fmtHours(t.estHours)}
                                                        </span>
                                                    </span>
                                                    <PriorityBar contrib={t.contrib} size="pbar-sm" score={t.score} />
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ol>
                            </section>
                        )}

                        <section className="fyp-ranked" aria-labelledby="ranked-title">
                            <header className="fyp-ranked-head">
                                <div>
                                    <h2 id="ranked-title">All open tasks, ranked</h2>
                                    <p>Sorted by priority score · recalculated on every visit</p>
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
                                            <p className="pop-sub">
                                                Moving one slider shares the difference among the other three, so the total stays 100%. The list
                                                re-ranks as you drag.
                                            </p>
                                            {CRIT.map((c) => (
                                                <div className="w-row fyp-w-row" key={c.k}>
                                                    <label htmlFor={`w-${c.k}`}>
                                                        <i className={`sw sw-${c.k}`} />
                                                        {CRITERION[c.k]}
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
                                                <span>
                                                    {sameWeights(weights, DEFAULT_WEIGHTS)
                                                        ? 'AHP defaults (CR 0.004)'
                                                        : 'Custom weights · always sum to 100%'}{' '}
                                                    ·{' '}
                                                    <a className="link" href="/weights">
                                                        How it works
                                                    </a>
                                                </span>
                                                <button
                                                    className="link"
                                                    disabled={sameWeights(weights, DEFAULT_WEIGHTS)}
                                                    onClick={() =>
                                                        router.put(
                                                            route('weights.update'),
                                                            { weights: DEFAULT_WEIGHTS },
                                                            { preserveScroll: true, preserveState: true, only: ['tasks', 'settings'] },
                                                        )
                                                    }
                                                >
                                                    Reset
                                                </button>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </header>
                            <div className="legend fyp-legend">
                                {CRIT.map((c) => (
                                    <span key={c.k}>
                                        <i className={`sw sw-${c.k}`} />
                                        {CRITERION[c.k]}
                                    </span>
                                ))}
                            </div>

                            {list.length === 0 ? (
                                <div className="fyp-empty">
                                    <Icon name="check" />
                                    <h3>All clear</h3>
                                    <p>You have no open tasks. Add the next thing on your plate and Velocity will slot it into place.</p>
                                    <button className="btn btn-primary" onClick={() => setForm('new')}>
                                        <Icon name="plus" />
                                        New task
                                    </button>
                                </div>
                            ) : (
                                <ol className="rank-list" aria-label="Tasks in priority order">
                                    {list.map((t, i) => {
                                        const due = dueInfo(t, clock);
                                        const nx = list[i + 1];
                                        return (
                                            <Fragment key={t.id}>
                                                <li
                                                    className={`row${selected?.id === t.id ? ' is-selected' : ''}`}
                                                    tabIndex={0}
                                                    aria-label={`Rank ${t.rank}: ${t.title}, score ${fmt1(t.score)}. Show explanation`}
                                                    onClick={() => setPicked(selected?.id === t.id ? null : t.id)}
                                                    onKeyDown={(e) =>
                                                        (e.key === 'Enter' || e.key === ' ') &&
                                                        e.target === e.currentTarget &&
                                                        (e.preventDefault(), setPicked(t.id))
                                                    }
                                                >
                                                    <Check
                                                        done={false}
                                                        label={`Mark “${t.title}” as done`}
                                                        onClick={() => router.post(route('tasks.complete', t.id), {}, { preserveScroll: true })}
                                                    />
                                                    <span className="rank">{t.rank}</span>
                                                    <div>
                                                        <div className="row-title">{t.title}</div>
                                                        <div className="row-sub">
                                                            <span>{fmtHours(t.estHours)}</span>
                                                            <span className="fyp-pips" title={`Importance ${t.importance} of 5`}>
                                                                Imp <Dots n={t.importance} c="I" />
                                                            </span>
                                                            <span className="fyp-pips" title={`Urgency ${t.urgency} of 5`}>
                                                                Urg <Dots n={t.urgency} c="U" />
                                                            </span>
                                                            <span className={`chip due-mobile ${due.cls}`}>{due.text}</span>
                                                        </div>
                                                    </div>
                                                    <span className={`chip ${due.cls}`}>
                                                        <Icon name="clock" />
                                                        {due.text}
                                                    </span>
                                                    <ScoreCell score={t.score} contrib={t.contrib} />
                                                </li>
                                                {t.nearTie && nx && (
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
                            )}
                        </section>

                        {props.completed.length > 0 && (
                            <details className="section-fold">
                                <summary>
                                    <Icon name="chevR" className="chev" />
                                    Recently completed{' '}
                                    <span className="hint">
                                        · {props.completed.length} shown ·{' '}
                                        <a className="link" href="/history">
                                            see history
                                        </a>
                                    </span>
                                </summary>
                                {props.completed.map((d) => {
                                    const onTime = d.dueAt && d.completedAt ? d.completedAt <= d.dueAt : null;
                                    const days = d.completedAt ? clock.dayDiff(d.completedAt) : 0;
                                    return (
                                        <div className="fold-row" key={d.id}>
                                            <span className="att-ic">
                                                <Icon name="check" />
                                            </span>
                                            <div>
                                                <div className="row-title" style={{ color: 'var(--text-2)' }}>
                                                    {d.title}
                                                </div>
                                                <div className="row-sub">
                                                    <span>
                                                        {days === 0
                                                            ? `Today, ${clock.time(d.completedAt!)}`
                                                            : days === -1
                                                              ? 'Yesterday'
                                                              : `${-days} days ago`}
                                                    </span>
                                                    {onTime === true && <span className="fyp-ontime">on time</span>}
                                                    {onTime === false && <span className="is-late">late</span>}
                                                    {d.scoreAtCompletion != null && <span>score {fmt1(d.scoreAtCompletion)}</span>}
                                                </div>
                                            </div>
                                            <button
                                                className="btn btn-ghost btn-sm"
                                                onClick={() => router.post(route('tasks.reopen', d.id), {}, { preserveScroll: true })}
                                            >
                                                Reopen
                                            </button>
                                        </div>
                                    );
                                })}
                            </details>
                        )}
                    </>
                )}
            </div>
            {form && <TaskFormOverlay task={form === 'new' ? undefined : form} onClose={() => setForm(null)} />}
        </FypShell>
    );
}
