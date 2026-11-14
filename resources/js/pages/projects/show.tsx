import { StartFocus } from '@/components/velocity/focus';
import { ProjectDialog } from '@/components/velocity/forms';
import { HabitDialog } from '@/components/velocity/habit-parts';
import { Icon } from '@/components/velocity/icon';
import { Picker } from '@/components/velocity/pick';
import { TaskPanel } from '@/components/velocity/task-panel';
import { Check, HabitButton, Ring } from '@/components/velocity/ui';
import VelocityShell from '@/layouts/velocity-shell';
import { Clock, dueInfo, fmt1, fmtHours, goalVar } from '@/lib/velocity';
import type { BaseTask, HabitToday, NotReadyTask, RankedTask, TaskDetail, VelocityShared } from '@/types/velocity';
import { Link, router, usePage } from '@inertiajs/react';
import { type KeyboardEvent, useMemo, useState } from 'react';

const keep = { preserveScroll: true, preserveState: true };
/** Enter or Space on a focused row opens it, like a click. */
const onEnter = (fn: () => void) => (e: KeyboardEvent) => {
    if ((e.key === 'Enter' || e.key === ' ') && e.target === e.currentTarget) {
        e.preventDefault();
        fn();
    }
};

interface Props extends VelocityShared {
    project: {
        id: number;
        name: string;
        description: string | null;
        dueDate: string | null;
        daysLeft: number | null;
        area: string | null;
        areaId: number | null;
        goal: { id: number; title: string; color: number } | null;
        archived: boolean;
    };
    stats: { done: number; total: number; pct: number; bestRank: number | null; focusedWeek: number };
    people: { id: number; name: string; initials: string; relationship: string | null }[];
    peopleChoices: { id: number; name: string }[];
    milestones: { id: number; title: string; dueDate: string | null; done: boolean }[];
    tasks: RankedTask[];
    totalRanked: number;
    notReady: NotReadyTask[];
    done: BaseTask[];
    habits: HabitToday[];
    notes: { id: number; title: string; updatedAt: string | null }[];
    selected: TaskDetail | null;
    projects: { id: number; name: string }[];
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
    const [editing, setEditing] = useState(false);
    const [newHabit, setNewHabit] = useState(false);
    const [milestone, setMilestone] = useState<{ title: string; due: string } | null>(null);
    const select = (id: number | null) =>
        router.get(`/projects/${project.id}`, id ? { task: id } : {}, { preserveState: true, preserveScroll: true, only: ['selected'] });
    const days = project.daysLeft;
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
        <VelocityShell
            title={project.name}
            section="tasks"
            active={`project:${project.id}`}
            panel={
                props.selected ? (
                    <TaskPanel key={props.selected.id} task={props.selected} clock={clock} projects={props.projects} onClose={() => select(null)} />
                ) : undefined
            }
            onEscape={props.selected ? () => select(null) : undefined}
        >
            <div className="main-inner project" data-view={view} style={{ ['--g' as string]: goalVar(project.goal?.color) }}>
                {project.archived && (
                    <div className="archived-bar" role="status">
                        <Icon name="archive" />
                        <span>This project is archived.</span>
                        <button className="btn btn-secondary btn-sm" onClick={() => router.post(route('projects.unarchive', project.id), {}, keep)}>
                            Restore
                        </button>
                    </div>
                )}
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
                            <span className="world-gap" />
                            <button className="btn btn-ghost btn-sm" onClick={() => setEditing(true)}>
                                <Icon name="settings" className="ic-sm" />
                                Edit project
                            </button>
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
                            {days != null && (
                                <div className="wstat">
                                    <div>
                                        <b className={days < 0 ? 'is-late' : ''}>
                                            {days > 0 ? `${days} ${days === 1 ? 'day' : 'days'}` : days === 0 ? 'Today' : 'Overdue'}
                                        </b>
                                        <span>
                                            {days > 0
                                                ? 'left · due '
                                                : days === 0
                                                  ? 'due '
                                                  : `by ${-days} ${days === -1 ? 'day' : 'days'} · was due `}
                                            {clock.date(project.dueDate + 'T12:00:00Z', { weekday: 'short', day: 'numeric', month: 'short' })}
                                        </span>
                                    </div>
                                </div>
                            )}
                            {stats.focusedWeek > 0 && (
                                <div className="wstat">
                                    <div>
                                        <b>{fmtHours(stats.focusedWeek / 3600)}</b>
                                        <span>focused this week</span>
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
                                        <button
                                            type="button"
                                            className="ms-dot"
                                            aria-pressed={m.done}
                                            aria-label={m.done ? `Mark “${m.title}” not reached` : `Mark “${m.title}” reached`}
                                            onClick={() => router.patch(route('milestones.update', m.id), { done: !m.done }, keep)}
                                        >
                                            {m.done && <Icon name="check" />}
                                        </button>
                                        <b>{m.title}</b>
                                        <span>
                                            {isNext ? 'Next · ' : ''}
                                            {m.dueDate ? clock.date(m.dueDate + 'T12:00:00Z') : ''}
                                        </span>
                                        <button
                                            type="button"
                                            className="ms-remove"
                                            aria-label={`Remove milestone “${m.title}”`}
                                            title="Remove"
                                            onClick={() => router.delete(route('milestones.destroy', m.id), keep)}
                                        >
                                            <Icon name="x" className="ic-xs" />
                                        </button>
                                    </li>
                                );
                            })}
                        </ol>
                    )}
                    <div className="ms-add">
                        {milestone ? (
                            <form
                                className="ms-form"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    if (!milestone.title.trim()) return;
                                    const data = { title: milestone.title, due_date: milestone.due || null };
                                    setMilestone(null);
                                    router.post(route('milestones.store', project.id), data, keep);
                                }}
                            >
                                <input
                                    className="input"
                                    autoFocus
                                    placeholder="Milestone"
                                    aria-label="Milestone"
                                    maxLength={120}
                                    value={milestone.title}
                                    onChange={(e) => setMilestone({ ...milestone, title: e.target.value })}
                                />
                                <input
                                    className="input"
                                    type="date"
                                    aria-label="Milestone date"
                                    value={milestone.due}
                                    onChange={(e) => setMilestone({ ...milestone, due: e.target.value })}
                                />
                                <button className="btn btn-primary btn-sm" type="submit">
                                    Add
                                </button>
                                <button className="btn btn-ghost btn-sm" type="button" onClick={() => setMilestone(null)}>
                                    Cancel
                                </button>
                            </form>
                        ) : (
                            <button className="btn btn-ghost btn-sm" onClick={() => setMilestone({ title: '', due: '' })}>
                                <Icon name="flag" className="ic-sm" />
                                Add a milestone
                            </button>
                        )}
                    </div>
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
                                    <StartFocus taskId={next.id} className="btn btn-primary" />
                                    <button className="btn btn-secondary" onClick={() => select(next.id)}>
                                        Open
                                    </button>
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
                                        if (!text.trim()) return;
                                        setText(''); // cleared first, so a fast second Enter can't add it twice
                                        router.post(
                                            route('tasks.store'),
                                            { text, project_id: project.id },
                                            { ...keep, onError: () => setText(text) },
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
                                                <div
                                                    className={`pt-row${props.selected?.id === t.id ? ' is-selected' : ''}`}
                                                    tabIndex={0}
                                                    onClick={() => select(props.selected?.id === t.id ? null : t.id)}
                                                    onKeyDown={onEnter(() => select(t.id))}
                                                >
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
                                                        onClick={() => router.post(route('tasks.complete', t.id), {}, keep)}
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
                                                                tabIndex={0}
                                                                onClick={() => select(c.id)}
                                                                onKeyDown={onEnter(() => select(c.id))}
                                                            >
                                                                <Check
                                                                    done={false}
                                                                    label={`Mark “${c.title}” done`}
                                                                    onClick={() => router.post(route('tasks.complete', c.id), {}, keep)}
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
                                                        className={`pt-row is-quiet${props.selected?.id === t.id ? ' is-selected' : ''}`}
                                                        tabIndex={0}
                                                        onClick={() => select(t.id)}
                                                        onKeyDown={onEnter(() => select(t.id))}
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
                                                            onClick={() => router.post(route('tasks.reopen', t.id), {}, keep)}
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
                        {view === 'overview' && (
                            <section className="card" aria-labelledby="ppl-title">
                                <div className="card-head">
                                    <h2 className="card-title" id="ppl-title">
                                        <Icon name="people" />
                                        People
                                    </h2>
                                    <Picker
                                        label="Add person"
                                        find="Find a person"
                                        none="No one matches. Add people in People."
                                        options={props.peopleChoices.map((p) => ({ id: p.id, title: p.name }))}
                                        taken={props.people.map((p) => p.id)}
                                        onPick={(id) =>
                                            router.post(
                                                route('people.link', id),
                                                { type: 'project', id: project.id },
                                                { preserveScroll: true, preserveState: true },
                                            )
                                        }
                                    />
                                </div>
                                {props.people.length === 0 ? (
                                    <p className="card-empty">No one yet.</p>
                                ) : (
                                    <ul className="proj-people">
                                        {props.people.map((p) => (
                                            <li key={p.id}>
                                                <Link href={`/people?person=${p.id}`}>
                                                    <span className="pp-av">{p.initials}</span>
                                                    <span>
                                                        <b>{p.name}</b>
                                                        {p.relationship && <small>{p.relationship}</small>}
                                                    </span>
                                                </Link>
                                                <button
                                                    className="note-task-x"
                                                    aria-label={`Take ${p.name} off this project`}
                                                    onClick={() =>
                                                        router.post(
                                                            route('people.unlink', p.id),
                                                            { type: 'project', id: project.id },
                                                            { preserveScroll: true, preserveState: true },
                                                        )
                                                    }
                                                >
                                                    <Icon name="x" className="ic-xs" />
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        )}
                        {show('habits') && (
                            <section className="card" aria-labelledby="phab-title">
                                <div className="card-head">
                                    <h2 className="card-title" id="phab-title">
                                        <Icon name="habits" />
                                        Habits for this project
                                    </h2>
                                    <button className="btn btn-ghost btn-sm" onClick={() => setNewHabit(true)}>
                                        <Icon name="plus" />
                                        New habit
                                    </button>
                                </div>
                                <div style={{ paddingBottom: 6 }}>
                                    {props.habits.length === 0 && (
                                        <p className="card-empty">No habits yet. A small daily habit keeps a big project moving.</p>
                                    )}
                                    {props.habits.map((h) => (
                                        <div className="habit" key={h.id}>
                                            <HabitButton habit={h} />
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
                                    <button
                                        className="btn btn-ghost btn-sm"
                                        onClick={() => router.post(route('notes.store'), { project_id: project.id })}
                                    >
                                        <Icon name="plus" />
                                        New note
                                    </button>
                                </div>
                                <div className="pnotes">
                                    {props.notes.length === 0 && <p className="card-empty">No notes yet.</p>}
                                    {props.notes.map((n) => (
                                        <Link className="pnote" key={n.id} href={`/notes?in=p${project.id}&note=${n.id}`}>
                                            <span className="pnote-fold" />
                                            <b>{n.title}</b>
                                            <span>{n.updatedAt ? `Edited ${clock.date(n.updatedAt)}` : ''}</span>
                                        </Link>
                                    ))}
                                </div>
                                <div style={{ height: 12 }} />
                            </section>
                        )}
                    </div>
                </div>
            </div>
            {newHabit && <HabitDialog initial={{ project_id: project.id }} projects={props.projects} onClose={() => setNewHabit(false)} />}
            {editing && (
                <ProjectDialog
                    project={{
                        id: project.id,
                        name: project.name,
                        description: project.description,
                        dueDate: project.dueDate,
                        areaId: project.areaId,
                        goalId: project.goal?.id ?? null,
                        archived: project.archived,
                    }}
                    onClose={() => setEditing(false)}
                />
            )}
        </VelocityShell>
    );
}
