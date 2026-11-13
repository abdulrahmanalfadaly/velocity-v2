import type { Clock } from '@/lib/velocity';
import type { HabitDay, HabitToday } from '@/types/velocity';
import { Link, router, useForm } from '@inertiajs/react';
import { type FormEvent, type ReactNode, useEffect, useRef, useState } from 'react';
import { Field, TextField } from './field';
import { Modal } from './forms';
import { Icon } from './icon';

export type HabitRow = HabitToday & { days: HabitDay[] };

export interface HabitForm {
    name: string;
    detail: string | null;
    schedule: 'daily' | 'days' | 'weekly';
    days: number[];
    per_week: number | null;
    target: number;
    unit: string | null;
    at_time: string | null;
    minutes: number | null;
    project_id: number | null;
}

export const keep = { preserveScroll: true, preserveState: true };
const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const at = (d: string) => new Date(`${d}T12:00:00Z`);
export const dayName = (d: string, clock: Clock, opts: Intl.DateTimeFormatOptions = { weekday: 'short', day: 'numeric', month: 'short' }) =>
    clock.date(at(d), { ...opts, timeZone: 'UTC' });

/** A habit as the edit dialog wants it. */
export const formOf = (h: HabitToday): HabitForm => ({
    name: h.name,
    detail: h.detail,
    schedule: h.schedule.perWeek ? 'weekly' : h.schedule.days.length ? 'days' : 'daily',
    days: h.schedule.days,
    per_week: h.schedule.perWeek,
    target: h.target,
    unit: h.unit,
    at_time: h.time,
    minutes: h.minutes,
    project_id: h.project?.id ?? null,
});

/** The line under a habit's name: progress, schedule, time. */
export function habitSub(h: HabitToday): string {
    const parts: string[] = [];
    if (h.week) parts.push(`${h.week.done} of ${h.week.need} this week`);
    else if (h.target > 1) parts.push(`${h.today} of ${h.target}${h.unit ? ` ${h.unit}` : ''}`);
    if (!h.week) parts.push(h.label);
    if (h.time) parts.push(h.time);
    if (h.detail) parts.push(h.detail);
    return parts.join(' · ');
}

export function Streak({ habit }: { habit: HabitToday }) {
    const unit = habit.streakUnit === 'week' ? 'week' : 'day';
    return (
        <span className="streak" title={`${habit.streak}-${unit} streak`} aria-label={`${habit.streak}-${unit} streak`}>
            <Icon name="flame" />
            {habit.streak}
            {unit === 'week' && <small>w</small>}
        </span>
    );
}

const STATE_TEXT: Record<HabitDay['state'], string> = {
    done: 'done',
    partial: 'partly done',
    skipped: 'skipped',
    missed: 'missed',
    todo: 'not done yet',
    open: 'no run',
    off: 'not on the schedule',
    future: 'still to come',
    before: 'before the habit started',
};

/** A day's mark: shape and icon carry the meaning, not colour alone. */
export function DayMark({
    day,
    target,
    clock,
    onClick,
    big = false,
}: {
    day: HabitDay;
    target: number;
    clock: Clock;
    onClick?: (el: HTMLElement) => void;
    big?: boolean;
}) {
    const label = `${dayName(day.date, clock, { weekday: 'long', day: 'numeric', month: 'long' })}: ${STATE_TEXT[day.state]}${
        day.state === 'partial' ? ` (${day.count} of ${target})` : ''
    }`;
    const inner =
        day.state === 'done' ? (
            <Icon name="check" />
        ) : day.state === 'skipped' ? (
            <Icon name="minus" />
        ) : day.state === 'partial' ? (
            <span>{day.count}</span>
        ) : null;
    const cls = `hd hd-${day.state}${big ? ' hd-big' : ''}`;
    if (!onClick) {
        return (
            <span className={cls} title={label} role="img" aria-label={label}>
                {inner}
            </span>
        );
    }
    return (
        <button type="button" className={cls} title={label} aria-label={label} onClick={(e) => onClick(e.currentTarget)}>
            {inner}
        </button>
    );
}

