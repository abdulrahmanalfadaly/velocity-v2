import { useReturnFocus } from '@/hooks/use-return-focus';
import { goalVar } from '@/lib/velocity';
import type { VelocityShared } from '@/types/velocity';
import { router, useForm, usePage } from '@inertiajs/react';
import { type FormEvent, type ReactNode, useEffect, useRef, useState } from 'react';
import { DatePick } from './date-pick';
import { Field, TextField } from './field';
import { Icon } from './icon';
import { Select } from './select';

/** A dialog over the page: Escape or a click outside closes it, and focus starts in the first field. */
export function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
    useReturnFocus();
    const box = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        document.addEventListener('keydown', onKey);
        box.current?.querySelector<HTMLElement>('input, textarea, select')?.focus();
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);
    return (
        <div className="overlay" onClick={(e) => e.target === e.currentTarget && onClose()}>
            <div className="dialog vform-dialog" role="dialog" aria-modal="true" aria-label={title} ref={box}>
                <div className="vform-head">
                    <h2>{title}</h2>
                    <button type="button" className="icon-btn" onClick={onClose} aria-label="Close">
                        <Icon name="x" />
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}

function Foot({ processing, saveLabel, left, onClose }: { processing: boolean; saveLabel: string; left?: ReactNode; onClose: () => void }) {
    return (
        <div className="vform-foot">
            {left ?? <span />}
            <span className="vform-foot-right">
                <button type="button" className="btn btn-secondary" onClick={onClose}>
                    Cancel
                </button>
                <button type="submit" className="btn btn-primary" disabled={processing}>
                    {saveLabel}
                </button>
            </span>
        </div>
    );
}

export interface ProjectValues {
    id?: number;
    name: string;
    description: string | null;
    dueDate: string | null;
    areaId: number | null;
    /** Every goal it serves; the first is its main one (its colour). */
    goalIds: number[];
    archived?: boolean;
    /** How many of its tasks are open (said when deleting it). */
    openCount?: number;
}

const NEW_AREA = 'new';

