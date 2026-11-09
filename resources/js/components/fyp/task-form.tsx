import { Icon } from '@/components/velocity/icon';
import { PriorityBar } from '@/components/velocity/ui';
import { Clock, CRIT, fmt1 } from '@/lib/velocity';
import type { CriterionKey, VelocityShared } from '@/types/velocity';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { CRITERION, IMPORTANCE_LABELS, trim, URGENCY_LABELS } from './terms';

export interface FormTask {
    id: number;
    title: string;
    notes: string | null;
    importance: number;
    urgency: number;
    dueAt: string | null;
    estHours: number;
}

interface Preview {
    rank: number;
    total: number;
    score: number;
    contrib: Record<CriterionKey, number>;
    criteria: Record<CriterionKey, number>;
    above: string | null;
    params: { weights: Record<CriterionKey, number>; horizonDays: number; hoursPerDay: number };
}

const DUE_PRESETS: [number, string][] = [
    [0, 'Today'],
    [1, 'Tomorrow'],
    [3, 'In 3 days'],
    [7, 'In a week'],
];
const HOUR_PRESETS: [number, string][] = [
    [0.25, '15 min'],
    [0.5, '30 min'],
    [1, '1 h'],
    [2, '2 h'],
    [4, '4 h'],
    [8, '8 h'],
];

