import { DatePick } from '@/components/velocity/date-pick';
import { Field, TextField } from '@/components/velocity/field';
import { Modal } from '@/components/velocity/forms';
import { Icon } from '@/components/velocity/icon';
import { Select } from '@/components/velocity/select';
import { Dots } from '@/components/velocity/ui';
import VelocityShell from '@/layouts/velocity-shell';
import { Clock, fmtHours, LEVEL, REPEAT_CHOICES, repeatText } from '@/lib/velocity';
import type { RepeatRule, VelocityShared } from '@/types/velocity';
import { router, useForm, usePage } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

interface Template {
    id: number;
    name: string;
    title: string;
    notes: string | null;
    importance: number;
    urgency: number;
    estHours: number;
    /** Due this many days after it is used, at this time (none: the end of that day). */
    dueDays: number | null;
    dueTime: string | null;
    /** Can start this many days after it is used. */
    startDays: number | null;
    project: { id: number; name: string } | null;
    repeat: RepeatRule | null;
    steps: string[];
    used: number;
}
interface Props extends VelocityShared {
    templates: Template[];
    projects: { id: number; name: string }[];
}

const keep = { preserveScroll: true, preserveState: true };
const repeatKey = (r: RepeatRule | null) => (r ? `${r.every}-${r.unit}` : '');
const daysAfter = (n: number) => (n === 0 ? 'the day it’s used' : n === 1 ? '1 day after use' : `${n} days after use`);
/** "due 3 days after use, 17:00 · starts 1 day after use". */
const whenText = (t: Template) =>
    [
        t.dueDays != null ? `due ${daysAfter(t.dueDays)}${t.dueTime ? `, ${t.dueTime}` : ''}` : '',
        t.startDays ? `starts ${daysAfter(t.startDays)}` : '',
    ]
        .filter(Boolean)
        .join(' · ');

/** Templates: tasks to copy from. "Use" makes a new task with the template's steps. */
export default function Templates() {
    const { props } = usePage<Props>();
    const [dialog, setDialog] = useState<null | 'new' | Template>(null);
    const [using, setUsing] = useState<Template | null>(null);

    return (
        <VelocityShell title="Templates" section="tasks" active="templates">
            <div className="main-inner">
                <header className="page-head">
                    <div>
                        <p className="eyebrow is-section">Tasks</p>
                        <h1 className="h1">
                            Templates <span className="count">{props.templates.length}</span>
                        </h1>
                    </div>
                    <div className="page-actions">
                        <button className="btn btn-primary" onClick={() => setDialog('new')}>
                            <Icon name="plus" />
                            New template
                        </button>
                    </div>
                </header>
                {props.templates.length === 0 && (
                    <p className="list-empty">No templates yet. Open any task and click its template icon to save it as one.</p>
                )}
                <div className="tpl-list">
                    {props.templates.map((t) => (
                        <section className="card tpl" key={t.id}>
                            <div className="tpl-main">
                                <h2 className="tpl-name">{t.name}</h2>
                                <p className="tpl-sub">
                                    {t.project?.name ?? 'No project'} · {fmtHours(t.estHours)} · importance {LEVEL[t.importance].toLowerCase()} ·
                                    urgency {LEVEL[t.urgency].toLowerCase()}
                                    {t.repeat && ` · ${repeatText(t.repeat).toLowerCase()}`}
                                    {whenText(t) && ` · ${whenText(t)}`}
                                    {t.steps.length > 0 && ` · ${t.steps.length} ${t.steps.length === 1 ? 'step' : 'steps'}`}
                                    {t.used > 0 && ` · used ${t.used}×`}
                                </p>
                                {t.steps.length > 0 && (
                                    <ol className="tpl-steps">
                                        {t.steps.map((s, i) => (
                                            <li key={i}>{s}</li>
                                        ))}
                                    </ol>
                                )}
                            </div>
                            <div className="tpl-actions">
                                <button className="btn btn-primary btn-sm" onClick={() => setUsing(t)}>
                                    <Icon name="plus" className="ic-sm" />
                                    Use
                                </button>
                                <button className="btn btn-secondary btn-sm" onClick={() => setDialog(t)}>
                                    Edit
                                </button>
                                <button
                                    className="icon-btn"
                                    aria-label={`Delete template “${t.name}”`}
                                    title="Delete"
                                    onClick={() => router.delete(route('templates.destroy', t.id), keep)}
                                >
                                    <Icon name="x" />
                                </button>
                            </div>
                        </section>
                    ))}
                </div>
            </div>
            {dialog && <TemplateDialog template={dialog === 'new' ? undefined : dialog} projects={props.projects} onClose={() => setDialog(null)} />}
            {using && <UseDialog template={using} projects={props.projects} onClose={() => setUsing(null)} />}
        </VelocityShell>
    );
}