/** New project, or edit one (with Archive / Restore). Areas and goals come from the side-menu data. */
export function ProjectDialog({ project, areaId, onClose }: { project?: ProjectValues; areaId?: number; onClose: () => void }) {
    const { props } = usePage<VelocityShared>();
    const areas = props.nav?.areas ?? [];
    const goals = props.nav?.goals ?? [];
    const form = useForm({
        name: project?.name ?? '',
        description: project?.description ?? '',
        due_date: project?.dueDate ?? '',
        area: project?.areaId ? String(project.areaId) : areaId ? String(areaId) : areas.length ? '' : NEW_AREA,
        new_area: '',
        goal_ids: project?.goalIds ?? [],
    });
    const { data, setData, processing } = form;
    // Errors come back under the names sent to the server (area_id), not the form's own (area).
    const errors = form.errors as Record<string, string | undefined>;
    const goalError = errors.goal_ids ?? Object.entries(errors).find(([k]) => k.startsWith('goal_ids.'))?.[1];
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            name: d.name,
            description: d.description || null,
            due_date: d.due_date || null,
            area_id: d.area && d.area !== NEW_AREA ? Number(d.area) : null,
            new_area: d.area === NEW_AREA ? d.new_area : null,
            goal_ids: d.goal_ids,
        }));
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (project?.id) form.patch(route('projects.update', project.id), opts);
        else form.post(route('projects.store'), opts);
    };
    const [deleting, setDeleting] = useState(false);

    if (deleting && project?.id) {
        const n = project.openCount ?? 0;
        const remove = (tasks: 'inbox' | 'delete') => router.delete(route('projects.destroy', project.id!), { data: { tasks } });
        return (
            <Modal title={`Delete “${project.name}”?`} onClose={onClose}>
                <div className="vform">
                    <p className="vform-text-plain">
                        {n ? `It has ${n} open ${n === 1 ? 'task' : 'tasks'}. ` : ''}Its tasks can go to the Inbox, or be deleted with it. Its notes
                        and habits stay, out of the project. You can undo this.
                    </p>
                    <div className="vform-foot">
                        <button type="button" className="btn btn-ghost" onClick={() => setDeleting(false)}>
                            Back
                        </button>
                        <div className="vform-foot-right">
                            <button type="button" className="btn btn-secondary" onClick={() => remove('inbox')}>
                                Tasks to the Inbox
                            </button>
                            <button type="button" className="btn btn-primary" onClick={() => remove('delete')}>
                                Delete the tasks too
                            </button>
                        </div>
                    </div>
                </div>
            </Modal>
        );
    }

    return (
        <Modal title={project?.id ? 'Edit project' : 'New project'} onClose={onClose}>
            <form className="vform" onSubmit={submit}>
                <TextField
                    id="p-name"
                    label="Name"
                    value={data.name}
                    onValue={(v) => setData('name', v)}
                    error={errors.name}
                    required
                    maxLength={120}
                />
                <Field id="p-desc" label="What it's about" error={errors.description}>
                    <textarea
                        id="p-desc"
                        className="input vform-text"
                        rows={2}
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        maxLength={2000}
                    />
                </Field>
                <div className="vform-row">
                    <Field id="p-area" label="Area" error={errors.area_id || errors.new_area}>
                        <Select
                            id="p-area"
                            value={String(data.area)}
                            onChange={(v) => setData('area', v)}
                            options={[
                                { value: '', label: 'No area' },
                                ...areas.map((a) => ({ value: String(a.id), label: a.name })),
                                { value: NEW_AREA, label: 'New area…' },
                            ]}
                        />
                    </Field>
                    {data.area === NEW_AREA && (
                        <TextField
                            id="p-new-area"
                            label="New area's name"
                            value={data.new_area}
                            onValue={(v) => setData('new_area', v)}
                            placeholder="University, Work, Personal…"
                            maxLength={80}
                        />
                    )}
                </div>
                <Field id="p-goal" label="Goals it serves" error={goalError}>
                    <span className="hb-goal-pick" id="p-goal">
                        {data.goal_ids.map((id) => {
                            const g = goals.find((x) => x.id === id);
                            return (
                                <span key={id} className="note-proj-chip goal-pick-chip" style={{ ['--g' as string]: goalVar(g?.color) }}>
                                    <i aria-hidden="true" />
                                    <span>{g?.title ?? 'Goal'}</span>
                                    <button
                                        type="button"
                                        className="note-task-x"
                                        aria-label={`Take off ${g?.title ?? 'this goal'}`}
                                        onClick={() =>
                                            setData(
                                                'goal_ids',
                                                data.goal_ids.filter((x) => x !== id),
                                            )
                                        }
                                    >
                                        <Icon name="x" className="ic-xs" />
                                    </button>
                                </span>
                            );
                        })}
                        {goals.some((g) => !data.goal_ids.includes(g.id)) && (
                            <Select
                                className="note-project-sel"
                                value=""
                                label="Add a goal"
                                placeholder={data.goal_ids.length ? '+ Goal' : 'No goal'}
                                onChange={(v) => v && setData('goal_ids', [...data.goal_ids, Number(v)])}
                                options={goals.filter((g) => !data.goal_ids.includes(g.id)).map((g) => ({ value: String(g.id), label: g.title }))}
                            />
                        )}
                    </span>
                </Field>
                <Field id="p-due" label="Due date" error={errors.due_date}>
                    <DatePick id="p-due" value={data.due_date} onChange={(v) => setData('due_date', v)} placeholder="No due date" />
                </Field>
                <Foot
                    processing={processing}
                    saveLabel={project?.id ? 'Save' : 'Create project'}
                    onClose={onClose}
                    left={
                        project?.id ? (
                            <span className="vform-foot-right">
                                <ArchiveButton kind="projects" id={project.id} archived={!!project.archived} onDone={onClose} />
                                <DeleteButton onClick={() => setDeleting(true)} />
                            </span>
                        ) : undefined
                    }
                />
            </form>
        </Modal>
    );
}

export interface GoalValues {
    id?: number;
    title: string;
    why: string | null;
    target: string | null;
    color: number;
    icon: string | null;
    status?: 'active' | 'paused' | 'achieved' | 'dropped';
    targetDate?: string | null;
    progressMode?: 'tasks' | 'milestones' | 'manual';
    progress?: number | null;
}

const GOAL_ICONS = ['target', 'cap', 'briefcase', 'heart', 'flame', 'zap'];
type GoalStatus = NonNullable<GoalValues['status']>;
type GoalProgress = NonNullable<GoalValues['progressMode']>;