/** "2026-11-09" + n days, at 23:59 – the end of that day in the user's own zone. */
function endOfDay(today: string, n: number): string {
    const d = new Date(`${today}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + n);
    return `${d.toISOString().slice(0, 10)}T23:59`;
}

/**
 * The task form of the first version (FR2/FR3): one field per attribute, with
 * a live preview of the score and the place in the ranking, worked out by the
 * server with the student's own weights.
 */
export function TaskFormDialog({ task, onClose }: { task?: FormTask; onClose: () => void }) {
    const { props } = usePage<VelocityShared>();
    const clock = Clock.from(props.clock);
    const editing = !!task;
    const [title, setTitle] = useState(task?.title ?? '');
    const [notes, setNotes] = useState(task?.notes ?? '');
    const [importance, setImportance] = useState(task?.importance ?? 3);
    const [urgency, setUrgency] = useState(task?.urgency ?? 3);
    const [due, setDue] = useState(clock.localInput(task?.dueAt ?? null));
    const [noDue, setNoDue] = useState(editing && !task?.dueAt);
    const [hoursText, setHoursText] = useState(task ? String(task.estHours) : '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [preview, setPreview] = useState<Preview | null>(null);
    const [saving, setSaving] = useState(false);
    const titleRef = useRef<HTMLInputElement>(null);
    const est = Number(hoursText);
    const ready = hoursText.trim() !== '' && est > 0;
    const dueValue = noDue || !due ? null : due;

    useEffect(() => titleRef.current?.focus(), []);
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    useEffect(() => {
        if (!ready) {
            setPreview(null);
            return;
        }
        const ctl = new AbortController();
        const timer = setTimeout(() => {
            fetch(route('tasks.preview'), {
                method: 'POST',
                signal: ctl.signal,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
                },
                body: JSON.stringify({ importance, urgency, due_at: dueValue, est_hours: est, except: task?.id ?? null }),
            })
                .then((r) => (r.ok ? r.json() : null))
                .then((d) => d && setPreview(d.preview))
                .catch(() => {});
        }, 200);
        return () => {
            clearTimeout(timer);
            ctl.abort();
        };
    }, [importance, urgency, dueValue, est, ready, task?.id]);

    const submit = () => {
        if (saving) return;
        setSaving(true);
        const data = { title, notes: notes.trim() ? notes : null, importance, urgency, due_at: dueValue, est_hours: ready ? est : null };
        const opts = {
            preserveScroll: true,
            onError: (e: Record<string, string>) => setErrors(e),
            onSuccess: onClose,
            onFinish: () => setSaving(false),
        };
        if (task) router.patch(route('tasks.update', task.id), { ...data, form: true }, opts);
        else router.post(route('tasks.store'), data, opts);
    };

    const today = clock.localInput(clock.now.toISOString()).slice(0, 10);
    const placeText = preview
        ? `${editing ? 'Would move to' : 'Would rank'} #${preview.rank} of ${preview.total}${preview.above ? `, just below “${preview.above}”.` : ' – the top of your list.'}`
        : 'Fill in the details to see the score.';

    return (
        <div className="dialog fyp-form" role="dialog" aria-modal="true" aria-labelledby="fyp-form-title">
            <div className="fyp-form-head">
                <div>
                    <h2 id="fyp-form-title">{editing ? 'Edit task' : 'New task'}</h2>
                    <p className="muted">Rate the task honestly; Velocity does the weighing. The preview shows where it will land.</p>
                </div>
                <button className="icon-btn" onClick={onClose} aria-label="Close">
                    <Icon name="x" />
                </button>
            </div>

            <div className="fyp-form-body">
                <form
                    className="fyp-fields"
                    noValidate
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                >
                    <div className={`field${errors.title ? ' is-invalid' : ''}`}>
                        <label htmlFor="f-title">Title</label>
                        <input
                            ref={titleRef}
                            id="f-title"
                            className="input input-lg"
                            value={title}
                            maxLength={150}
                            onChange={(e) => setTitle(e.target.value)}
                            placeholder="e.g. Software Engineering assignment report"
                            autoComplete="off"
                        />
                        {errors.title && <p className="field-error">{errors.title}</p>}
                    </div>
                    <div className="field">
                        <label htmlFor="f-notes">
                            Notes <span className="field-optional">optional</span>
                        </label>
                        <textarea
                            id="f-notes"
                            className="input"
                            rows={2}
                            value={notes}
                            maxLength={5000}
                            onChange={(e) => setNotes(e.target.value)}
                            placeholder="Module, grade weight, who is waiting…"
                        />
                    </div>

                    <div className="field-grid">
                        <Scale
                            k="I"
                            label="Importance"
                            hint="How much it matters, e.g. its weight in your grade."
                            labels={IMPORTANCE_LABELS}
                            value={importance}
                            onChange={setImportance}
                        />
                        <Scale
                            k="U"
                            label="Urgency"
                            hint="Time sensitivity the due date misses, e.g. others waiting."
                            labels={URGENCY_LABELS}
                            value={urgency}
                            onChange={setUrgency}
                        />
                    </div>

                    <div className="field-grid">
                        <div className={`field${errors.due_at ? ' is-invalid' : ''}`}>
                            <label htmlFor="f-due">
                                <i className="sw sw-D" />
                                Due date
                            </label>
                            <input
                                id="f-due"
                                className="input"
                                type="datetime-local"
                                value={noDue ? '' : due}
                                disabled={noDue}
                                onChange={(e) => setDue(e.target.value)}
                            />
                            <div className="chips" role="group" aria-label="Quick due dates">
                                {DUE_PRESETS.map(([n, label]) => (
                                    <button
                                        key={n}
                                        type="button"
                                        className="chip-btn"
                                        onClick={() => {
                                            setNoDue(false);
                                            setDue(endOfDay(today, n));
                                        }}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <label className="switch">
                                <input type="checkbox" checked={noDue} onChange={(e) => setNoDue(e.target.checked)} />
                                <span className="switch-track" aria-hidden="true" />
                                <span>No due date</span>
                            </label>
                            {errors.due_at && <p className="field-error">{errors.due_at}</p>}
                        </div>
                        <div className={`field${errors.est_hours ? ' is-invalid' : ''}`}>
                            <label htmlFor="f-hours">
                                <i className="sw sw-P" />
                                Estimated duration
                            </label>
                            <div className="input-group">
                                <input
                                    id="f-hours"
                                    className="input"
                                    type="number"
                                    min={0.1}
                                    max={500}
                                    step={0.25}
                                    inputMode="decimal"
                                    value={hoursText}
                                    placeholder="2"
                                    onChange={(e) => setHoursText(e.target.value)}
                                />
                                <span>hours</span>
                            </div>
                            <div className="chips" role="group" aria-label="Quick durations">
                                {HOUR_PRESETS.map(([h, label]) => (
                                    <button
                                        key={h}
                                        type="button"
                                        className="chip-btn"
                                        aria-pressed={est === h}
                                        onClick={() => setHoursText(String(h))}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <p className="field-hint">Most people underestimate; add a margin.</p>
                            {errors.est_hours && <p className="field-error">{errors.est_hours}</p>}
                        </div>
                    </div>
                    <button type="submit" hidden />
                </form>

                <aside className="fyp-preview" aria-live="polite" aria-label="Score preview">
                    <p className="eyebrow">Live preview</p>
                    <div className="fyp-preview-score">
                        <b>{preview ? fmt1(preview.score) : '–'}</b>
                        <span>/ 100</span>
                    </div>
                    <p className="fyp-preview-rank">{placeText}</p>
                    <PriorityBar contrib={preview?.contrib ?? { I: 0, U: 0, D: 0, P: 0 }} size="pbar-lg" score={preview?.score ?? 0} />
                    <ul className="fyp-preview-list">
                        {CRIT.map((c) => (
                            <li key={c.k}>
                                <span>
                                    <i className={`sw sw-${c.k}`} />
                                    {CRITERION[c.k]}
                                </span>
                                <span className="muted">c = {preview ? preview.criteria[c.k].toFixed(2) : '0.00'}</span>
                                <b>{preview ? fmt1(preview.contrib[c.k]) : '0.0'}</b>
                            </li>
                        ))}
                    </ul>
                    {preview && (
                        <p className="fyp-preview-note">
                            Uses your weights ({CRIT.map((c) => preview.params.weights[c.k]).join(' / ')}%), horizon{' '}
                            {trim(preview.params.horizonDays)} d and {trim(preview.params.hoursPerDay)} h/day.{' '}
                            <a className="link" href="/weights">
                                Change
                            </a>
                        </p>
                    )}
                </aside>
            </div>

            <div className="fyp-form-foot">
                <button className="btn btn-ghost" onClick={onClose}>
                    Cancel
                </button>
                <button className="btn btn-primary btn-lg" onClick={submit} disabled={saving}>
                    <Icon name={editing ? 'check' : 'plus'} />
                    {editing ? 'Save changes' : 'Add task'}
                </button>
            </div>
        </div>
    );
}

function Scale({
    k,
    label,
    hint,
    labels,
    value,
    onChange,
}: {
    k: 'I' | 'U';
    label: string;
    hint: string;
    labels: string[];
    value: number;
    onChange: (v: number) => void;
}) {
    return (
        <fieldset className="field">
            <legend>
                <i className={`sw sw-${k}`} />
                {label}
            </legend>
            <p className="field-hint">{hint}</p>
            <div className="fyp-scale" data-c={k} role="radiogroup" aria-label={label}>
                {[1, 2, 3, 4, 5].map((v) => (
                    <button key={v} type="button" role="radio" aria-checked={value === v} onClick={() => onChange(v)}>
                        <b>{v}</b>
                        <span>{labels[v]}</span>
                    </button>
                ))}
            </div>
        </fieldset>
    );
}

/** The form in its own layer, closed by clicking outside or Escape. */
export function TaskFormOverlay({ task, onClose }: { task?: FormTask; onClose: () => void }) {
    return (
        <div
            className="overlay fyp-overlay"
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
        >
            <TaskFormDialog task={task} onClose={onClose} />
        </div>
    );
}
