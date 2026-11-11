import { goalVar } from '@/lib/velocity';
import type { VelocityShared } from '@/types/velocity';
import { useForm, usePage } from '@inertiajs/react';
import { type FormEvent, type ReactNode, useEffect, useRef } from 'react';
import { Field, TextField } from './field';
import { Icon } from './icon';

/** A dialog over the page: Escape or a click outside closes it, and focus starts in the first field. */
export function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
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
    goalId: number | null;
    archived?: boolean;
}

const NEW_AREA = 'new';

/** New project, or edit one (with Archive / Restore). Areas and goals come from the side-menu data. */
export function ProjectDialog({ project, onClose }: { project?: ProjectValues; onClose: () => void }) {
    const { props } = usePage<VelocityShared>();
    const areas = props.nav?.areas ?? [];
    const goals = props.nav?.goals ?? [];
    const form = useForm({
        name: project?.name ?? '',
        description: project?.description ?? '',
        due_date: project?.dueDate ?? '',
        area: project?.areaId ? String(project.areaId) : areas.length ? '' : NEW_AREA,
        new_area: '',
        goal_id: project?.goalId ? String(project.goalId) : '',
    });
    const { data, setData, processing } = form;
    // Errors come back under the names sent to the server (area_id), not the form's own (area).
    const errors = form.errors as Record<string, string | undefined>;
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            name: d.name,
            description: d.description || null,
            due_date: d.due_date || null,
            area_id: d.area && d.area !== NEW_AREA ? Number(d.area) : null,
            new_area: d.area === NEW_AREA ? d.new_area : null,
            goal_id: d.goal_id ? Number(d.goal_id) : null,
        }));
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (project?.id) form.patch(route('projects.update', project.id), opts);
        else form.post(route('projects.store'), opts);
    };

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
                        <select id="p-area" className="input" value={data.area} onChange={(e) => setData('area', e.target.value)}>
                            <option value="">No area</option>
                            {areas.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                            <option value={NEW_AREA}>New area…</option>
                        </select>
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
                <div className="vform-row">
                    <Field id="p-goal" label="Goal" error={errors.goal_id}>
                        <select id="p-goal" className="input" value={data.goal_id} onChange={(e) => setData('goal_id', e.target.value)}>
                            <option value="">No goal</option>
                            {goals.map((g) => (
                                <option key={g.id} value={g.id}>
                                    {g.title}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <TextField
                        id="p-due"
                        label="Due date"
                        type="date"
                        value={data.due_date}
                        onValue={(v) => setData('due_date', v)}
                        error={errors.due_date}
                    />
                </div>
                <Foot
                    processing={processing}
                    saveLabel={project?.id ? 'Save' : 'Create project'}
                    onClose={onClose}
                    left={project?.id ? <ArchiveButton kind="projects" id={project.id} archived={!!project.archived} onDone={onClose} /> : undefined}
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
}

const GOAL_ICONS = ['target', 'cap', 'briefcase', 'heart', 'flame', 'zap'];

/** New goal, or edit one (with Archive). Colour is one of the three goal colours. */
export function GoalDialog({ goal, onClose }: { goal?: GoalValues; onClose: () => void }) {
    const form = useForm({
        title: goal?.title ?? '',
        why: goal?.why ?? '',
        target: goal?.target ?? '',
        color: goal?.color ?? 1,
        icon: goal?.icon ?? 'target',
    });
    const { data, setData, errors, processing } = form;
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, why: d.why || null, target: d.target || null }));
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (goal?.id) form.patch(route('goals.update', goal.id), opts);
        else form.post(route('goals.store'), opts);
    };

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
                    <TextField
                        id="g-target"
                        label="By when"
                        value={data.target}
                        onValue={(v) => setData('target', v)}
                        placeholder="Jun 2027, Every day…"
                        maxLength={60}
                        error={errors.target}
                    />
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
                    left={goal?.id ? <ArchiveButton kind="goals" id={goal.id} archived={false} onDone={onClose} /> : undefined}
                />
            </form>
        </Modal>
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
