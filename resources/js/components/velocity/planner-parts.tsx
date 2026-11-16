import { fmtHours } from '@/lib/velocity';
import type { VelocityShared } from '@/types/velocity';
import { Link, router, usePage } from '@inertiajs/react';
import { type FormEvent, type ReactNode, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { DatePick } from './date-pick';
import { Field, TextField } from './field';
import { Modal } from './forms';
import { Icon } from './icon';
import { Select, type SelectOption } from './select';

/** Working hours, Monday first: { start, end } or null for a day off. */
export type Hours = ({ start: string; end: string } | null)[];

/** Time booked on the Planner: for a task, for a whole project, or reserved ("Gym"). */
export interface PlanBlock {
    id: number;
    kind: 'task' | 'project' | 'reserved';
    taskId: number | null;
    projectId: number | null;
    title: string;
    start: string;
    end: string;
    pinned: boolean;
    locked: boolean;
    rank: number | null;
    dueAt: string | null;
    /** A project's block: its top task by the formula. */
    next: { id: number; title: string; rank: number | null } | null;
    /** A task's block whose task can't be worked on now: why ("Waiting for Dr. Lim"). */
    blocked?: { kind: 'waiting' | 'blocked' | 'held' | 'later'; why: string } | null;
}

/** What a click on an empty spot of the Planner adds. */
export type NewKind = 'event' | 'project' | 'reserved';
const NEW_KINDS: [NewKind, string, string][] = [
    ['event', 'Event', 'calendar'],
    ['project', 'Project time', 'folder'],
    ['reserved', 'Reserved', 'ban'],
];

export function KindSeg({ value, onChange }: { value: NewKind; onChange: (k: NewKind) => void }) {
    return (
        <div className="seg ev-seg kind-seg" role="radiogroup" aria-label="What to add">
            {NEW_KINDS.map(([k, label, icon]) => (
                <button key={k} type="button" role="radio" aria-checked={value === k} onClick={() => onChange(k)}>
                    <Icon name={icon} className="ic-sm" />
                    {label}
                </button>
            ))}
        </div>
    );
}

/** New time for a whole project, or reserved time with a name ("Gym", "Keep free"): planning books around it. */
export function TimeDialog({
    kind,
    date,
    start,
    end,
    top,
    onClose,
}: {
    kind: 'project' | 'reserved';
    date: string;
    start: string;
    end: string;
    top: ReactNode;
    onClose: () => void;
}) {
    const { props } = usePage<VelocityShared>();
    const options: SelectOption[] = [
        ...(props.nav?.loose ?? []).map((p) => ({ value: String(p.id), label: p.name })),
        ...(props.nav?.areas ?? []).flatMap((a) => a.projects.map((p) => ({ value: String(p.id), label: p.name, group: a.name }))),
    ];
    const [project, setProject] = useState('');
    const [label, setLabel] = useState('');
    const [day, setDay] = useState(date);
    const [from, setFrom] = useState(start);
    const [to, setTo] = useState(end);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        const mins = (v: string) => Number(v.slice(0, 2)) * 60 + Number(v.slice(3, 5));
        const minutes = mins(to) - mins(from);
        if (minutes < 5) {
            setErrors({ end: 'The end has to be after the start.' });
            return;
        }
        router.post(
            route('blocks.store'),
            {
                kind,
                project_id: kind === 'project' ? Number(project) || null : null,
                label: kind === 'reserved' ? label : null,
                date: day,
                start: from,
                minutes,
            },
            { ...keep, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose, onError: setErrors },
        );
    };
    return (
        <Modal title={kind === 'project' ? 'New project time' : 'Reserve time'} onClose={onClose}>
            <form className="vform" onSubmit={submit}>
                {top}
                {kind === 'project' ? (
                    <Field id="tb-project" label="Project" error={errors.project_id}>
                        <Select id="tb-project" value={project} options={options} onChange={setProject} placeholder="Choose a project" />
                    </Field>
                ) : (
                    <TextField
                        id="tb-label"
                        label="Name"
                        value={label}
                        onValue={setLabel}
                        error={errors.label}
                        required
                        maxLength={80}
                        placeholder="Gym"
                    />
                )}
                <div className="vform-row">
                    <Field id="tb-date" label="Date" error={errors.date}>
                        <DatePick id="tb-date" value={day} onChange={(v) => v && setDay(v)} clearable={false} placeholder="Pick a day" />
                    </Field>
                    <div className="vform-row ev-times">
                        <Field id="tb-start" label="From" error={errors.start}>
                            <DatePick id="tb-start" mode="time" value={from} onChange={(v) => v && setFrom(v)} clearable={false} />
                        </Field>
                        <Field id="tb-end" label="To" error={errors.end ?? errors.minutes}>
                            <DatePick id="tb-end" mode="time" value={to} onChange={(v) => v && setTo(v)} clearable={false} />
                        </Field>
                    </div>
                </div>
                <div className="vform-foot">
                    <span />
                    <span className="vform-foot-right">
                        <button type="button" className="btn btn-secondary" onClick={onClose}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={busy}>
                            Add
                        </button>
                    </span>
                </div>
            </form>
        </Modal>
    );
}

