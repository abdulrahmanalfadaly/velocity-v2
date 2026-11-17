import { DatePick } from '@/components/velocity/date-pick';
import { Field } from '@/components/velocity/field';
import { Modal } from '@/components/velocity/forms';
import { Pop } from '@/components/velocity/habit-parts';
import { Icon } from '@/components/velocity/icon';
import { Select } from '@/components/velocity/select';
import { Clock, fmtHours } from '@/lib/velocity';
import type { RepeatRule, VelocityShared } from '@/types/velocity';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';

export interface Template {
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

type Projects = { id: number; name: string }[];

export const daysAfter = (n: number) => (n === 0 ? 'the day it’s used' : n === 1 ? '1 day after use' : `${n} days after use`);

/** "Use": pick a due date (and project), then the new task opens in Up next. */
export function UseDialog({ template, projects, onClose }: { template: Template; projects: Projects; onClose: () => void }) {
    const { props } = usePage<VelocityShared>();
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
                    form.post(route('templates.use', template.id), { onSuccess: onClose });
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

/**
 * The template button in Up next's "Add a task" box: pick a template to make a task from it, or open
 * the Templates page to make and change them. The templates load when the button is first pressed.
 */
export function TemplatePick({ templates, projects }: { templates?: Template[]; projects: Projects }) {
    const [at, setAt] = useState<{ x: number; y: number } | null>(null);
    const [using, setUsing] = useState<Template | null>(null);
    const close = useCallback(() => setAt(null), []);
    return (
        <>
            <button
                type="button"
                className="icon-btn add-tpl"
                aria-label="Use a template"
                title="Use a template"
                aria-expanded={!!at}
                onClick={(e) => {
                    const r = e.currentTarget.getBoundingClientRect();
                    if (!templates) router.reload({ only: ['templates'] });
                    setAt(at ? null : { x: r.right - 260, y: r.bottom + 6 });
                }}
            >
                <Icon name="file" />
            </button>
            {at && (
                <Pop x={at.x} y={at.y} label="Use a template" onClose={close}>
                    {templates === undefined ? (
                        <p className="tpl-pick-empty">Loading…</p>
                    ) : templates.length === 0 ? (
                        <p className="tpl-pick-empty">No templates yet.</p>
                    ) : (
                        <div className="tpl-pick-list">
                            {templates.map((t) => (
                                <button
                                    key={t.id}
                                    type="button"
                                    className="bmenu-item tpl-pick-item"
                                    onClick={() => {
                                        setAt(null);
                                        setUsing(t);
                                    }}
                                >
                                    <Icon name="file" />
                                    <span>{t.name}</span>
                                </button>
                            ))}
                        </div>
                    )}
                    <div className="bmenu-sep" />
                    <Link href="/tasks/templates" className="bmenu-item">
                        <Icon name="sliders" />
                        Manage templates
                    </Link>
                </Pop>
            )}
            {using && <UseDialog template={using} projects={projects} onClose={() => setUsing(null)} />}
        </>
    );
}