/** A small popover in a fixed place, closed by a click outside or Escape. */
export function Pop({
    x,
    y,
    label,
    onClose,
    children,
    width = 260,
}: {
    x: number;
    y: number;
    label: string;
    onClose: () => void;
    children: ReactNode;
    width?: number;
}) {
    const box = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const onDown = (e: PointerEvent) => !box.current?.contains(e.target as Node) && onClose();
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        document.addEventListener('pointerdown', onDown, true);
        document.addEventListener('keydown', onKey);
        box.current?.querySelector<HTMLElement>('input, button')?.focus();
        return () => {
            document.removeEventListener('pointerdown', onDown, true);
            document.removeEventListener('keydown', onKey);
        };
    }, [onClose]);
    return (
        <div
            className="bmenu hb-pop"
            role="dialog"
            aria-label={label}
            ref={box}
            style={{ width, left: Math.max(8, Math.min(x, window.innerWidth - width - 8)), top: Math.max(8, Math.min(y, window.innerHeight - 240)) }}
        >
            {children}
        </div>
    );
}

/** Set one day exactly: done / not done / skipped, or how many for a counted habit. */
export function DayEditor({
    habit,
    day,
    x,
    y,
    clock,
    openHref,
    onClose,
}: {
    habit: Pick<HabitToday, 'id' | 'name' | 'target' | 'unit'>;
    day: HabitDay;
    x: number;
    y: number;
    clock: Clock;
    /** From the Planner: a way to the habit itself. */
    openHref?: string;
    onClose: () => void;
}) {
    const [count, setCount] = useState(day.state === 'skipped' ? 0 : day.count);
    const put = (c: number, skipped = false) => {
        router.put(route('habits.day', habit.id), { date: day.date, count: c, skipped }, keep);
        onClose();
    };
    const future = day.state === 'future';
    return (
        <Pop x={x} y={y} label={`${habit.name}, ${dayName(day.date, clock)}`} onClose={onClose}>
            <div className="bmenu-head">
                <b>{habit.name}</b>
                <span>{dayName(day.date, clock, { weekday: 'long', day: 'numeric', month: 'long' })}</span>
            </div>
            {!future && habit.target === 1 && (
                <>
                    <button className="bmenu-item" aria-pressed={day.state === 'done'} onClick={() => put(1)}>
                        <Icon name="check" />
                        Done
                    </button>
                    <button
                        className="bmenu-item"
                        aria-pressed={['missed', 'todo', 'open', 'off', 'before'].includes(day.state)}
                        onClick={() => put(0)}
                    >
                        <Icon name="x" />
                        Not done
                    </button>
                </>
            )}
            {!future && habit.target > 1 && (
                <form
                    className="hb-count"
                    onSubmit={(e) => {
                        e.preventDefault();
                        put(Math.max(0, count));
                    }}
                >
                    <button type="button" className="icon-btn" aria-label="One less" onClick={() => setCount((c) => Math.max(0, c - 1))}>
                        <Icon name="minus" />
                    </button>
                    <input
                        className="input"
                        type="number"
                        min={0}
                        max={10000}
                        value={count}
                        onChange={(e) => setCount(Number(e.target.value))}
                        aria-label={`How many${habit.unit ? ` ${habit.unit}` : ''}`}
                    />
                    <button type="button" className="icon-btn" aria-label="One more" onClick={() => setCount((c) => c + 1)}>
                        <Icon name="plus" />
                    </button>
                    <span className="hb-count-of">
                        of {habit.target}
                        {habit.unit ? ` ${habit.unit}` : ''}
                    </span>
                    <button type="submit" className="btn btn-primary btn-sm">
                        Save
                    </button>
                </form>
            )}
            {day.state === 'skipped' ? (
                <button className="bmenu-item" onClick={() => put(0)}>
                    <Icon name="x" />
                    Don’t skip
                </button>
            ) : (
                <button className="bmenu-item" onClick={() => put(0, true)}>
                    <Icon name="minus" />
                    Skip this day
                </button>
            )}
            {openHref && (
                <Link className="bmenu-item" href={openHref}>
                    <Icon name="arrowUpRight" />
                    Open habit
                </Link>
            )}
        </Pop>
    );
}