function TemplateDialog({ template, projects, onClose }: { template?: Template; projects: Props['projects']; onClose: () => void }) {
    const form = useForm({
        name: template?.name ?? '',
        title: template?.title ?? '',
        notes: template?.notes ?? '',
        importance: template?.importance ?? 3,
        urgency: template?.urgency ?? 3,
        est_hours: String(template?.estHours ?? 1),
        project_id: template?.project ? String(template.project.id) : '',
        repeat: repeatKey(template?.repeat ?? null),
        steps: (template?.steps ?? []).join('\n'),
        due_days: template?.dueDays != null ? String(template.dueDays) : '',
        due_time: template?.dueTime ?? '',
        start_days: template?.startDays ? String(template.startDays) : '',
    });
    const { data, setData, processing } = form;
    const errors = form.errors as Record<string, string | undefined>;
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => {
            const [every, unit] = d.repeat.split('-');
            return {
                name: d.name,
                title: d.title,
                notes: d.notes || null,
                importance: d.importance,
                urgency: d.urgency,
                est_hours: Number(d.est_hours),
                project_id: d.project_id ? Number(d.project_id) : null,
                repeat_unit: unit ?? null,
                repeat_every: Number(every) || 1,
                steps: d.steps.split('\n'),
                due_days: d.due_days === '' ? null : Number(d.due_days),
                due_time: d.due_days === '' ? null : d.due_time || null,
                start_days: d.start_days === '' ? null : Number(d.start_days),
            };
        });
        const opts = { ...keep, onSuccess: onClose };
        if (template) form.patch(route('templates.update', template.id), opts);
        else form.post(route('templates.store'), opts);
    };
    return (
        <Modal title={template ? 'Edit template' : 'New template'} onClose={onClose}>
            <form className="vform" onSubmit={submit}>
                <div className="vform-row">
                    <TextField
                        id="t-name"
                        label="Template name"
                        value={data.name}
                        onValue={(v) => setData('name', v)}
                        error={errors.name}
                        required
                        maxLength={120}
                    />
                    <TextField
                        id="t-title"
                        label="Task title"
                        value={data.title}
                        onValue={(v) => setData('title', v)}
                        error={errors.title}
                        required
                        maxLength={255}
                    />
                </div>
                <div className="vform-row">
                    <Field id="t-imp" label="Importance">
                        <div className="tpl-dots" id="t-imp">
                            <Dots n={data.importance} c="I" onPick={(v) => setData('importance', v)} />
                            {LEVEL[data.importance]}
                        </div>
                    </Field>
                    <Field id="t-urg" label="Urgency">
                        <div className="tpl-dots" id="t-urg">
                            <Dots n={data.urgency} c="U" onPick={(v) => setData('urgency', v)} />
                            {LEVEL[data.urgency]}
                        </div>
                    </Field>
                </div>
                <div className="vform-row">
                    <TextField
                        id="t-hours"
                        label="Hours of work"
                        type="number"
                        min={0.25}
                        max={500}
                        step={0.25}
                        value={data.est_hours}
                        onValue={(v) => setData('est_hours', v)}
                        error={errors.est_hours}
                    />
                    <Field id="t-project" label="Project" error={errors.project_id}>
                        <Select
                            id="t-project"
                            value={String(data.project_id)}
                            onChange={(v) => setData('project_id', v)}
                            options={[{ value: '', label: 'No project' }, ...projects.map((p) => ({ value: String(p.id), label: p.name }))]}
                        />
                    </Field>
                </div>
                <div className="vform-row">
                    <TextField
                        id="t-due-days"
                        label="Due (days after use)"
                        type="number"
                        min={0}
                        max={365}
                        placeholder="No due date"
                        value={data.due_days}
                        onValue={(v) => setData('due_days', v)}
                        error={errors.due_days}
                    />
                    <Field id="t-due-time" label="At" error={errors.due_time}>
                        <DatePick
                            id="t-due-time"
                            mode="time"
                            value={data.due_time}
                            onChange={(v) => setData('due_time', v)}
                            placeholder="End of the day"
                        />
                    </Field>
                    <TextField
                        id="t-start-days"
                        label="Can start (days after use)"
                        type="number"
                        min={0}
                        max={365}
                        placeholder="Right away"
                        value={data.start_days}
                        onValue={(v) => setData('start_days', v)}
                        error={errors.start_days}
                    />
                </div>
                <div className="vform-row">
                    <Field id="t-repeat" label="Repeats" error={errors.repeat_unit}>
                        <Select
                            id="t-repeat"
                            value={data.repeat}
                            onChange={(v) => setData('repeat', v)}
                            options={[
                                { value: '', label: 'Doesn’t repeat' },
                                ...REPEAT_CHOICES.map((r) => ({ value: repeatKey(r), label: repeatText(r) })),
                            ]}
                        />
                    </Field>
                </div>
                <Field id="t-steps" label="Steps (one per line)" error={errors.steps}>
                    <textarea
                        id="t-steps"
                        className="input vform-text"
                        rows={4}
                        value={data.steps}
                        onChange={(e) => setData('steps', e.target.value)}
                    />
                </Field>
                <Field id="t-notes" label="Notes" error={errors.notes}>
                    <textarea
                        id="t-notes"
                        className="input vform-text"
                        rows={2}
                        value={data.notes}
                        onChange={(e) => setData('notes', e.target.value)}
                    />
                </Field>
                <div className="vform-foot">
                    <span />
                    <span className="vform-foot-right">
                        <button type="button" className="btn btn-secondary" onClick={onClose}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={processing}>
                            {template ? 'Save' : 'Create template'}
                        </button>
                    </span>
                </div>
            </form>
        </Modal>
    );
}

