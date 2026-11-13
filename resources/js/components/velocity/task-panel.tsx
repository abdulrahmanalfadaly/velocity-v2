import { Clock, dueInfo, fmt1, fmtHours, goalVar, LEVEL, REPEAT_CHOICES, repeatText } from '@/lib/velocity';
import type { RankedTask, RepeatRule, TaskDetail } from '@/types/velocity';
import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { StartFocus } from './focus';
import { Icon } from './icon';
import { askToNotify, notifyState } from './reminders';
import { TaskFiles } from './task-files';
import { Check, Dots } from './ui';

type Value = string | number | boolean | null | number[];
const patch = (id: number, data: Record<string, Value>) =>
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
    const [busy, setBusy] = useState(false);
    const titleRef = useRef<HTMLTextAreaElement>(null);
    useEffect(() => setTitle(task.title), [task.id, task.title]);
    // The title box grows with the text, so long titles are never cut off.
    useEffect(() => {
        const el = titleRef.current;
        if (el) {
            el.style.height = 'auto';
            el.style.height = `${el.scrollHeight}px`;
        }
    }, [title]);
    const ranked = task.rank != null ? (task as RankedTask) : null;
    const due = dueInfo(task, clock);
    const done = task.status === 'done';
    // A task in an archived project still shows that project in the list.
    const projectChoices = task.project && !projects.some((p) => p.id === task.project!.id) ? [...projects, task.project] : projects;
    const waitsFor = task.dependsOn.map((d) => d.id);
    const toggleDone = () => {
        if (busy) return; // a second click while the first is saving would undo it
        setBusy(true);
        router.post(
            route(done ? 'tasks.reopen' : 'tasks.complete', task.id),
            {},
            { preserveScroll: true, preserveState: true, onFinish: () => setBusy(false) },
        );
    };
    const saveTitle = () => {
        if (!title.trim()) setTitle(task.title);
        else if (title !== task.title) patch(task.id, { title: title.trim() });
    };

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
                        aria-label="Save as template"
                        title="Save as template (with its steps)"
                        onClick={() => router.post(route('templates.store'), { task_id: task.id }, { preserveScroll: true, preserveState: true })}
                    >
                        <Icon name="file" />
                    </button>
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
                    <Check done={done} size="check-lg" label={done ? 'Mark not done' : 'Mark done'} onClick={toggleDone} />
                    <textarea
                        ref={titleRef}
                        className="p-title p-title-edit"
                        rows={1}
                        value={title}
                        aria-label="Task title"
                        maxLength={255}
                        onChange={(e) => setTitle(e.target.value.replace(/\n/g, ' '))}
                        onBlur={saveTitle}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                (e.target as HTMLTextAreaElement).blur();
                            } else if (e.key === 'Escape') {
                                setTitle(task.title);
                            }
                        }}
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
                    ) : task.dependsOn.some((d) => d.status !== 'done') ? (
                        `After “${task.dependsOn.find((d) => d.status !== 'done')!.title}”`
                    ) : task.availableFrom ? (
                        `From ${clock.date(task.availableFrom, { weekday: 'short', day: 'numeric', month: 'short' })}`
                    ) : null}
                </p>

                <div className="props">
                    {task.status === 'waiting' && (
                        <>
                            <div className="prop-label">
                                <Icon name="hourglass" />
                                Waiting for
                            </div>
                            <div className="prop-value">
                                <input
                                    className="prop-input"
                                    aria-label="Waiting for"
                                    placeholder="Who or what?"
                                    maxLength={160}
                                    defaultValue={task.waitingFor ?? ''}
                                    key={`${task.id}-w-${task.waitingFor}`}
                                    onBlur={(e) =>
                                        e.target.value.trim() !== (task.waitingFor ?? '') &&
                                        patch(task.id, { waiting_for: e.target.value.trim() || null })
                                    }
                                    onKeyDown={(e) => e.key === 'Enter' && (e.target as HTMLInputElement).blur()}
                                />
                            </div>
                        </>
                    )}
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
                            max={500}
                            step={0.25}
                            aria-label="Estimated hours"
                            defaultValue={task.estHours}
                            key={`${task.id}-h-${task.estHours}`}
                            onBlur={(e) => {
                                const h = Number(e.target.value);
                                if (!(h > 0 && h <= 500))
                                    e.target.value = String(task.estHours); // not a usable number: put the old one back
                                else if (h !== task.estHours) patch(task.id, { est_hours: h });
                            }}
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
                            {projectChoices.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="prop-label">
                        <Icon name="target" />
                        Goal
                    </div>
                    <div className="prop-value">
                        {task.goal ? (
                            <Link className="p-goal" href={`/goals?focus=g${task.goal.id}`} style={{ ['--g' as string]: goalVar(task.goal.color) }}>
                                <i />
                                {task.goal.title}
                            </Link>
                        ) : (
                            <span className="muted">{task.project ? 'The project has no goal' : 'None'}</span>
                        )}
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
                    {task.focused.seconds > 0 && (
                        <>
                            <div className="prop-label">
                                <Icon name="focus" />
                                Focused
                            </div>
                            <div className="prop-value">
                                {fmtHours(task.focused.seconds / 3600)}
                                <span className="muted">
                                    · {task.focused.sessions} {task.focused.sessions === 1 ? 'session' : 'sessions'} · {fmtHours(task.estHours)}{' '}
                                    estimated
                                </span>
                            </div>
                        </>
                    )}
                    <div className="prop-label">
                        <Icon name="bell" />
                        Reminder
                    </div>
                    <div className="prop-value p-reminder">
                        <input
                            type="datetime-local"
                            aria-label="Reminder"
                            defaultValue={clock.localInput(task.reminderAt)}
                            key={`${task.id}-r-${task.reminderAt}`}
                            onBlur={(e) =>
                                e.target.value !== clock.localInput(task.reminderAt) && patch(task.id, { reminder_at: e.target.value || null })
                            }
                            className="prop-input"
                        />
                        {task.dueAt && !done && (
                            <select
                                className="prop-input"
                                aria-label="Quick reminder"
                                value=""
                                onChange={(e) => {
                                    const before = Number(e.target.value);
                                    if (!before) return;
                                    const at = new Date(new Date(task.dueAt!).getTime() - before * 60_000).toISOString();
                                    patch(task.id, { reminder_at: clock.localInput(at) });
                                }}
                            >
                                <option value="">Before due…</option>
                                <option value="15">15 min before</option>
                                <option value="60">1 hour before</option>
                                <option value="180">3 hours before</option>
                                <option value="1440">1 day before</option>
                            </select>
                        )}
                        {task.reminderAt && (
                            <button
                                className="icon-btn"
                                aria-label="No reminder"
                                title="No reminder"
                                onClick={() => patch(task.id, { reminder_at: null })}
                            >
                                <Icon name="x" className="ic-sm" />
                            </button>
                        )}
                        <NotifyHint />
                    </div>
                    <div className="prop-label">
                        <Icon name="calendar" />
                        Can start
                    </div>
                    <div className="prop-value">
                        <input
                            type="datetime-local"
                            aria-label="Can start"
                            defaultValue={clock.localInput(task.availableFrom)}
                            key={`${task.id}-a-${task.availableFrom}`}
                            onBlur={(e) =>
                                e.target.value !== clock.localInput(task.availableFrom) && patch(task.id, { available_from: e.target.value || null })
                            }
                            className="prop-input"
                        />
                    </div>
                    <div className="prop-label">
                        <Icon name="lock" />
                        Starts after
                    </div>
                    <div className="prop-value p-after">
                        {task.dependsOn.map((d) => (
                            <span key={d.id} className={`p-after-chip${d.status === 'done' ? ' is-done' : ''}`}>
                                {d.title}
                                <button
                                    type="button"
                                    aria-label={`Remove “${d.title}”`}
                                    onClick={() => patch(task.id, { depends_on: waitsFor.filter((x) => x !== d.id) })}
                                >
                                    <Icon name="x" className="ic-xs" />
                                </button>
                            </span>
                        ))}
                        <select
                            className="prop-input"
                            value=""
                            aria-label="Add a task it starts after"
                            onChange={(e) => e.target.value && patch(task.id, { depends_on: [...waitsFor, Number(e.target.value)] })}
                        >
                            <option value="">{task.dependsOn.length ? 'Add another…' : 'Nothing – pick a task…'}</option>
                            {task.candidates
                                .filter((c) => !waitsFor.includes(c.id))
                                .map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.title}
                                    </option>
                                ))}
                        </select>
                    </div>
                </div>

                <TaskFiles taskId={task.id} files={task.files} />

                <section className="p-section">
                    <div className="p-section-head">
                        <h3 className="p-section-title">Notes</h3>
                    </div>
                    <textarea
                        className="p-notes"
                        aria-label="Notes"
                        placeholder="Add notes…"
                        defaultValue={task.notes ?? ''}
                        key={`${task.id}-n-${task.notes}`}
                        maxLength={20000}
                        rows={3}
                        onBlur={(e) => e.target.value !== (task.notes ?? '') && patch(task.id, { notes: e.target.value || null })}
                    />
                    <div className="p-linked">
                        {task.linkedNotes?.map((n) => (
                            <Link key={n.id} className="p-linked-note" href={`/notes?note=${n.id}`}>
                                <Icon name="notes" className="ic-sm" />
                                <span>{n.title}</span>
                            </Link>
                        ))}
                        <button className="note-add" onClick={() => router.post(route('notes.store'), { task_id: task.id })}>
                            <Icon name="plus" className="ic-xs" />
                            New note
                        </button>
                    </div>
                </section>

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
                                <span className="pt-step-title" title={s.title}>
                                    {s.title}
                                </span>
                                <button
                                    className="pt-promote"
                                    onClick={() => router.post(route('steps.promote', s.id), {}, { preserveScroll: true, preserveState: true })}
                                >
                                    <Icon name="rank" className="ic-xs" />
                                    Make it a task
                                </button>
                                <button
                                    className="icon-btn pt-remove"
                                    aria-label={`Remove step “${s.title}”`}
                                    title="Remove step"
                                    onClick={() => router.delete(route('steps.destroy', s.id), { preserveScroll: true, preserveState: true })}
                                >
                                    <Icon name="x" className="ic-sm" />
                                </button>
                            </li>
                        ))}
                        {task.children.map((c) => (
                            <li key={`c${c.id}`} className="pt-step pt-subtask" style={{ minHeight: 34 }}>
                                <span className="rank-badge">{c.rank ?? '–'}</span>
                                <Link className="pt-step-title" href={`?task=${c.id}`} preserveScroll title={c.title}>
                                    {c.title}
                                </Link>
                            </li>
                        ))}
                    </ul>
                    <form
                        className="add-step"
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (!newStep.trim()) return;
                            const text = newStep;
                            setNewStep(''); // cleared first, so a fast second Enter can't add it twice
                            router.post(
                                route('steps.store', task.id),
                                { title: text },
                                { preserveScroll: true, preserveState: true, onError: () => setNewStep(text) },
                            );
                        }}
                    >
                        <Icon name="plus" className="ic-sm" />
                        <input
                            value={newStep}
                            onChange={(e) => setNewStep(e.target.value)}
                            placeholder="Add a step"
                            aria-label="Add a step"
                            maxLength={255}
                            style={{ flex: 1, border: 0, background: 'transparent', outline: 0 }}
                        />
                    </form>
                </section>
            </div>
            <div className="panel-foot">
                <button className="btn btn-primary btn-lg" onClick={toggleDone} disabled={busy}>
                    <Icon name="check" />
                    {done ? 'Not done' : 'Mark done'}
                </button>
                {!done && <StartFocus taskId={task.id} className="btn btn-secondary btn-lg" />}
            </div>
        </>
    );
}

/** Asks once for Windows notifications, so reminders also pop up outside the app window. */
function NotifyHint() {
    const [state, setState] = useState(notifyState());
    if (state !== 'ask') return null;
    return (
        <button className="link p-notify" onClick={() => askToNotify().then(() => setState(notifyState()))}>
            Also show in Windows
        </button>
    );
}