const keep = { preserveScroll: true, preserveState: true };
const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

/** The part of each day that "Plan" may fill. */
export function HoursDialog({ hours, onClose }: { hours: Hours; onClose: () => void }) {
    const [rows, setRows] = useState(() => hours.map((h) => ({ on: h !== null, start: h?.start ?? '08:00', end: h?.end ?? '22:00' })));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const set = (i: number, patch: Partial<(typeof rows)[number]>) => setRows((r) => r.map((x, j) => (j === i ? { ...x, ...patch } : x)));
    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.put(
            route('planner.hours'),
            { hours: rows.map((r) => (r.on ? { start: r.start, end: r.end } : null)) },
            { ...keep, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose, onError: setErrors },
        );
    };
    return (
        <Modal title="Working hours" onClose={onClose}>
            <form className="vform" onSubmit={submit}>
                <div className="hours-rows">
                    {rows.map((r, i) => {
                        const error = errors[`hours.${i}.end`] ?? errors[`hours.${i}.start`];
                        return (
                            <div key={i} className={`hours-row${r.on ? '' : ' is-off'}`}>
                                <label className="auth-check">
                                    <input type="checkbox" checked={r.on} onChange={(e) => set(i, { on: e.target.checked })} />
                                    {DAYS[i]}
                                </label>
                                {r.on ? (
                                    <span className="hours-times">
                                        <DatePick
                                            mode="time"
                                            value={r.start}
                                            onChange={(v) => set(i, { start: v })}
                                            label={`${DAYS[i]} from`}
                                            clearable={false}
                                        />
                                        <span aria-hidden="true">–</span>
                                        <DatePick
                                            mode="time"
                                            value={r.end}
                                            onChange={(v) => set(i, { end: v })}
                                            label={`${DAYS[i]} to`}
                                            clearable={false}
                                        />
                                    </span>
                                ) : (
                                    <span className="hours-off">Day off</span>
                                )}
                                {error && <p className="field-error hours-error">{error}</p>}
                            </div>
                        );
                    })}
                </div>
                <div className="vform-foot">
                    <span />
                    <span className="vform-foot-right">
                        <button type="button" className="btn btn-secondary" onClick={onClose}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={busy}>
                            Save
                        </button>
                    </span>
                </div>
            </form>
        </Modal>
    );
}