/** Skip today or until a date, edit, archive. */
export function HabitMenu({
    habit,
    today,
    x,
    y,
    onEdit,
    onClose,
}: {
    habit: HabitToday;
    today: string;
    x: number;
    y: number;
    onEdit: () => void;
    onClose: () => void;
}) {
    const [until, setUntil] = useState('');
    const max = new Date(Date.parse(`${today}T12:00:00Z`) + 90 * 864e5).toISOString().slice(0, 10);
    const done = (fn: () => void) => () => {
        fn();
        onClose();
    };
    return (
        <Pop x={x} y={y} label={habit.name} onClose={onClose} width={268}>
            <div className="bmenu-head">
                <b>{habit.name}</b>
                <span>{habit.label}</span>
            </div>
            {habit.skippedToday ? (
                <button
                    className="bmenu-item"
                    onClick={done(() => router.put(route('habits.day', habit.id), { date: today, count: 0, skipped: false }, keep))}
                >
                    <Icon name="x" />
                    Don’t skip today
                </button>
            ) : (
                !habit.doneToday && (
                    <button className="bmenu-item" onClick={done(() => router.post(route('habits.skip', habit.id), {}, keep))}>
                        <Icon name="minus" />
                        Skip today
                    </button>
                )
            )}
            <form
                className="hb-until"
                onSubmit={(e) => {
                    e.preventDefault();
                    if (until) done(() => router.post(route('habits.skip', habit.id), { until }, keep))();
                }}
            >
                <label>
                    <span>Skip until</span>
                    <input className="input" type="date" min={today} max={max} value={until} onChange={(e) => setUntil(e.target.value)} />
                </label>
                <button type="submit" className="btn btn-secondary btn-sm" disabled={!until}>
                    Skip
                </button>
            </form>
            <button className="bmenu-item" onClick={done(onEdit)}>
                <Icon name="sliders" />
                Edit
            </button>
            <button className="bmenu-item" onClick={done(() => router.post(route('habits.archive', habit.id), {}, keep))}>
                <Icon name="archive" />
                Archive
            </button>
        </Pop>
    );
}