/** "Use": pick a due date (and project), then the new task opens in Up next. */
function UseDialog({ template, projects, onClose }: { template: Template; projects: Props['projects']; onClose: () => void }) {
    const { props } = usePage<Props>();
    const clock = Clock.from(props.clock);
    // The template's own due date, counted from today (you can change it here).
    const today = clock.localInput(clock.now.toISOString()).slice(0, 10);
    const plus = (n: number) => {
        const d = new Date(`${today}T12:00:00Z`);
        d.setUTCDate(d.getUTCDate() + n);
        return d.toISOString().slice(0, 10);
    };
    const form = useForm({
        due_at: template.dueDays != null ? `${plus(template.dueDays)}T${template.dueTime ?? '23:59'}` : '',
        project_id: template.project ? String(template.project.id) : '',
    });
    const { data, setData, processing } = form;
    const errors = form.errors as Record<string, string | undefined>;
    return (
        <Modal title={`New task from “${template.name}”`} onClose={onClose}>
            <form
                className="vform"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((d) => ({ due_at: d.due_at || null, project_id: d.project_id ? Number(d.project_id) : null }));
                    form.post(route('templates.use', template.id));
                }}
            >
                <p className="tpl-use-title">
                    “{template.title}” · {fmtHours(template.estHours)}
                    {template.steps.length > 0 && ` · ${template.steps.length} steps`}
                    {template.startDays ? ` · can start ${daysAfter(template.startDays)}` : ''}
                </p>
                <div className="vform-row">
                    <Field id="u-due" label="Due" error={errors.due_at}>
                        <DatePick id="u-due" mode="datetime" value={data.due_at} onChange={(v) => setData('due_at', v)} placeholder="No due date" />
                    </Field>
                    <Field id="u-project" label="Project" error={errors.project_id}>
                        <Select
                            id="u-project"
                            value={String(data.project_id)}
                            onChange={(v) => setData('project_id', v)}
                            options={[{ value: '', label: 'No project' }, ...projects.map((p) => ({ value: String(p.id), label: p.name }))]}
                        />
                    </Field>
                </div>
                <div className="vform-foot">
                    <span />
                    <span className="vform-foot-right">
                        <button type="button" className="btn btn-secondary" onClick={onClose}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={processing}>
                            Add task
                        </button>
                    </span>
                </div>
            </form>
        </Modal>
    );
}
