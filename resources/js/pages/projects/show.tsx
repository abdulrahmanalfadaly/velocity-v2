import { Icon } from '@/components/velocity/icon';
import { Check, Ring } from '@/components/velocity/ui';
import VelocityShell from '@/layouts/velocity-shell';
import { Clock, dueInfo, fmt1, fmtHours, goalVar } from '@/lib/velocity';
import type { BaseTask, HabitToday, NotReadyTask, RankedTask, TaskDetail, VelocityShared } from '@/types/velocity';
import { Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface Props extends VelocityShared {
    project: {
        id: number;
        name: string;
        description: string | null;
        dueDate: string | null;
        daysLeft: number | null;
        area: string | null;
        goal: { id: number; title: string; color: number } | null;
    };
    stats: { done: number; total: number; pct: number; bestRank: number | null };
    milestones: { id: number; title: string; dueDate: string | null; done: boolean }[];
    tasks: RankedTask[];
    totalRanked: number;
    notReady: NotReadyTask[];
    done: BaseTask[];
    habits: HabitToday[];
    notes: { id: number; title: string; updatedAt: string | null }[];
    selected?: TaskDetail | null;
}

type View = 'overview' | 'tasks' | 'notes' | 'habits';

/** A soft contour-line landscape, unique to each project and tinted with its goal colour. */
function WorldArt({ seed }: { seed: string }) {
    const paths = useMemo(() => {
        let s = [...seed].reduce((a, ch) => (a * 31 + ch.charCodeAt(0)) >>> 0, 7);
        const rnd = () => (s = (s * 1664525 + 1013904223) >>> 0) / 4294967296;
        const cx = 560 + rnd() * 80;
        const cy = 70 + rnd() * 50;
        const phase = rnd() * 6;
        const out: string[] = [];
        for (let i = 1; i <= 11; i += 1) {
            const r = i * 30 + rnd() * 8;
            const pts: [number, number][] = [];
            for (let a = 0; a < 360; a += 20) {
                const rad = (a * Math.PI) / 180;
                const rr = r * (1 + 0.11 * Math.sin(rad * 3 + phase + i * 0.35) + 0.05 * Math.cos(rad * 5 + i));
                pts.push([cx + rr * Math.cos(rad) * 1.55, cy + rr * Math.sin(rad)]);
            }
            const mid = (p: [number, number], q: [number, number]) => `${((p[0] + q[0]) / 2).toFixed(1)} ${((p[1] + q[1]) / 2).toFixed(1)}`;
            let d = `M ${mid(pts[0], pts[1])}`;
            for (let k = 1; k <= pts.length; k += 1) {
                const p = pts[k % pts.length];
                const q = pts[(k + 1) % pts.length];
                d += ` Q ${p[0].toFixed(1)} ${p[1].toFixed(1)} ${mid(p, q)}`;
            }
            out.push(d);
        }
        return out;
    }, [seed]);
    return (
        <svg className="world-art" viewBox="0 0 720 240" preserveAspectRatio="xMaxYMid slice" aria-hidden="true">
            {paths.map((d, i) => (
                <path key={i} d={d} style={{ ['--i' as string]: i + 1 }} />
            ))}
        </svg>
    );
}

export default function ProjectShow() {
    const { props } = usePage<Props>();
    const clock = Clock.from(props.clock);
    const { project, stats } = props;
    const [view, setView] = useState<View>('overview');
    const [open, setOpen] = useState<Record<number, boolean>>({ [props.tasks[0]?.id ?? 0]: true });
    const [text, setText] = useState('');
    const topLevel = props.tasks.filter((t) => !t.parent || !props.tasks.some((p) => p.id === t.parent!.id));
    const childrenOf = (id: number) => props.tasks.filter((t) => t.parent?.id === id);
    const next = props.tasks[0];
    const show = (v: View) => view === 'overview' || view === v;
    const tabs: [View, string, number | null][] = [
        ['overview', 'Overview', null],
        ['tasks', 'Tasks', props.tasks.length + props.notReady.length],
        ['notes', 'Notes', props.notes.length],
        ['habits', 'Habits', props.habits.length],
    ];

    return (
        <VelocityShell title={project.name} section="tasks" active={`project:${project.id}`}>
            <div className="main-inner project" data-view={view} style={{ ['--g' as string]: goalVar(project.goal?.color) }}>
                <section className="world" aria-labelledby="world-title">
                    <WorldArt seed={`${project.id}${project.name}`} />
                    <div className="world-inner">
                        <div className="world-top">
                            {project.goal && (
                                <Link className="goal-chip" href={`/goals?focus=g${project.goal.id}`} title="See this goal in the Goals map">
                                    <i />
                                    {project.goal.title}
                                </Link>
                            )}
                            {project.area && <span className="world-area">{project.area}</span>}
                        </div>
                        <h1 className="world-title" id="world-title">
                            {project.name}
                        </h1>
                        {project.description && <p className="world-about">{project.description}</p>}
                        <div className="world-stats">
                            <div className="wstat">
                                <Ring pct={stats.pct} />
                                <div>
                                    <b>{stats.pct}%</b>
                                    <span>
                                        {stats.done} of {stats.total} tasks done
                                    </span>
                                </div>
                            </div>
                            {project.daysLeft != null && (
                                <div className="wstat">
                                    <div>
                                        <b>{project.daysLeft} days</b>
                                        <span>
                                            left · due{' '}
                                            {clock.date(project.dueDate + 'T12:00:00Z', { weekday: 'short', day: 'numeric', month: 'short' })}
                                        </span>
                                    </div>
                                </div>
                            )}
                            {stats.bestRank && (
                                <div className="wstat">
                                    <div>
                                        <b>#{stats.bestRank}</b>
                                        <span>its best task in your whole list</span>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                    {props.milestones.length > 0 && (
                        <ol className="ms" aria-label="Milestones">
                            {props.milestones.map((m) => {
                                const isNext = !m.done && props.milestones.find((x) => !x.done)?.id === m.id;
                                return (
                                    <li key={m.id} className={`ms-item is-${m.done ? 'done' : isNext ? 'next' : 'todo'}`}>
                                        <span className="ms-dot">{m.done && <Icon name="check" />}</span>
                                        <b>{m.title}</b>
                                        <span>
                                            {isNext ? 'Next · ' : ''}
                                            {m.dueDate ? clock.date(m.dueDate + 'T12:00:00Z') : ''}
                                        </span>
                                    </li>
                                );
                            })}
                        </ol>
                    )}
                </section>

                <div className="ptabs" role="tablist" aria-label="Inside this project">
                    {tabs.map(([k, label, n]) => (
                        <button key={k} role="tab" aria-selected={view === k} onClick={() => setView(k)}>
                            {label}
                            {n != null && <span> {n}</span>}
                        </button>
                    ))}
                    <span className="ptabs-gap" />
                    <Link className="btn btn-ghost btn-sm" href={`/goals?focus=p${project.id}`}>
                        <Icon name="target" className="ic-sm" />
                        See it in the Goals map
                    </Link>
                </div>

                <div className="pgrid">
                    <div className="col">
                        {next && show('tasks') && (
                            <section className="card pnext" aria-label="Next action in this project">
                                <div className="pnext-top">
                                    <span className="pill-label">
                                        <Icon name="zap" />
                                        Next here
                                    </span>
                                    <span className="pnext-rank">
                                        #{next.rank} · <b>{fmt1(next.score)}</b>
                                    </span>
                                </div>
                                <h2 className="pnext-title">{next.title}</h2>
                                <div className="meta">
                                    <span className={`chip ${dueInfo(next, clock).cls}`}>
                                        <Icon name="clock" />
                                        {dueInfo(next, clock).text}
                                    </span>
                                    <span className="dotsep" />
                                    <span>{fmtHours(next.estHours)} of work</span>
                                </div>
                                <div className="hero-actions">
                                    <Link className="btn btn-primary" href={`/tasks?task=${next.id}`}>
                                        <Icon name="play" />
                                        Open and start
                                    </Link>
                                </div>
                            </section>
                        )}
                        {show('tasks') && (
                            <section className="card" aria-labelledby="ptasks-title">
                                <div className="card-head">
                                    <h2 className="card-title" id="ptasks-title">
                                        <Icon name="tasks" />
                                        Tasks
                                    </h2>
                                    <span className="card-sub">Same order as your Up next list</span>
                                </div>
                                <form
                                    className="ptasks-add"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        if (text.trim())
                                            router.post(
                                                route('tasks.store'),
                                                { text, project_id: project.id },
                                                { preserveScroll: true, onSuccess: () => setText('') },
                                            );
                                    }}
                                >
                                    <label className="add-row">
                                        <Icon name="plus" />
                                        <input
                                            value={text}
                                            onChange={(e) => setText(e.target.value)}
                                            placeholder={`Add a task to ${project.name}`}
                                            aria-label={`Add a task to ${project.name}`}
                                        />
                                        <kbd>Enter</kbd>
                                    </label>
                                </form>
                                <ul className="ptree">
                                    {topLevel.length === 0 && <li className="pt-empty">No open tasks. Add one above.</li>}
                                    {topLevel.map((t) => {
                                        const kids = childrenOf(t.id);
                                        const d = dueInfo(t, clock);
                                        const isOpen = !!open[t.id];
                                        return (
                                            <li key={t.id} className={`pt${isOpen ? ' is-open' : ''}`}>
                                                <div className="pt-row" onClick={() => router.visit(`/tasks?task=${t.id}`)}>
                                                    {t.steps.length || kids.length ? (
                                                        <button
                                                            className="pt-caret"
                                                            aria-expanded={isOpen}
                                                            aria-label="Show steps"
                                                            onClick={(e) => {
                                                                e.stopPropagation();
                                                                setOpen((o) => ({ ...o, [t.id]: !o[t.id] }));
                                                            }}
                                                        >
                                                            <Icon name="chevR" />
                                                        </button>
                                                    ) : (
                                                        <span className="pt-caret-gap" />
                                                    )}
                                                    <Check
                                                        done={false}
                                                        label={`Mark “${t.title}” done`}
                                                        onClick={() => router.post(route('tasks.complete', t.id), {}, { preserveScroll: true })}
                                                    />
                                                    <span className="rank-badge">{t.rank}</span>
                                                    <div className="pt-main">
                                                        <div className="row-title">{t.title}</div>
                                                        <div className="row-sub">
                                                            <span className={d.late ? 'is-late' : ''}>{d.text}</span>
                                                            <span className="dotsep" />
                                                            <span>{fmtHours(t.estHours)}</span>
                                                            {t.steps.length > 0 && (
                                                                <>
                                                                    <span className="dotsep" />
                                                                    <span>
                                                                        {t.steps.filter((s) => s.done).length}/{t.steps.length} steps
                                                                    </span>
                                                                </>
                                                            )}
                                                            {t.status === 'doing' && <span className="chip is-doing">Doing</span>}
                                                        </div>
                                                    </div>
                                                    <span className="score-num">{fmt1(t.score)}</span>
                                                </div>
                                                {(t.steps.length > 0 || kids.length > 0) && (
                                                    <ul className="pt-steps">
                                                        {t.steps.map((s) => (
                                                            <li key={s.id} className={`pt-step${s.done ? ' is-done' : ''}`}>
                                                                <Check
                                                                    done={s.done}
                                                                    label="Step done"
                                                                    onClick={() =>
                                                                        router.patch(
                                                                            route('steps.update', s.id),
                                                                            { done: !s.done },
                                                                            { preserveScroll: true, preserveState: true },
                                                                        )
                                                                    }
                                                                />
                                                                <span className="pt-step-title">{s.title}</span>
                                                                <button
                                                                    className="pt-promote"
                                                                    onClick={() =>
                                                                        router.post(
                                                                            route('steps.promote', s.id),
                                                                            {},
                                                                            { preserveScroll: true, preserveState: true },
                                                                        )
                                                                    }
                                                                >
                                                                    <Icon name="rank" className="ic-xs" />
                                                                    Make it a task
                                                                </button>
                                                            </li>
                                                        ))}
                                                        {kids.map((c) => (
                                                            <li
                                                                key={`c${c.id}`}
                                                                className="pt-step pt-subtask"
                                                                onClick={() => router.visit(`/tasks?task=${c.id}`)}
                                                            >
                                                                <Check
                                                                    done={false}
                                                                    label={`Mark “${c.title}” done`}
                                                                    onClick={() =>
                                                                        router.post(route('tasks.complete', c.id), {}, { preserveScroll: true })
                                                                    }
                                                                />
                                                                <span className="rank-badge">{c.rank}</span>
                                                                <span className="pt-step-title">{c.title}</span>
                                                            </li>
                                                        ))}
                                                    </ul>
                                                )}
                                            </li>
                                        );
                                    })}
                                </ul>
                                {props.notReady.length > 0 && (
                                    <>
                                        <div className="pt-group">Not ready yet</div>
                                        <ul className="ptree">
                                            {props.notReady.map((t) => (
                                                <li key={t.id} className="pt">
                                                    <div
                                                        className="pt-row is-quiet"
                                                        onClick={() => router.visit(`/tasks?task=${t.id}`)}
                                                        style={{ cursor: 'pointer' }}
                                                    >
                                                        <span className="pt-caret-gap" />
                                                        <span className="att-ic">
                                                            <Icon
                                                                name={t.kind === 'waiting' ? 'hourglass' : t.kind === 'blocked' ? 'lock' : 'calendar'}
                                                            />
                                                        </span>
                                                        <span className="rank-badge is-none">–</span>
                                                        <div className="pt-main">
                                                            <div className="row-title">{t.title}</div>
                                                            <div className="row-sub">{t.why}</div>
                                                        </div>
                                                        <span />
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    </>
                                )}
                                {props.done.length > 0 && (
                                    <details className="pt-done">
                                        <summary>
                                            <Icon name="chevR" className="chev" />
                                            Done <span>· {props.done.length}</span>
                                        </summary>
                                        <ul className="ptree">
                                            {props.done.map((t) => (
                                                <li key={t.id} className="pt">
                                                    <div className="pt-row is-quiet is-done">
                                                        <span className="pt-caret-gap" />
                                                        <Check
                                                            done
                                                            label={`Mark “${t.title}” not done`}
                                                            onClick={() => router.post(route('tasks.reopen', t.id), {}, { preserveScroll: true })}
                                                        />
                                                        <span className="rank-badge is-none">–</span>
                                                        <div className="pt-main">
                                                            <div className="row-title">{t.title}</div>
                                                            <div className="row-sub">
                                                                {t.completedAt ? `Done ${clock.date(t.completedAt)}` : 'Done'}
                                                            </div>
                                                        </div>
                                                        <span />
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    </details>
                                )}
                            </section>
                        )}
                    </div>
                    <div className="col">
                        {show('habits') && (
                            <section className="card" aria-labelledby="phab-title">
                                <div className="card-head">
                                    <h2 className="card-title" id="phab-title">
                                        <Icon name="habits" />
                                        Habits for this project
                                    </h2>
                                </div>
                                <div style={{ paddingBottom: 6 }}>
                                    {props.habits.length === 0 && (
                                        <p className="card-empty">No habits yet. A small daily habit keeps a big project moving.</p>
                                    )}
                                    {props.habits.map((h) => (
                                        <div className="habit" key={h.id}>
                                            <span className={`habit-btn${h.doneToday ? ' is-done' : ''}`}>
                                                <Icon name="check" />
                                            </span>
                                            <div>
                                                <div className={`habit-name${h.doneToday ? ' is-done' : ''}`}>{h.name}</div>
                                                <div className="habit-detail">
                                                    {h.doneToday
                                                        ? 'Done today'
                                                        : h.target > 1
                                                          ? `${h.today} of ${h.target} today`
                                                          : 'Not done yet today'}
                                                </div>
                                            </div>
                                            <span className="streak" title={`${h.streak}-day streak`}>
                                                <Icon name="flame" />
                                                {h.streak}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        )}
                        {show('notes') && (
                            <section className="card" aria-labelledby="pnotes-title">
                                <div className="card-head">
                                    <h2 className="card-title" id="pnotes-title">
                                        <Icon name="notes" />
                                        Notes
                                    </h2>
                                </div>
                                <div className="pnotes">
                                    {props.notes.length === 0 && <p className="card-empty">No notes yet.</p>}
                                    {props.notes.map((n) => (
                                        <div className="pnote" key={n.id}>
                                            <span className="pnote-fold" />
                                            <b>{n.title}</b>
                                            <span>{n.updatedAt ? `Edited ${clock.date(n.updatedAt)}` : ''}</span>
                                        </div>
                                    ))}
                                </div>
                                <div style={{ height: 12 }} />
                            </section>
                        )}
                    </div>
                </div>
            </div>
        </VelocityShell>
    );
}