/** What can be done with a block: exact times, focus on it now, open its task or project, or take it off the plan. */
export function BlockMenu({
    block,
    x,
    y,
    minutes,
    when,
    date,
    startMin,
    endMin,
    choices = [],
    nextUp = null,
    onClose,
}: {
    /** The first task still to book, by rank: what a blocked block's time can go to. */
    nextUp?: { taskId: number; title: string; rank: number | null } | null;
    block: PlanBlock;
    x: number;
    y: number;
    minutes: number;
    when: string;
    date: string;
    startMin: number;
    endMin: number;
    /** Ranked open tasks, for "Change task". */
    choices?: { id: number; title: string; rank: number }[];
    onClose: () => void;
}) {
    const hm = (m: number) => `${String(Math.floor(m / 60) % 24).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
    const mins = (v: string) => Number(v.slice(0, 2)) * 60 + Number(v.slice(3, 5));
    // Exact times: a new start keeps the end; a new end changes the length. Typed here, a locked block moves too.
    const setTimes = (s: number, e: number) => {
        const length = e > s ? e - s : e + 1440 - s;
        if (length < 5 || length > 720) return;
        router.patch(route('blocks.update', block.id), { date, start: hm(s), minutes: length, anyway: true }, keep);
        onClose();
    };
    const { props } = usePage<VelocityShared>();
    const box = useRef<HTMLDivElement>(null);
    // Opens where it was clicked, moved up as far as needed to fit on the screen.
    const [top, setTop] = useState(() => Math.max(8, y));
    useLayoutEffect(() => {
        const h = box.current?.offsetHeight ?? 0;
        setTop((t) => Math.max(8, Math.min(t, window.innerHeight - h - 8)));
    }, []);
    useEffect(() => {
        const onDown = (e: PointerEvent) => {
            const t = e.target as Element;
            if (!box.current?.contains(t) && !t.closest?.('.dp-pop, .sel-pop')) onClose();
        };
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        document.addEventListener('pointerdown', onDown, true);
        document.addEventListener('keydown', onKey);
        box.current?.querySelector<HTMLElement>('button, a')?.focus();
        return () => {
            document.removeEventListener('pointerdown', onDown, true);
            document.removeEventListener('keydown', onKey);
        };
    }, [onClose]);
    // A project's block focuses on its top task; reserved time has nothing to focus on.
    const focusId = block.kind === 'task' ? block.taskId : block.kind === 'project' ? (block.next?.id ?? null) : null;
    const focusing = focusId !== null && props.focusSession?.taskId === focusId;
    return (
        <div
            className="bmenu"
            role="menu"
            aria-label={block.title}
            ref={box}
            style={{ left: Math.max(8, Math.min(x, window.innerWidth - 268)), top }}
        >
            <div className="bmenu-head">
                <b>{block.title}</b>
                <span>{when}</span>
                {block.kind === 'project' && (
                    <span className="bmenu-next">
                        {block.next ? `Next: ${block.next.rank ? `#${block.next.rank} ` : ''}${block.next.title}` : 'No open tasks'}
                    </span>
                )}
            </div>
            {block.blocked && (
                <>
                    <p className="bmenu-warn">
                        <Icon name="alert" className="ic-sm" />
                        {block.blocked.why}
                    </p>
                    {nextUp && (
                        <button
                            role="menuitem"
                            className="bmenu-item"
                            onClick={() => {
                                router.patch(route('blocks.task', block.id), { task_id: nextUp.taskId }, keep);
                                onClose();
                            }}
                        >
                            <Icon name="arrowR" />
                            <span className="bmenu-grow">
                                Use for {nextUp.rank ? `#${nextUp.rank} ` : ''}
                                {nextUp.title}
                            </span>
                        </button>
                    )}
                </>
            )}
            <div className="bmenu-times">
                <DatePick
                    mode="time"
                    className="prop-input"
                    label="Starts"
                    value={hm(startMin)}
                    clearable={false}
                    onChange={(v) => v && setTimes(mins(v), endMin)}
                />
                <span aria-hidden="true">–</span>
                <DatePick
                    mode="time"
                    className="prop-input"
                    label="Ends"
                    value={hm(endMin)}
                    clearable={false}
                    onChange={(v) => v && setTimes(startMin, mins(v))}
                />
            </div>
            {focusId !== null && !focusing && (
                <button
                    role="menuitem"
                    className="bmenu-item"
                    onClick={() => {
                        router.post(route('focus.start'), { task_id: focusId, minutes: Math.min(minutes, 600) }, keep);
                        onClose();
                    }}
                >
                    <Icon name="play" />
                    Focus for {fmtHours(minutes / 60)}
                </button>
            )}
            {block.kind === 'task' && (
                <Link role="menuitem" className="bmenu-item" href={`/tasks?task=${block.taskId}`}>
                    <Icon name="arrowUpRight" />
                    Open task
                </Link>
            )}
            {block.kind === 'project' && (
                <Link role="menuitem" className="bmenu-item" href={`/projects/${block.projectId}`}>
                    <Icon name="folder" />
                    Open project
                </Link>
            )}
            {block.kind === 'task' && choices.length > 1 && (
                <Select
                    value=""
                    className="bmenu-item"
                    label="Change task"
                    search
                    options={choices.filter((c) => c.id !== block.taskId).map((c) => ({ value: String(c.id), label: `#${c.rank} ${c.title}` }))}
                    trigger={
                        <>
                            <Icon name="tasks" />
                            Change task
                        </>
                    }
                    onChange={(v) => {
                        router.patch(route('blocks.task', block.id), { task_id: Number(v) }, keep);
                        onClose();
                    }}
                />
            )}
            <button
                role="menuitem"
                className="bmenu-item"
                onClick={() => {
                    router.patch(route('blocks.lock', block.id), { locked: !block.locked }, keep);
                    onClose();
                }}
            >
                <Icon name="lock" />
                {block.locked ? 'Unlock' : 'Lock'}
            </button>
            <button
                role="menuitem"
                className="bmenu-item"
                onClick={() => {
                    router.delete(route('blocks.destroy', block.id), keep);
                    onClose();
                }}
            >
                <Icon name="x" />
                Remove from plan
            </button>
        </div>
    );
}
