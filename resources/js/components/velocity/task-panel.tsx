import { Clock, fmt1, fmtHours, goalVar, LEVEL, REPEAT_CHOICES, repeatText } from '@/lib/velocity';
import type { RankedTask, RepeatRule, TaskDetail, TaskReminderInfo, VelocityShared } from '@/types/velocity';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { DatePick } from './date-pick';
import { StartFocus } from './focus';
import { Modal } from './forms';
import { TaskGitHub } from './github-parts';
import { Icon } from './icon';
import { Picker } from './pick';
import { askToNotify, notifyState } from './reminders';
import { Select } from './select';
import { TaskFiles } from './task-files';
import { Check, Dots } from './ui';

type Value = string | number | boolean | null | number[];
const patch = (id: number, data: Record<string, Value>, onError?: (errors: Record<string, string>) => void) =>
    router.patch(route('tasks.update', id), data, { preserveScroll: true, preserveState: true, onError });
const isOpen = (s: string) => s !== 'done' && s !== 'cancelled';

const repeatKey = (r: RepeatRule | null) => (r ? `${r.every}-${r.unit}` : '');
const VIA_WORD: Record<string, string> = {
    created: 'added',
    updated: 'changed',
    completed: 'done',
    reopened: 'not done',
    deleted: 'deleted',
    restored: 'restored',
};

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
    const [newSub, setNewSub] = useState('');
    const [deleting, setDeleting] = useState(false);
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
    const goals = usePage<VelocityShared>().props.nav?.goals ?? [];
    // An archived goal the task still has stays in the list.
    const goalChoices = task.goal && !goals.some((g) => g.id === task.goal!.id) ? [...goals, task.goal] : goals;
    // Due after the project's own due date: a warning only.
    const dueDay = task.dueAt ? clock.localInput(task.dueAt).slice(0, 10) : null;
    const lateForProject = !!(dueDay && task.project?.dueDate && dueDay > task.project.dueDate);
    const done = task.status === 'done';
    const cancelled = task.status === 'cancelled';
    const finished = done || cancelled;
    const [cancelling, setCancelling] = useState(false);
    const waitedDays = task.status === 'waiting' && task.waitingSince ? -clock.dayDiff(task.waitingSince) : 0;
    // A task in an archived project still shows that project in the list.
    const projectChoices = task.project && !projects.some((p) => p.id === task.project!.id) ? [...projects, task.project] : projects;
    const waitsFor = task.dependsOn.map((d) => d.id);
    // The last change made with an access key (Mark), newest first.
    const viaKey = task.events.find((e) => e.via);
    const blocks = task.blocks ?? [];
    // Why a link couldn't be made ("they would wait for each other").
    const [linkError, setLinkError] = useState('');
    useEffect(() => setLinkError(''), [task.id]);
    const linkFailed = (e: Record<string, string>) => setLinkError(Object.values(e)[0] ?? '');
    const waitingOn = task.dependsOn.find((d) => isOpen(d.status));
    const [asking, setAsking] = useState(false);
    const complete = (extra: Record<string, Value> = {}) => {
        if (busy) return; // a second click while the first is saving would undo it
        setBusy(true);
        router.post(route(finished ? 'tasks.reopen' : 'tasks.complete', task.id), extra, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setBusy(false),
        });
    };
    const openKids = task.children.filter((c) => isOpen(c.status));
    // Still waiting on another task, or subtasks still open: ask first.
    const toggleDone = () => (!finished && (waitingOn || openKids.length) ? setAsking(true) : complete());
    const remove = (children?: 'delete' | 'keep') =>
        router.delete(route('tasks.destroy', task.id), { preserveScroll: true, data: children ? { children } : {} });
    const kids = (n: number) => (n === 1 ? '1 subtask' : `${n} subtasks`);
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
                    {!finished && (
                        <button
                            className="icon-btn"
                            aria-label="Cancel task"
                            title="Cancel task (it won’t be done)"
                            onClick={() => setCancelling(true)}
                        >
                            <Icon name="ban" />
                        </button>
                    )}
                    {finished && (
                        <button
                            className="icon-btn"
                            aria-label={task.archivedAt ? 'Take out of the archive' : 'Archive'}
                            title={task.archivedAt ? 'Take out of the archive' : 'Archive'}
                            aria-pressed={!!task.archivedAt}
                            onClick={() =>
                                router.post(
                                    route(task.archivedAt ? 'tasks.unarchive' : 'tasks.archive-one', task.id),
                                    {},
                                    { preserveScroll: true, preserveState: true },
                                )
                            }
                        >
                            <Icon name="archive" />
                        </button>
                    )}
                    <button
                        className="icon-btn"
                        aria-label="Delete task"
                        title="Delete (you can undo)"
                        onClick={() => (task.children.length ? setDeleting(true) : remove())}
                    >
                        <Icon name="trash" />
                    </button>
                    <button className="icon-btn" onClick={onClose} aria-label="Close">
                        <Icon name="x" />
                    </button>
                </div>
            </div>
            <div className="panel-body">
                <div className="p-title-row">
                    {cancelled ? (
                        <button className="p-cancel-mark" onClick={toggleDone} aria-label="Reopen" title="Reopen">
                            <Icon name="ban" />
                        </button>
                    ) : (
                        <Check done={done} size="check-lg" label={done ? 'Mark not done' : 'Mark done'} onClick={toggleDone} />
                    )}
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
                {!finished && task.capturedAt && (
                    <div className="p-captured" role="status">
                        <Icon name="inbox" className="ic-sm" />
                        <span>Captured · not ranked yet</span>
                        <button
                            className="btn btn-secondary btn-sm"
                            onClick={() => router.post(route('tasks.rank', task.id), {}, { preserveScroll: true, preserveState: true })}
                        >
                            Rank it
                        </button>
                    </div>
                )}
                {!finished && (
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
                        `Done${task.archivedAt ? ' · archived' : ''}`
                    ) : cancelled ? (
                        `Cancelled${task.cancelledAt ? ` ${clock.date(task.cancelledAt)}` : ''}${task.cancelReason ? ` · ${task.cancelReason}` : ''}${task.archivedAt ? ' · archived' : ''}`
                    ) : task.status === 'waiting' ? (
                        `Waiting${task.waitingFor ? ` for ${task.waitingFor}` : ''}${waitedDays > 0 ? ` · ${waitedDays === 1 ? '1 day' : `${waitedDays} days`}` : ''}`
                    ) : task.dependsOn.some((d) => d.status !== 'done' && d.status !== 'cancelled') ? (
                        `After “${task.dependsOn.find((d) => d.status !== 'done' && d.status !== 'cancelled')!.title}”`
                    ) : task.availableFrom ? (
                        <>
                            From {clock.date(task.availableFrom, { weekday: 'short', day: 'numeric', month: 'short' })}
                            {!finished && (
                                <button type="button" className="link p-start-now" onClick={() => patch(task.id, { available_from: null })}>
                                    Start now
                                </button>
                            )}
                        </>
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
                    {!finished && (
                        <>
                            <div className="prop-label">
                                <Icon name="followUp" />
                                Follow up
                            </div>
                            <div className="prop-value">
                                <DatePick
                                    className="prop-input"
                                    label="Follow up"
                                    placeholder="No follow-up"
                                    value={task.followUpOn ?? ''}
                                    onChange={(v) => patch(task.id, { follow_up_on: v || null })}
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
                        <DatePick
                            mode="datetime"
                            className="prop-input"
                            label="Due"
                            placeholder="No due date"
                            value={clock.localInput(task.dueAt)}
                            onChange={(v) => patch(task.id, { due_at: v || null })}
                        />
                        {lateForProject && (
                            <span className="p-warn">
                                <Icon name="alert" className="ic-xs" />
                                After the project’s due date (
                                {clock.date(`${task.project!.dueDate}T12:00:00Z`, {
                                    weekday: 'short',
                                    day: 'numeric',
                                    month: 'short',
                                    timeZone: 'UTC',
                                })}
                                )
                            </span>
                        )}
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
                        <span className="muted">{Number.isInteger(task.estHours) ? 'hours' : `hours · ${fmtHours(task.estHours)}`}</span>
                    </div>
                    <div className="prop-label">
                        <Icon name="flame" />
                        Energy
                    </div>
                    <div className="prop-value">
                        <Select
                            className="prop-input"
                            value={task.energy ?? ''}
                            label="Energy it needs"
                            search={false}
                            onChange={(v) => patch(task.id, { energy: v || null })}
                            options={[
                                { value: '', label: 'Not set' },
                                { value: 'low', label: 'Low' },
                                { value: 'normal', label: 'Normal' },
                                { value: 'high', label: 'High' },
                            ]}
                        />
                    </div>
                    <div className="prop-label">
                        <Icon name="folder" />
                        Project
                    </div>
                    <div className="prop-value">
                        <Select
                            className="prop-input"
                            value={String(task.project?.id ?? '')}
                            label="Project"
                            onChange={(v) => patch(task.id, { project_id: v ? Number(v) : null })}
                            options={[
                                { value: '', label: 'Inbox (no project)' },
                                ...projectChoices.map((p) => ({ value: String(p.id), label: p.name })),
                            ]}
                        />
                    </div>
                    <div className="prop-label">
                        <Icon name="target" />
                        Goal
                    </div>
                    <div className="prop-value">
                        {task.project ? (
                            task.goal ? (
                                <Link
                                    className="p-goal"
                                    href={`/goals?focus=g${task.goal.id}`}
                                    style={{ ['--g' as string]: goalVar(task.goal.color) }}
                                >
                                    <i />
                                    {task.goal.title}
                                </Link>
                            ) : (
                                <span className="muted">The project has no goal</span>
                            )
                        ) : (
                            // No project: the task can belong straight to a goal.
                            <Select
                                className="prop-input"
                                value={String(task.goal?.id ?? '')}
                                label="Goal"
                                onChange={(v) => patch(task.id, { goal_id: v ? Number(v) : null })}
                                options={[{ value: '', label: 'No goal' }, ...goalChoices.map((g) => ({ value: String(g.id), label: g.title }))]}
                            />
                        )}
                    </div>
                    <div className="prop-label">
                        <Icon name="habits" />
                        Repeats
                    </div>
                    <div className="prop-value p-repeat">
                        <Select
                            className="prop-input"
                            value={repeatKey(task.repeat ? { unit: task.repeat.unit, every: task.repeat.every } : null)}
                            label="Repeats"
                            onChange={(v) => {
                                const [every, unit] = v.split('-'); // "" = doesn't repeat
                                patch(task.id, { repeat_unit: unit ?? null, repeat_every: Number(every) || 1 });
                            }}
                            options={[
                                { value: '', label: 'Doesn’t repeat' },
                                ...[
                                    ...REPEAT_CHOICES,
                                    ...(task.repeat && !REPEAT_CHOICES.some((r) => repeatKey(r) === repeatKey(task.repeat))
                                        ? [{ unit: task.repeat.unit, every: task.repeat.every }]
                                        : []),
                                ].map((r) => ({ value: repeatKey(r), label: repeatText(r) })),
                            ]}
                        />
                        {task.repeat?.unit === 'week' && (
                            <div className="hb-days p-weekdays" role="group" aria-label="On which days">
                                {WEEK.map((w, i) => {
                                    // Without named days a weekly repeat keeps to the due date's weekday.
                                    const due = task.dueAt ? WEEK.indexOf(clock.weekday(task.dueAt)) + 1 : 0;
                                    const days = task.repeat?.days?.length ? task.repeat.days : due ? [due] : [];
                                    const on = days.includes(i + 1);
                                    return (
                                        <button
                                            key={w}
                                            type="button"
                                            aria-pressed={on}
                                            onClick={() => {
                                                const next = on ? days.filter((d) => d !== i + 1) : [...days, i + 1].sort();
                                                if (next.length) patch(task.id, { repeat_days: next.length > 1 || next[0] !== due ? next : null });
                                            }}
                                        >
                                            {w.slice(0, 2)}
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                        {task.repeat && (
                            <span className="p-repeat-until">
                                <DatePick
                                    className="prop-input"
                                    label="Repeats until"
                                    min={clock.localInput(clock.now.toISOString()).slice(0, 10)}
                                    placeholder="No end"
                                    value={task.repeat.until ?? ''}
                                    onChange={(v) => patch(task.id, { repeat_until: v || null })}
                                />
                                {!finished && (
                                    <button
                                        type="button"
                                        className="link"
                                        onClick={() =>
                                            router.post(route('tasks.cancel', task.id), { skip: true }, { preserveScroll: true, preserveState: true })
                                        }
                                    >
                                        Skip this one
                                    </button>
                                )}
                            </span>
                        )}
                    </div>
                    {task.focused.seconds > 0 && (
                        <>
                            <div className="prop-label">
                                <Icon name="focus" />
                                Focused
                            </div>
                            <div className="prop-value">
                                <span className="nowrap">{fmtHours(task.focused.seconds / 3600)}</span>
                                <span className="muted">
                                    · {task.focused.sessions} {task.focused.sessions === 1 ? 'session' : 'sessions'} · {fmtHours(task.estHours)}{' '}
                                    estimated
                                </span>
                            </div>
                        </>
                    )}
                    {viaKey && (
                        <>
                            <div className="prop-label">
                                <Icon name="key" />
                                Via
                            </div>
                            <div className="prop-value">
                                {viaKey.via}
                                <span className="muted">
                                    · {VIA_WORD[viaKey.type] ?? 'changed'}
                                    {viaKey.at ? ` ${clock.date(viaKey.at, { day: 'numeric', month: 'short' })}` : ''}
                                </span>
                            </div>
                        </>
                    )}
                    <div className="prop-label">
                        <Icon name="bell" />
                        Reminders
                    </div>
                    <div className="prop-value p-reminders">
                        {task.reminders.map((r) => (
                            <span key={r.id} className={`p-after-chip${r.kind === 'before' && !task.dueAt ? ' is-waiting' : ''}`}>
                                <Icon name={r.kind === 'bug' ? 'zap' : 'bell'} className="ic-xs" />
                                {reminderText(r, clock)}
                                <button
                                    type="button"
                                    aria-label={r.kind === 'bug' ? 'Stop bugging' : `Remove reminder “${reminderText(r, clock)}”`}
                                    title={r.kind === 'bug' ? 'Stop bugging' : 'Remove'}
                                    onClick={() => router.delete(route('reminders.destroy', r.id), { preserveScroll: true, preserveState: true })}
                                >
                                    <Icon name="x" className="ic-xs" />
                                </button>
                            </span>
                        ))}
                        {!finished && task.reminders.length < 5 && (
                            <span className="p-reminder">
                                <DatePick
                                    mode="datetime"
                                    className="prop-input"
                                    label="Remind me at"
                                    placeholder="At a time…"
                                    defaultTime="09:00"
                                    value=""
                                    onChange={(v) => v && addReminder(task.id, { kind: 'at', at: v })}
                                />
                                <Select
                                    className="prop-input"
                                    label="Add a reminder"
                                    value=""
                                    placeholder="Before due / Bug me…"
                                    onChange={(v) =>
                                        addReminder(
                                            task.id,
                                            v.startsWith('bug') ? { kind: 'bug', every: Number(v.slice(3)) } : { kind: 'before', before: v },
                                        )
                                    }
                                    options={[
                                        ...BEFORE.filter(([key]) => !task.reminders.some((r) => r.kind === 'before' && beforeKey(r) === key)).map(
                                            ([value, label]) => ({
                                                value,
                                                label,
                                                group: task.dueAt ? 'Before it’s due' : 'Before it’s due (once it has a date)',
                                            }),
                                        ),
                                        ...(task.reminders.some((r) => r.kind === 'bug')
                                            ? []
                                            : EVERY.map(([m, label]) => ({ value: `bug${m}`, label: `Bug me ${label}`, group: 'Until it’s done' }))),
                                    ]}
                                />
                            </span>
                        )}
                        <NotifyHint />
                    </div>
                    <div className="prop-label">
                        <Icon name="calendar" />
                        Can start
                    </div>
                    <div className="prop-value">
                        <DatePick
                            mode="datetime"
                            className="prop-input"
                            label="Can start"
                            placeholder="Any time"
                            defaultTime="09:00"
                            value={clock.localInput(task.availableFrom)}
                            onChange={(v) => patch(task.id, { available_from: v || null })}
                        />
                    </div>
                    <div className="prop-label">
                        <Icon name="lock" />
                        Starts after
                    </div>
                    <div className="prop-value p-after">
                        {task.dependsOn.map((d) => (
                            <span key={d.id} className={`p-after-chip${d.status === 'done' || d.status === 'cancelled' ? ' is-done' : ''}`}>
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
                        <Select
                            className="prop-input"
                            value=""
                            label="Add a task it starts after"
                            placeholder={task.dependsOn.length ? 'Add another…' : 'Nothing – pick a task…'}
                            onChange={(v) => patch(task.id, { depends_on: [...waitsFor, Number(v)] }, linkFailed)}
                            options={task.candidates.filter((c) => !waitsFor.includes(c.id)).map((c) => ({ value: String(c.id), label: c.title }))}
                        />
                    </div>
                    <div className="prop-label">
                        <Icon name="branch" />
                        Blocks
                    </div>
                    <div className="prop-value p-after">
                        {blocks.map((b) => (
                            <span key={b.id} className={`p-after-chip${isOpen(b.status) ? '' : ' is-done'}`}>
                                <Link href={`?task=${b.id}`} preserveScroll preserveState className="p-chip-link">
                                    {b.title}
                                </Link>
                                <button
                                    type="button"
                                    aria-label={`“${b.title}” doesn’t wait on this`}
                                    title="Doesn’t wait on this"
                                    onClick={() =>
                                        router.post(route('tasks.blocks', task.id), { remove: b.id }, { preserveScroll: true, preserveState: true })
                                    }
                                >
                                    <Icon name="x" className="ic-xs" />
                                </button>
                            </span>
                        ))}
                        <Select
                            className="prop-input"
                            value=""
                            label="Add a task that waits on this one"
                            placeholder={blocks.length ? 'Add another…' : 'Nothing waits on it…'}
                            onChange={(v) =>
                                router.post(
                                    route('tasks.blocks', task.id),
                                    { add: Number(v) },
                                    { preserveScroll: true, preserveState: true, onError: linkFailed },
                                )
                            }
                            options={task.candidates
                                .filter((c) => !waitsFor.includes(c.id) && !blocks.some((b) => b.id === c.id))
                                .map((c) => ({ value: String(c.id), label: c.title }))}
                        />
                        {linkError && (
                            <span className="p-warn" role="alert">
                                <Icon name="alert" className="ic-xs" />
                                {linkError}
                            </span>
                        )}
                    </div>
                </div>

                <section className="p-section">
                    <div className="p-section-head">
                        <h3 className="p-section-title">People</h3>
                    </div>
                    <div className="p-linked">
                        {task.people?.map((p) => (
                            <span key={p.id} className="note-task">
                                <Link className="note-task-open" href={`/people?person=${p.id}`}>
                                    <Icon name="person" className="ic-xs" />
                                    <span>{p.name}</span>
                                </Link>
                                <button
                                    className="note-task-x"
                                    aria-label={`Unlink “${p.name}”`}
                                    onClick={() =>
                                        router.post(
                                            route('people.unlink', p.id),
                                            { type: 'task', id: task.id },
                                            { preserveScroll: true, preserveState: true },
                                        )
                                    }
                                >
                                    <Icon name="x" className="ic-xs" />
                                </button>
                            </span>
                        ))}
                        <Picker
                            label={task.status === 'waiting' ? 'Waiting on…' : 'Add person'}
                            find="Find a person"
                            options={(task.peopleChoices ?? []).map((p) => ({ id: p.id, title: p.name }))}
                            taken={task.people?.map((p) => p.id) ?? []}
                            none="No one matches. Add people in People."
                            onPick={(id) =>
                                router.post(route('people.link', id), { type: 'task', id: task.id }, { preserveScroll: true, preserveState: true })
                            }
                        />
                    </div>
                </section>

                {task.github && <TaskGitHub taskId={task.id} title={task.title} notes={task.notes} done={done} github={task.github} />}

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
                        {task.linkedNotes?.map((n) =>
                            n.quote ? (
                                // Made from words in this note: the link opens it at those words.
                                <Link
                                    key={n.id}
                                    className="p-linked-note is-source"
                                    href={`/notes?note=${n.id}&find=${encodeURIComponent(n.quote.slice(0, 80))}`}
                                    title={`“${n.quote}”`}
                                >
                                    <Icon name="notes" className="ic-sm" />
                                    <span>From note: {n.title}</span>
                                    <Icon name="chevR" className="ic-xs" />
                                </Link>
                            ) : (
                                <Link key={n.id} className="p-linked-note" href={`/notes?note=${n.id}`}>
                                    <Icon name="notes" className="ic-sm" />
                                    <span>{n.title}</span>
                                </Link>
                            ),
                        )}
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

                {(!finished || task.children.length > 0) && (
                    <section className="p-section">
                        <div className="p-section-head">
                            <h3 className="p-section-title">Subtasks</h3>
                            {task.children.length > 0 && (
                                <span className="card-sub">
                                    {task.children.filter((c) => !isOpen(c.status)).length} of {task.children.length} done
                                </span>
                            )}
                        </div>
                        <ul className="steps">
                            {task.children.map((c) => (
                                <li key={c.id} className={`pt-step pt-subtask${isOpen(c.status) ? '' : ' is-done'}`} style={{ minHeight: 34 }}>
                                    <Check
                                        done={c.status === 'done'}
                                        label={c.status === 'done' ? `Mark “${c.title}” not done` : `Mark “${c.title}” done`}
                                        onClick={() =>
                                            router.post(
                                                route(isOpen(c.status) ? 'tasks.complete' : 'tasks.reopen', c.id),
                                                {},
                                                { preserveScroll: true, preserveState: true },
                                            )
                                        }
                                    />
                                    <Link className="pt-step-title" href={`?task=${c.id}`} preserveScroll title={c.title}>
                                        {c.title}
                                    </Link>
                                    {c.rank != null ? (
                                        <span className="rank-badge" title={`#${c.rank} in the ranking`}>
                                            {c.rank}
                                        </span>
                                    ) : c.dueAt && isOpen(c.status) ? (
                                        <span className="card-sub">{clock.date(c.dueAt, { day: 'numeric', month: 'short' })}</span>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                        {!finished && (
                            <form
                                className="add-step"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    if (!newSub.trim()) return;
                                    const text = newSub;
                                    setNewSub('');
                                    router.post(
                                        route('tasks.subtask', task.id),
                                        { title: text },
                                        { preserveScroll: true, preserveState: true, onError: () => setNewSub(text) },
                                    );
                                }}
                            >
                                <Icon name="plus" className="ic-sm" />
                                <input
                                    value={newSub}
                                    onChange={(e) => setNewSub(e.target.value)}
                                    placeholder="Add a subtask"
                                    aria-label="Add a subtask"
                                    maxLength={255}
                                    style={{ flex: 1, border: 0, background: 'transparent', outline: 0 }}
                                />
                            </form>
                        )}
                    </section>
                )}
                <TaskActivity key={task.id} items={task.activity ?? []} clock={clock} />
            </div>
            <div className="panel-foot">
                <button className="btn btn-primary btn-lg" onClick={toggleDone} disabled={busy}>
                    <Icon name={cancelled ? 'arrowL' : 'check'} />
                    {done ? 'Not done' : cancelled ? 'Reopen' : 'Mark done'}
                </button>
                {!finished && <StartFocus taskId={task.id} className="btn btn-secondary btn-lg" />}
            </div>
            {cancelling && <CancelTask task={task} onClose={() => setCancelling(false)} />}
            {asking && (
                <Modal
                    title={waitingOn ? `Still waiting on “${waitingOn.title}”` : `${kids(openKids.length)} still open`}
                    onClose={() => setAsking(false)}
                >
                    <div className="vform" onKeyDown={(e) => e.key === 'Escape' && (e.stopPropagation(), setAsking(false))}>
                        <p className="muted">
                            {waitingOn ? `“${task.title}” starts after it, and it isn’t done yet.` : ''}
                            {waitingOn && openKids.length ? ` ${kids(openKids.length)} still open too.` : ''}
                            {!waitingOn ? 'Complete them too, or only this task?' : ''}
                        </p>
                        <div className="vform-foot">
                            <button type="button" className="btn btn-ghost" onClick={() => setAsking(false)}>
                                Keep it open
                            </button>
                            <div className="vform-foot-right">
                                {openKids.length > 0 ? (
                                    <>
                                        <button
                                            type="button"
                                            className="btn btn-secondary"
                                            onClick={() => {
                                                setAsking(false);
                                                complete({ anyway: true, children: 'leave' });
                                            }}
                                        >
                                            Only this one
                                        </button>
                                        <button
                                            type="button"
                                            className="btn btn-primary"
                                            autoFocus
                                            onClick={() => {
                                                setAsking(false);
                                                complete({ anyway: true, children: 'complete' });
                                            }}
                                        >
                                            Complete them too
                                        </button>
                                    </>
                                ) : (
                                    <button
                                        type="button"
                                        className="btn btn-primary"
                                        autoFocus
                                        onClick={() => {
                                            setAsking(false);
                                            complete({ anyway: true });
                                        }}
                                    >
                                        Complete anyway
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>
                </Modal>
            )}
            {deleting && (
                <Modal title={`Delete “${task.title}”?`} onClose={() => setDeleting(false)}>
                    <div className="vform" onKeyDown={(e) => e.key === 'Escape' && (e.stopPropagation(), setDeleting(false))}>
                        <p className="muted">
                            It has {kids(task.children.length)}. Delete them too, or keep them as tasks of their own? You can undo either.
                        </p>
                        <div className="vform-foot">
                            <button type="button" className="btn btn-ghost" onClick={() => setDeleting(false)}>
                                Cancel
                            </button>
                            <div className="vform-foot-right">
                                <button type="button" className="btn btn-secondary" onClick={() => remove('keep')}>
                                    Keep the subtasks
                                </button>
                                <button type="button" className="btn btn-primary" autoFocus onClick={() => remove('delete')}>
                                    Delete them too
                                </button>
                            </div>
                        </div>
                    </div>
                </Modal>
            )}
        </>
    );
}

/** "Cancel task": an optional reason; a repeating task can skip only this one or stop repeating. */
function CancelTask({ task, onClose }: { task: TaskDetail; onClose: () => void }) {
    const [reason, setReason] = useState('');
    const waiting = (task.blocks ?? []).filter((b) => isOpen(b.status)).length;
    // Open subtasks go with it unless unticked (Done and Delete ask the same).
    const openKids = task.children.filter((c) => isOpen(c.status)).length;
    const [withKids, setWithKids] = useState(true);
    const send = (skip: boolean) =>
        router.post(
            route('tasks.cancel', task.id),
            { reason: reason.trim() || null, skip, ...(openKids ? { children: withKids ? 'cancel' : 'leave' } : {}) },
            { preserveScroll: true, preserveState: true, onSuccess: onClose },
        );
    return (
        <Modal title={`Cancel “${task.title}”?`} onClose={onClose}>
            <div
                className="vform"
                onKeyDown={(e) => {
                    // Escape closes this box only, not the task panel behind it.
                    if (e.key === 'Escape') {
                        e.stopPropagation();
                        onClose();
                    }
                }}
            >
                {waiting > 0 && (
                    <p className="muted">{waiting === 1 ? '1 task waits' : `${waiting} tasks wait`} on this one. Cancelled, it won’t hold them up.</p>
                )}
                <div className="field">
                    <div className="field-label">
                        <label htmlFor="cancel-reason">Why? (optional)</label>
                    </div>
                    <textarea
                        id="cancel-reason"
                        className="input"
                        rows={2}
                        maxLength={300}
                        value={reason}
                        placeholder="Supervisor dropped this section"
                        onChange={(e) => setReason(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && !e.shiftKey) {
                                e.preventDefault();
                                send(!!task.repeat);
                            }
                        }}
                    />
                </div>
                {openKids > 0 && (
                    <label className="auth-check">
                        <input type="checkbox" checked={withKids} onChange={(e) => setWithKids(e.target.checked)} />
                        Cancel its {openKids === 1 ? 'open subtask' : `${openKids} open subtasks`} too
                    </label>
                )}
                <div className="vform-foot">
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Keep it
                    </button>
                    <div className="vform-foot-right">
                        {task.repeat ? (
                            <>
                                <button type="button" className="btn btn-secondary" onClick={() => send(false)}>
                                    Stop repeating
                                </button>
                                <button type="button" className="btn btn-primary" onClick={() => send(true)}>
                                    Skip this one
                                </button>
                            </>
                        ) : (
                            <button type="button" className="btn btn-primary" onClick={() => send(false)}>
                                Cancel task
                            </button>
                        )}
                    </div>
                </div>
            </div>
        </Modal>
    );
}

/** The task's history, newest first: the last few, or all of it. */
function TaskActivity({ items, clock }: { items: NonNullable<TaskDetail['activity']>; clock: Clock }) {
    const [all, setAll] = useState(false);
    if (!items.length) return null;
    return (
        <section className="p-section">
            <div className="p-section-head">
                <h3 className="p-section-title">Activity</h3>
            </div>
            <ol className="p-activity">
                {(all ? items : items.slice(0, 6)).map((a, i) => (
                    <li key={i}>
                        <span>{a.text}</span>
                        <span className="muted">
                            {a.at ? `${clock.date(a.at, { weekday: 'short', day: 'numeric', month: 'short' })}, ${clock.time(a.at)}` : ''}
                            {a.via ? ` · via ${a.via}` : ''}
                        </span>
                    </li>
                ))}
            </ol>
            {items.length > 6 && (
                <button type="button" className="link" onClick={() => setAll(!all)}>
                    {all ? 'Show less' : `Show all ${items.length}`}
                </button>
            )}
        </section>
    );
}

const WEEK = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const BEFORE: [string, string][] = [
    ['15m', '15 min before'],
    ['1h', '1 hour before'],
    ['3h', '3 hours before'],
    ['0d', 'Due day, 9:00 am'],
    ['1d', '1 day before, 9:00 am'],
    ['2d', '2 days before, 9:00 am'],
    ['7d', '1 week before, 9:00 am'],
];
const EVERY: [number, string][] = [
    [15, 'every 15 min'],
    [30, 'every 30 min'],
    [60, 'every hour'],
    [120, 'every 2 hours'],
];
const beforeKey = (r: TaskReminderInfo) => (r.minutesBefore != null ? { 15: '15m', 60: '1h', 180: '3h' }[r.minutesBefore] : `${r.daysBefore}d`);

/** "Tue 17 Nov, 9:00 am", "1 day before, 9:00 am", "Bug me every hour". */
function reminderText(r: TaskReminderInfo, clock: Clock): string {
    if (r.kind === 'at' && r.at) return `${clock.date(r.at, { weekday: 'short', day: 'numeric', month: 'short' })}, ${clock.time(r.at)}`;
    if (r.kind === 'bug') return `Bug me ${EVERY.find(([m]) => m === r.everyMinutes)?.[1] ?? `every ${r.everyMinutes} min`}`;
    return BEFORE.find(([k]) => k === beforeKey(r))?.[1] ?? 'Before it’s due';
}

const addReminder = (taskId: number, data: Record<string, string | number>) =>
    router.post(route('reminders.store', taskId), data, { preserveScroll: true, preserveState: true });

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
