import { Clock, dueInfo, fmt1, fmtHours, LEVEL, REPEAT_CHOICES, repeatText } from '@/lib/velocity';
import type { RankedTask, RepeatRule, TaskDetail } from '@/types/velocity';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Icon } from './icon';
import { Check, Dots } from './ui';

const patch = (id: number, data: Record<string, string | number | boolean | null>) =>
    router.patch(route('tasks.update', id), data, { preserveScroll: true, preserveState: true });

const repeatKey = (r: RepeatRule | null) => (r ? `${r.every}-${r.unit}` : '');

/** One task: its place in the list, details, steps and child tasks. Every change saves on its own. */
export function TaskPanel({
    task,
    clock,
    projects,
    onClose,
}: {
    task: TaskDetail;
    clock: Clock;
    projects: { id: number; name: string }[];
    onClose: () => void;
}) {
    const [title, setTitle] = useState(task.title);
    const [newStep, setNewStep] = useState('');
    useEffect(() => setTitle(task.title), [task.id, task.title]);
    const ranked = task.rank != null ? (task as RankedTask) : null;
    const due = dueInfo(task, clock);
    const done = task.status === 'done';

    return (
        <>
            <div className="panel-head">
                <div className="crumbs">
                    {task.project ? <span>{task.project.name}</span> : <span>Inbox</span>}
                    {task.parent && (
                        <>
                            <Icon name="chevR" />
                            <span>{task.parent.title}</span>
                        </>
                    )}
                </div>
                <div className="panel-tools">
                    <button
                        className="icon-btn"
                        aria-label="Delete task"
                        title="Delete (you can undo)"
                        onClick={() => router.delete(route('tasks.destroy', task.id), { preserveScroll: true })}
                    >
                        <Icon name="archive" />
                    </button>
                    <button className="icon-btn" onClick={onClose} aria-label="Close">
                        <Icon name="x" />
                    </button>
                </div>
            </div>
            <div className="panel-body">
                <div className="p-title-row">
                    <Check
                        done={done}
                        size="check-lg"
                        label={done ? 'Mark not done' : 'Mark done'}
                        onClick={() => router.post(route(done ? 'tasks.reopen' : 'tasks.complete', task.id), {}, { preserveScroll: true })}
                    />
                    <input
                        className="p-title"
                        value={title}
                        aria-label="Task title"
                        onChange={(e) => setTitle(e.target.value)}
                        onBlur={() => title.trim() && title !== task.title && patch(task.id, { title })}
                        onKeyDown={(e) => e.key === 'Enter' && (e.target as HTMLInputElement).blur()}
                        style={{ width: '100%', border: 0, background: 'transparent', padding: 0 }}
                    />
                </div>
                {!done && (
                    <div className="seg" role="radiogroup" aria-label="Status">
                        {(['todo', 'doing', 'waiting'] as const).map((s) => (
                            <button
                                key={s}
                                role="radio"
                                aria-checked={task.status === s}
                                onClick={() => task.status !== s && patch(task.id, { status: s })}
                            >
                                {s === 'todo' ? 'To do' : s === 'doing' ? 'Doing' : 'Waiting'}
                            </button>
                        ))}
                    </div>
                )}

                <p className="p-rank">
                    {ranked ? (
                        <>
                            <span className="rank-badge">{ranked.rank}</span>
                            <span>of {task.total}</span>
                            <span className="dotsep" />
                            <span className="score-num">{fmt1(ranked.score)}</span>
                        </>
                    ) : done ? (
                        'Done'
                    ) : task.status === 'waiting' ? (
                        `Waiting${task.waitingFor ? ` for ${task.waitingFor}` : ''}`
                    ) : task.dependsOn.length ? (
                        `After “${task.dependsOn[0].title}”`
                    ) : task.availableFrom ? (
                        `From ${clock.date(task.availableFrom, { weekday: 'short', day: 'numeric', month: 'short' })}`
                    ) : null}
                </p>

                <div className="props">
                    <div className="prop-label">
                        <Icon name="flag" />
                        Importance
                    </div>
                    <div className="prop-value">
                        <Dots n={task.importance} c="I" onPick={(v) => patch(task.id, { importance: v })} />
                        {LEVEL[task.importance]}
                    </div>
                    <div className="prop-label">
                        <Icon name="zap" />
                        Urgency
                    </div>
                    <div className="prop-value">
                        <Dots n={task.urgency} c="U" onPick={(v) => patch(task.id, { urgency: v })} />
                        {LEVEL[task.urgency]}
                    </div>
                    <div className="prop-label">
                        <Icon name="calendar" />
                        Due
                    </div>
                    <div className="prop-value">
                        <input
                            type="datetime-local"
                            aria-label={`Due: ${due.long}`}
                            defaultValue={clock.localInput(task.dueAt)}
                            key={`${task.id}-${task.dueAt}`}
                            onBlur={(e) => e.target.value !== clock.localInput(task.dueAt) && patch(task.id, { due_at: e.target.value || null })}
                            className="prop-input"
                        />
                    </div>
                    <div className="prop-label">
                        <Icon name="clock" />
                        Work
                    </div>
                    <div className="prop-value">
                        <input
                            type="number"
                            min={0.25}
                            step={0.25}
                            aria-label="Estimated hours"
                            defaultValue={task.estHours}
                            key={`${task.id}-h-${task.estHours}`}
                            onBlur={(e) =>
                                Number(e.target.value) > 0 &&
                                Number(e.target.value) !== task.estHours &&
                                patch(task.id, { est_hours: Number(e.target.value) })
                            }
                            className="prop-input"
                            style={{ width: 80 }}
                        />
                        <span className="muted">hours · {fmtHours(task.estHours)}</span>
                    </div>
                    <div className="prop-label">
                        <Icon name="folder" />
                        Project
                    </div>
                    <div className="prop-value">
                        <select
                            className="prop-input"
                            value={task.project?.id ?? ''}
                            aria-label="Project"
                            onChange={(e) => patch(task.id, { project_id: e.target.value ? Number(e.target.value) : null })}
                        >
                            <option value="">Inbox (no project)</option>
                            {projects.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="prop-label">
                        <Icon name="habits" />
                        Repeats
                    </div>
                    <div className="prop-value">
                        <select
                            className="prop-input"
                            value={repeatKey(task.repeat)}
                            aria-label="Repeats"
                            onChange={(e) => {
                                const [every, unit] = e.target.value.split('-'); // "" = doesn't repeat
                                patch(task.id, { repeat_unit: unit ?? null, repeat_every: Number(every) || 1 });
                            }}
                        >
                            <option value="">Doesn’t repeat</option>
                            {[
                                ...REPEAT_CHOICES,
                                ...(task.repeat && !REPEAT_CHOICES.some((r) => repeatKey(r) === repeatKey(task.repeat)) ? [task.repeat] : []),
                            ].map((r) => (
                                <option key={repeatKey(r)} value={repeatKey(r)}>
                                    {repeatText(r)}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                <section className="p-section">
                    <div className="p-section-head">
                        <h3 className="p-section-title">Steps</h3>
                        {task.steps.length > 0 && (
                            <span className="card-sub">
                                {task.steps.filter((s) => s.done).length} of {task.steps.length} done
                            </span>
                        )}
                    </div>
                    <ul className="steps">
                        {task.steps.map((s) => (
                            <li key={s.id} className={`pt-step${s.done ? ' is-done' : ''}`} style={{ minHeight: 34 }}>
                                <Check
                                    done={s.done}
                                    label={s.done ? 'Mark step not done' : 'Mark step done'}
                                    onClick={() =>
                                        router.patch(route('steps.update', s.id), { done: !s.done }, { preserveScroll: true, preserveState: true })
                                    }
                                />
                                <span className="pt-step-title">{s.title}</span>
                                <button
                                    className="pt-promote"
                                    onClick={() => router.post(route('steps.promote', s.id), {}, { preserveScroll: true, preserveState: true })}
                                >
                                    <Icon name="rank" className="ic-xs" />
                                    Make it a task
                                </button>
                            </li>
                        ))}
                        {task.children.map((c) => (
                            <li key={`c${c.id}`} className="pt-step pt-subtask" style={{ minHeight: 34 }}>
                                <span className="rank-badge">{c.rank ?? '–'}</span>
                                <a className="pt-step-title" href={`/tasks?task=${c.id}`}>
                                    {c.title}
                                </a>
                            </li>
                        ))}
                    </ul>
                    <form
                        className="add-step"
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (!newStep.trim()) return;
                            router.post(
                                route('steps.store', task.id),
                                { title: newStep },
                                { preserveScroll: true, preserveState: true, onSuccess: () => setNewStep('') },
                            );
                        }}
                    >
                        <Icon name="plus" className="ic-sm" />
                        <input
                            value={newStep}
                            onChange={(e) => setNewStep(e.target.value)}
                            placeholder="Add a step"
                            aria-label="Add a step"
                            style={{ flex: 1, border: 0, background: 'transparent', outline: 0 }}
                        />
                    </form>
                </section>
            </div>
            <div className="panel-foot">
                <button
                    className="btn btn-primary btn-lg"
                    onClick={() => router.post(route(done ? 'tasks.reopen' : 'tasks.complete', task.id), {}, { preserveScroll: true })}
                >
                    <Icon name="check" />
                    {done ? 'Not done' : 'Mark done'}
                </button>
                {!done && task.status !== 'doing' ? (
                    <button className="btn btn-secondary btn-lg" onClick={() => patch(task.id, { status: 'doing' })}>
                        <Icon name="play" />
                        Start
                    </button>
                ) : null}
            </div>
        </>
    );
}