/** New habit, or change one: name, how often, how much a day, an optional time on the Planner, project. */
export function HabitDialog({
    habitId,
    initial,
    projects,
    onClose,
}: {
    habitId?: number;
    initial?: Partial<HabitForm>;
    projects: { id: number; name: string }[];
    onClose: () => void;
}) {
    const form = useForm({
        name: initial?.name ?? '',
        detail: initial?.detail ?? '',
        schedule: initial?.schedule ?? 'daily',
        days: initial?.days?.length ? initial.days : [1, 3, 5],
        per_week: String(initial?.per_week ?? 3),
        target: String(initial?.target ?? 1),
        unit: initial?.unit ?? '',
        at_time: initial?.at_time ?? '',
        minutes: String(initial?.minutes ?? 30),
        project_id: initial?.project_id ? String(initial.project_id) : '',
    });
    const { data, setData, processing } = form;
    const errors = form.errors as Record<string, string | undefined>;
    const counted = Number(data.target) > 1;
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            name: d.name,
            detail: d.detail || null,
            schedule: d.schedule,
            days: d.schedule === 'days' ? d.days : [],
            per_week: d.schedule === 'weekly' ? Number(d.per_week) : null,
            target: Number(d.target) || 1,
            unit: Number(d.target) > 1 ? d.unit || null : null,
            at_time: d.schedule !== 'weekly' && d.at_time ? d.at_time : null,
            minutes: d.schedule !== 'weekly' && d.at_time ? Number(d.minutes) : null,
            project_id: d.project_id ? Number(d.project_id) : null,
        }));
        const opts = { ...keep, onSuccess: onClose };
        if (habitId) form.patch(route('habits.update', habitId), opts);
        else form.post(route('habits.store'), opts);
    };
    const toggleDay = (d: number) => setData('days', data.days.includes(d) ? data.days.filter((x) => x !== d) : [...data.days, d].sort());

    return (
        <Modal title={habitId ? 'Edit habit' : 'New habit'} onClose={onClose}>
            <form className="vform" onSubmit={submit}>
                <TextField
                    id="h-name"
                    label="Name"
                    value={data.name}
                    onValue={(v) => setData('name', v)}
                    error={errors.name}
                    required
                    maxLength={120}
                />
                <Field id="h-often" label="How often" error={errors.days || errors.per_week || errors.schedule}>
                    <div className="seg hb-seg" role="radiogroup" aria-label="How often" id="h-often">
                        {(
                            [
                                ['daily', 'Every day'],
                                ['days', 'Some days'],
                                ['weekly', 'Times a week'],
                            ] as const
                        ).map(([k, label]) => (
                            <button key={k} type="button" role="radio" aria-checked={data.schedule === k} onClick={() => setData('schedule', k)}>
                                {label}
                            </button>
                        ))}
                    </div>
                    {data.schedule === 'days' && (
                        <div className="hb-days" role="group" aria-label="Which days">
                            {WEEKDAYS.map((w, i) => (
                                <button key={w} type="button" aria-pressed={data.days.includes(i + 1)} onClick={() => toggleDay(i + 1)}>
                                    {w}
                                </button>
                            ))}
                        </div>
                    )}
                    {data.schedule === 'weekly' && (
                        <div className="hb-inline">
                            <select
                                className="input"
                                value={data.per_week}
                                onChange={(e) => setData('per_week', e.target.value)}
                                aria-label="Times a week"
                            >
                                {[1, 2, 3, 4, 5, 6, 7].map((n) => (
                                    <option key={n} value={n}>
                                        {n}
                                    </option>
                                ))}
                            </select>
                            <span>{data.per_week === '1' ? 'time a week, any day' : 'times a week, any days'}</span>
                        </div>
                    )}
                </Field>
                <div className="vform-row">
                    <Field id="h-target" label="How much a day" error={errors.target}>
                        <input
                            id="h-target"
                            className="input"
                            type="number"
                            min={1}
                            max={1000}
                            value={data.target}
                            onChange={(e) => setData('target', e.target.value)}
                        />
                    </Field>
                    {counted ? (
                        <TextField
                            id="h-unit"
                            label="Of what"
                            value={data.unit}
                            onValue={(v) => setData('unit', v)}
                            error={errors.unit}
                            placeholder="glasses, pages, min"
                            maxLength={24}
                        />
                    ) : (
                        <span />
                    )}
                </div>
                {data.schedule !== 'weekly' && (
                    <div className="vform-row">
                        <Field id="h-time" label="Time on the Planner" error={errors.at_time}>
                            <input
                                id="h-time"
                                className="input"
                                type="time"
                                value={data.at_time}
                                onChange={(e) => setData('at_time', e.target.value)}
                            />
                        </Field>
                        {data.at_time ? (
                            <Field id="h-min" label="For (minutes)" error={errors.minutes}>
                                <input
                                    id="h-min"
                                    className="input"
                                    type="number"
                                    min={5}
                                    max={240}
                                    step={5}
                                    value={data.minutes}
                                    onChange={(e) => setData('minutes', e.target.value)}
                                />
                            </Field>
                        ) : (
                            <span />
                        )}
                    </div>
                )}
                <div className="vform-row">
                    <TextField
                        id="h-detail"
                        label="Note"
                        value={data.detail}
                        onValue={(v) => setData('detail', v)}
                        error={errors.detail}
                        placeholder="Before bed, 25 min…"
                        maxLength={120}
                    />
                    <Field id="h-project" label="Project" error={errors.project_id}>
                        <select id="h-project" className="input" value={data.project_id} onChange={(e) => setData('project_id', e.target.value)}>
                            <option value="">No project</option>
                            {projects.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                </div>
                <div className="vform-foot">
                    <span />
                    <span className="vform-foot-right">
                        <button type="button" className="btn btn-secondary" onClick={onClose}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={processing}>
                            {habitId ? 'Save' : 'Add'}
                        </button>
                    </span>
                </div>
            </form>
        </Modal>
    );
}
