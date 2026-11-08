import { Clock, CRIT, critValue, dueInfo, fmt1, fmtHours, LEVEL, signed } from '@/lib/velocity';
import type { RankedTask, TaskDetail } from '@/types/velocity';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Icon } from './icon';
import { Check, Dots, PriorityBar } from './ui';

const patch = (id: number, data: Record<string, string | number | boolean | null>) => router.patch(route('tasks.update', id), data, { preserveScroll: true, preserveState: true });

/**
 * Everything about one task: why it sits where it does (points per part of the
 * formula and the report's contrastive sentence), its details, steps and
 * child tasks. Every change saves on its own.
 */
export function TaskPanel({ task, clock, projects, onClose }: { task: TaskDetail; clock: Clock; projects: { id: number; name: string }[]; onClose: () => void }) {
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
                    <button className="icon-btn" aria-label="Delete task" title="Delete (you can undo)" onClick={() => router.delete(route('tasks.destroy', task.id), { preserveScroll: true })}>
                        <Icon name="archive" />
                    </button>
                    <button className="icon-btn" onClick={onClose} aria-label="Close">
                        <Icon name="x" />
                    </button>
                </div>
            </div>
            <div className="panel-body">
                <div className="p-title-row">
                    <Check done={done} size="check-lg" label={done ? 'Mark not done' : 'Mark done'} onClick={() => router.post(route(done ? 'tasks.reopen' : 'tasks.complete', task.id), {}, { preserveScroll: true })} />
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
                            <button key={s} role="radio" aria-checked={task.status === s} onClick={() => task.status !== s && patch(task.id, { status: s })}>
                                {s === 'todo' ? 'To do' : s === 'doing' ? 'Doing' : 'Waiting'}
                            </button>
                        ))}
                    </div>
                )}

                {ranked ? (
                    <section className="why" aria-label="Why this task is ranked here">
                        <div className="why-top">
                            <div>
                                <div className="why-label">Priority</div>
                                <div className="why-score">
                                    <b>{fmt1(ranked.score)}</b>
                                    <span>
                                        #{ranked.rank} of {task.total}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <PriorityBar contrib={ranked.contrib} size="pbar-lg" score={ranked.score} />
                        <ul className="contrib">
                            {CRIT.map((c) => (
                                <li key={c.k}>
                                    <i className={`sw sw-${c.k}`} />
                                    <span className="name">{c.name}</span>
                                    <span className="val">{critValue(ranked, c.k)}</span>
                                    <b>+{fmt1(ranked.contrib[c.k])}</b>
                                </li>
                            ))}
                        </ul>
                        {ranked.below && ranked.deltas ? (
                            <div className="vs">
                                <p className="vs-title">
                                    Why it’s above #{ranked.rank + 1} <span className="muted">· {ranked.below.title}</span>
                                </p>
                                <Diverging deltas={ranked.deltas} />
                                <p className="vs-sentence">
                                    {ranked.contrast} <strong>{fmt1(ranked.gap ?? 0)} points ahead.</strong>
                                </p>
                            </div>
                        ) : (
                            <div className="vs">
                                <p className="vs-sentence">This is the last task in your list.</p>
                            </div>
                        )}
                        <a className="link how" href="/weights">
                            <Icon name="info" className="ic-sm" />
                            How the score is worked out
                        </a>
                    </section>
                ) : (
                    <section className="why">
                        <div className="why-label">Not ranked</div>
                        <p className="vs-sentence" style={{ marginTop: 6 }}>
                            {done
                                ? `Done${task.scoreAtCompletion ? ` with priority ${fmt1(task.scoreAtCompletion)}` : ''}.`
                                : task.status === 'waiting'
                                  ? `Waiting${task.waitingFor ? ` for ${task.waitingFor}` : ''}. It joins the ranking when you set it back to To do.`
                                  : task.dependsOn.length
                                    ? `Starts after “${task.dependsOn[0].title}”. It joins the ranking as soon as that is done.`
                                    : 'It joins the ranking when it can start.'}
                        </p>
                    </section>
                )}

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
                            onBlur={(e) => Number(e.target.value) > 0 && Number(e.target.value) !== task.estHours && patch(task.id, { est_hours: Number(e.target.value) })}
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
                        <select className="prop-input" value={task.project?.id ?? ''} aria-label="Project" onChange={(e) => patch(task.id, { project_id: e.target.value ? Number(e.target.value) : null })}>
                            <option value="">Inbox (no project)</option>
                            {projects.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
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
                                <Check done={s.done} label={s.done ? 'Mark step not done' : 'Mark step done'} onClick={() => router.patch(route('steps.update', s.id), { done: !s.done }, { preserveScroll: true, preserveState: true })} />
                                <span className="pt-step-title">{s.title}</span>
                                <button className="pt-promote" title="Give this step its own due date and importance, so it is ranked like any task" onClick={() => router.post(route('steps.promote', s.id), {}, { preserveScroll: true, preserveState: true })}>
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
                                <span className="pt-real">Its own task{c.score != null ? ` · ${fmt1(c.score)}` : ''}</span>
                            </li>
                        ))}
                    </ul>
                    <form
                        className="add-step"
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (!newStep.trim()) return;
                            router.post(route('steps.store', task.id), { title: newStep }, { preserveScroll: true, preserveState: true, onSuccess: () => setNewStep('') });
                        }}
                    >
                        <Icon name="plus" className="ic-sm" />
                        <input value={newStep} onChange={(e) => setNewStep(e.target.value)} placeholder="Add a step" aria-label="Add a step" style={{ flex: 1, border: 0, background: 'transparent', outline: 0 }} />
                    </form>
                </section>
            </div>
            <div className="panel-foot">
                <button className="btn btn-primary btn-lg" onClick={() => router.post(route(done ? 'tasks.reopen' : 'tasks.complete', task.id), {}, { preserveScroll: true })}>
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

/** Points ahead (right) or behind (left) the task below, per part of the formula. */
export function Diverging({ deltas }: { deltas: Record<string, number> }) {
    const max = Math.max(0.1, ...CRIT.map((c) => Math.abs(deltas[c.k])));
    return (
        <div className="dv" role="img" aria-label={`Differences in points: ${CRIT.map((c) => `${c.name} ${signed(deltas[c.k])}`).join(', ')}`}>
            {CRIT.map((c) => {
                const v = deltas[c.k];
                return (
                    <div className="dv-row" key={c.k}>
                        <span className="n">{c.name}</span>
                        <span className="dv-neg">{v < -0.05 && <i style={{ width: `${(-v / max) * 100}%`, background: `var(--c-${c.k})` }} />}</span>
                        <span className="dv-pos">{v > 0.05 && <i style={{ width: `${(v / max) * 100}%`, background: `var(--c-${c.k})` }} />}</span>
                        <span className="v">{signed(v)}</span>
                    </div>
                );
            })}
        </div>
    );
}