/** New goal, or edit one (with Archive). Colour is one of the three goal colours. */
export function GoalDialog({ goal, onClose }: { goal?: GoalValues; onClose: () => void }) {
    const form = useForm({
        title: goal?.title ?? '',
        why: goal?.why ?? '',
        target: goal?.target ?? '',
        color: goal?.color ?? 1,
        icon: goal?.icon ?? 'target',
        status: goal?.status ?? 'active',
        target_date: goal?.targetDate ?? '',
        progress_mode: goal?.progressMode ?? 'tasks',
        progress: goal?.progress ?? 0,
    });
    const { data, setData, errors, processing } = form;
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            ...d,
            why: d.why || null,
            target: d.target || null,
            target_date: d.target_date || null,
            progress: d.progress_mode === 'manual' ? Number(d.progress) : null,
        }));
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (goal?.id) form.patch(route('goals.update', goal.id), opts);
        else form.post(route('goals.store'), opts);
    };
    const [deleting, setDeleting] = useState(false);

    if (deleting && goal?.id) {
        return (
            <Modal title={`Delete “${goal.title}”?`} onClose={onClose}>
                <div className="vform">
                    <p className="vform-text-plain">Its projects and tasks stay, without a goal. You can undo this.</p>
                    <div className="vform-foot">
                        <button type="button" className="btn btn-ghost" onClick={() => setDeleting(false)}>
                            Back
                        </button>
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={() => router.delete(route('goals.destroy', goal.id!), { preserveScroll: true, onSuccess: onClose })}
                        >
                            Delete goal
                        </button>
                    </div>
                </div>
            </Modal>
        );
    }

    return (
        <Modal title={goal?.id ? 'Edit goal' : 'New goal'} onClose={onClose}>
            <form className="vform" onSubmit={submit}>
                <TextField
                    id="g-title"
                    label="Goal"
                    value={data.title}
                    onValue={(v) => setData('title', v)}
                    error={errors.title}
                    required
                    maxLength={160}
                />
                <Field id="g-why" label="Why it matters" error={errors.why}>
                    <textarea id="g-why" className="input vform-text" rows={2} value={data.why} onChange={(e) => setData('why', e.target.value)} />
                </Field>
                <div className="vform-row">
                    <Field id="g-date" label="Target date" error={errors.target_date}>
                        <DatePick id="g-date" value={data.target_date} onChange={(v) => setData('target_date', v)} placeholder="No date" />
                    </Field>
                    <TextField
                        id="g-target"
                        label="Or in words"
                        value={data.target}
                        onValue={(v) => setData('target', v)}
                        placeholder="Jun 2027, Every day…"
                        maxLength={60}
                        error={errors.target}
                    />
                </div>
                <div className="vform-row">
                    <Field id="g-status" label="Status" error={errors.status}>
                        <Select
                            id="g-status"
                            value={data.status}
                            search={false}
                            onChange={(v) => setData('status', v as GoalStatus)}
                            options={[
                                { value: 'active', label: 'Active' },
                                { value: 'paused', label: 'Paused' },
                                { value: 'achieved', label: 'Achieved' },
                                { value: 'dropped', label: 'Dropped' },
                            ]}
                        />
                    </Field>
                    <Field id="g-progress" label="Progress" error={errors.progress_mode || errors.progress}>
                        <Select
                            id="g-progress"
                            value={data.progress_mode}
                            search={false}
                            onChange={(v) => setData('progress_mode', v as GoalProgress)}
                            options={[
                                { value: 'tasks', label: 'From its tasks' },
                                { value: 'milestones', label: 'From milestones' },
                                { value: 'manual', label: 'By hand' },
                            ]}
                        />
                    </Field>
                </div>
                {data.progress_mode === 'manual' && (
                    <Field id="g-pct" label={`Progress: ${data.progress}%`}>
                        <input
                            id="g-pct"
                            className="g-range"
                            type="range"
                            min={0}
                            max={100}
                            step={5}
                            value={data.progress}
                            onChange={(e) => setData('progress', Number(e.target.value))}
                        />
                    </Field>
                )}
                <div className="vform-row">
                    <Field id="g-color" label="Colour" error={errors.color}>
                        <div className="vform-swatches" role="radiogroup" id="g-color">
                            {[1, 2, 3].map((c) => (
                                <button
                                    key={c}
                                    type="button"
                                    role="radio"
                                    aria-checked={data.color === c}
                                    aria-label={['Rose', 'Teal', 'Olive'][c - 1]}
                                    style={{ ['--g' as string]: goalVar(c) }}
                                    onClick={() => setData('color', c)}
                                />
                            ))}
                        </div>
                    </Field>
                </div>
                <Field id="g-icon" label="Icon">
                    <div className="vform-icons" role="radiogroup" id="g-icon">
                        {GOAL_ICONS.map((i) => (
                            <button
                                key={i}
                                type="button"
                                role="radio"
                                aria-checked={data.icon === i}
                                aria-label={i}
                                onClick={() => setData('icon', i)}
                            >
                                <Icon name={i} />
                            </button>
                        ))}
                    </div>
                </Field>
                <Foot
                    processing={processing}
                    saveLabel={goal?.id ? 'Save' : 'Create goal'}
                    onClose={onClose}
                    left={
                        goal?.id ? (
                            <span className="vform-foot-right">
                                <ArchiveButton kind="goals" id={goal.id} archived={false} onDone={onClose} />
                                <DeleteButton onClick={() => setDeleting(true)} />
                            </span>
                        ) : undefined
                    }
                />
            </form>
        </Modal>
    );
}

function DeleteButton({ onClick }: { onClick: () => void }) {
    return (
        <button type="button" className="btn btn-ghost" onClick={onClick}>
            <Icon name="trash" />
            Delete
        </button>
    );
}

function ArchiveButton({ kind, id, archived, onDone }: { kind: 'projects' | 'goals'; id: number; archived: boolean; onDone: () => void }) {
    const form = useForm({});
    return (
        <button
            type="button"
            className="btn btn-ghost"
            disabled={form.processing}
            onClick={() => form.post(route(`${kind}.${archived ? 'unarchive' : 'archive'}`, id), { preserveScroll: true, onSuccess: onDone })}
        >
            <Icon name="archive" />
            {archived ? 'Restore' : 'Archive'}
        </button>
    );
}
