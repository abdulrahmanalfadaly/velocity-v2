import { useReturnFocus } from '@/hooks/use-return-focus';
import { type Clock, hm12 } from '@/lib/velocity';
import type { HabitDay, HabitToday, VelocityShared } from '@/types/velocity';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { type FormEvent, type ReactNode, useEffect, useRef, useState } from 'react';
import { DatePick } from './date-pick';
import { Field, TextField } from './field';
import { Modal } from './forms';
import { Icon } from './icon';
import { Select } from './select';

export type HabitRow = HabitToday & { days: HabitDay[] };

export interface HabitForm {
    name: string;
    kind: HabitToday['kind'];
    detail: string | null;
    schedule: 'daily' | 'days' | 'weekly' | 'monthly' | 'every';
    days: number[];
    per_week: number | null;
    per_month: number | null;
    every_days: number | null;
    starts_on: string | null;
    ends_on?: string | null;
    description?: string | null;
    why?: string | null;
    area_id?: number | null;
    goal_ids?: number[];
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
    kind: h.kind ?? 'do',
    detail: h.detail,
    schedule: h.schedule.perWeek
        ? 'weekly'
        : h.schedule.perMonth
          ? 'monthly'
          : h.schedule.everyDays
            ? 'every'
            : h.schedule.days.length
              ? 'days'
              : 'daily',
    days: h.schedule.days,
    per_week: h.schedule.perWeek,
    per_month: h.schedule.perMonth,
    every_days: h.schedule.everyDays,
    starts_on: h.startsOn ?? null,
    ends_on: h.endsOn ?? null,
    target: h.target,
    unit: h.unit,
    at_time: h.time,
    minutes: h.minutes,
    project_id: h.project?.id ?? null,
});

/** The line under a habit's name: progress, schedule, time. */
export function habitSub(h: HabitToday): string {
    const parts: string[] = [];
    if (h.pausedSince)
        parts.push(
            h.pausedUntil
                ? `Paused until ${new Date(`${h.pausedUntil}T12:00:00Z`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' })}`
                : 'Paused',
        );
    if (h.kind === 'avoid') parts.push(h.overToday ? 'It happened today' : 'Kept so far today');
    else if (h.kind === 'limit') parts.push(`${h.today} of at most ${h.target}${h.unit ? ` ${h.unit}` : ''}${h.overToday ? ' · over' : ''}`);
    else if (h.week) parts.push(`${h.week.done} of ${h.week.need} this week`);
    else if (h.month) parts.push(`${h.month.done} of ${h.month.need} this month`);
    else if (h.target > 1 || h.kind === 'minutes') parts.push(`${h.today} of ${h.target}${h.unit ? ` ${h.unit}` : ''}`);
    if (!h.week && !h.month) parts.push(h.label);
    if (h.time) parts.push(hm12(h.time));
    if (h.detail) parts.push(h.detail);
    return parts.join(' · ');
}

export function Streak({ habit }: { habit: HabitToday }) {
    const unit = habit.streakUnit ?? 'day';
    return (
        <span className="streak" title={`${habit.streak}-${unit} streak`} aria-label={`${habit.streak}-${unit} streak`}>
            <Icon name="flame" />
            {habit.streak}
            {unit !== 'day' && <small>{unit === 'week' ? 'w' : 'm'}</small>}
        </span>
    );
}

const STATE_TEXT: Record<HabitDay['state'], string> = {
    done: 'done',
    partial: 'partly done',
    skipped: 'skipped',
    paused: 'paused',
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
    useReturnFocus();
    const box = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const onDown = (e: PointerEvent) => {
            const t = e.target as Element;
            if (!box.current?.contains(t) && !t.closest?.('.dp-pop, .sel-pop')) onClose();
        };
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
    habit: Pick<HabitToday, 'id' | 'name' | 'target' | 'unit'> & { kind?: HabitToday['kind'] };
    day: HabitDay;
    x: number;
    y: number;
    clock: Clock;
    /** From the Planner: a way to the habit itself. */
    openHref?: string;
    onClose: () => void;
}) {
    const [count, setCount] = useState(day.state === 'skipped' ? 0 : day.count);
    const [note, setNote] = useState(day.note ?? '');
    const kind = habit.kind ?? 'do';
    const put = (c: number, skipped = false) => {
        router.put(route('habits.day', habit.id), { date: day.date, count: c, skipped, note: note.trim() || null }, keep);
        onClose();
    };
    const future = day.state === 'future';
    const counted = kind === 'minutes' || kind === 'limit' || (kind === 'do' && habit.target > 1);
    return (
        <Pop x={x} y={y} label={`${habit.name}, ${dayName(day.date, clock)}`} onClose={onClose}>
            <div className="bmenu-head">
                <b>{habit.name}</b>
                <span>{dayName(day.date, clock, { weekday: 'long', day: 'numeric', month: 'long' })}</span>
            </div>
            {!future && kind === 'avoid' && (
                <>
                    <button className="bmenu-item" aria-pressed={day.count === 0 && day.state !== 'skipped'} onClick={() => put(0)}>
                        <Icon name="check" />
                        Kept
                    </button>
                    <button className="bmenu-item" aria-pressed={day.count > 0} onClick={() => put(1)}>
                        <Icon name="x" />
                        It happened
                    </button>
                </>
            )}
            {!future && kind === 'do' && habit.target === 1 && (
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
            {!future && counted && (
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
                        {kind === 'limit' ? 'at most' : 'of'} {habit.target}
                        {kind === 'minutes' ? ' min' : habit.unit ? ` ${habit.unit}` : ''}
                    </span>
                    <button type="submit" className="btn btn-primary btn-sm">
                        Save
                    </button>
                </form>
            )}
            {!future && (
                <form
                    className="hb-note"
                    onSubmit={(e) => {
                        e.preventDefault();
                        put(day.state === 'skipped' ? 0 : day.count, day.state === 'skipped');
                    }}
                >
                    <input
                        className="input"
                        value={note}
                        maxLength={300}
                        placeholder="Note (ran 5 km, knee okay)"
                        aria-label="Note on this day"
                        onChange={(e) => setNote(e.target.value)}
                    />
                    {note.trim() !== (day.note ?? '') && (
                        <button type="submit" className="btn btn-secondary btn-sm">
                            Save
                        </button>
                    )}
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
                <span className="hb-until-field">
                    <span>Skip until</span>
                    <DatePick label="Skip until" min={today} max={max} value={until} onChange={setUntil} clearable={false} placeholder="Pick a day" />
                </span>
                <button type="submit" className="btn btn-secondary btn-sm" disabled={!until}>
                    Skip
                </button>
            </form>
            {habit.pausedSince ? (
                <button className="bmenu-item" onClick={done(() => router.post(route('habits.resume', habit.id), {}, keep))}>
                    <Icon name="play" />
                    Resume
                </button>
            ) : (
                <button className="bmenu-item" onClick={done(() => router.post(route('habits.pause', habit.id), {}, keep))}>
                    <Icon name="pause" />
                    Pause
                </button>
            )}
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
        kind: initial?.kind ?? 'do',
        detail: initial?.detail ?? '',
        schedule: initial?.schedule ?? 'daily',
        days: initial?.days?.length ? initial.days : [1, 3, 5],
        per_week: String(initial?.per_week ?? 3),
        per_month: String(initial?.per_month ?? 8),
        every_days: String(initial?.every_days ?? 3),
        starts_on: initial?.starts_on ?? '',
        ends_on: initial?.ends_on ?? '',
        description: initial?.description ?? '',
        why: initial?.why ?? '',
        area_id: initial?.area_id ? String(initial.area_id) : '',
        goal_ids: initial?.goal_ids ?? [],
        target: String(initial?.target ?? 1),
        unit: initial?.unit ?? '',
        at_time: initial?.at_time ?? '',
        minutes: String(initial?.minutes ?? 30),
        project_id: initial?.project_id ? String(initial.project_id) : '',
    });
    const { data, setData, processing } = form;
    const errors = form.errors as Record<string, string | undefined>;
    const less = data.kind === 'limit' || data.kind === 'avoid';
    const flexible = data.schedule === 'weekly' || data.schedule === 'monthly';
    const counted = data.kind === 'limit' || (data.kind === 'do' && Number(data.target) > 1);
    const timed = !flexible && !less;
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            name: d.name,
            kind: d.kind,
            detail: d.detail || null,
            schedule: d.schedule,
            days: d.schedule === 'days' ? d.days : [],
            per_week: d.schedule === 'weekly' ? Number(d.per_week) : null,
            per_month: d.schedule === 'monthly' ? Number(d.per_month) : null,
            every_days: d.schedule === 'every' ? Number(d.every_days) : null,
            starts_on: d.starts_on || null,
            ends_on: d.ends_on || null,
            // Sent only when the dialog had them (a new habit, or one opened with all its details).
            ...(full
                ? {
                      description: d.description.trim() || null,
                      why: d.why.trim() || null,
                      area_id: d.area_id ? Number(d.area_id) : null,
                      goal_ids: d.goal_ids,
                  }
                : {}),
            target: d.kind === 'avoid' ? 1 : Number(d.target) || 1,
            unit: counted ? d.unit || null : null,
            at_time: timed && d.at_time ? d.at_time : null,
            minutes: timed && d.at_time ? Number(d.minutes) : null,
            project_id: d.project_id ? Number(d.project_id) : null,
        }));
        const opts = { ...keep, onSuccess: onClose };
        if (habitId) form.patch(route('habits.update', habitId), opts);
        else form.post(route('habits.store'), opts);
    };
    const toggleDay = (d: number) => setData('days', data.days.includes(d) ? data.days.filter((x) => x !== d) : [...data.days, d].sort());
    const full = !habitId || initial?.goal_ids !== undefined;
    const nav = usePage<VelocityShared>().props.nav;
    const goals = nav?.goals ?? [];
    const areas = nav?.areas ?? [];

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
                <Field id="h-kind" label="What kind" error={errors.kind}>
                    <div className="seg hb-seg" role="radiogroup" aria-label="What kind" id="h-kind">
                        {(
                            [
                                ['do', 'Do it'],
                                ['minutes', 'Minutes'],
                                ['limit', 'Limit'],
                                ['avoid', 'Avoid'],
                            ] as const
                        ).map(([k, label]) => (
                            <button
                                key={k}
                                type="button"
                                role="radio"
                                aria-checked={data.kind === k}
                                onClick={() =>
                                    setData((d) => ({
                                        ...d,
                                        kind: k,
                                        // A limit or something to avoid is a matter of each day.
                                        schedule:
                                            (k === 'limit' || k === 'avoid') && (d.schedule === 'weekly' || d.schedule === 'monthly')
                                                ? 'daily'
                                                : d.schedule,
                                        target: k === 'minutes' && Number(d.target) < 5 ? '20' : d.target,
                                    }))
                                }
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </Field>
                <Field
                    id="h-often"
                    label="How often"
                    error={errors.days || errors.per_week || errors.per_month || errors.every_days || errors.schedule}
                >
                    <div className="seg hb-seg" role="radiogroup" aria-label="How often" id="h-often">
                        {(
                            [
                                ['daily', 'Every day'],
                                ['days', 'Some days'],
                                ['weekly', 'A week'],
                                ['monthly', 'A month'],
                                ['every', 'Every few days'],
                            ] as const
                        )
                            .filter(([k]) => !less || (k !== 'weekly' && k !== 'monthly'))
                            .map(([k, label]) => (
                                <button key={k} type="button" role="radio" aria-checked={data.schedule === k} onClick={() => setData('schedule', k)}>
                                    {label}
                                </button>
                            ))}
                    </div>
                    {data.schedule === 'monthly' && (
                        <div className="hb-inline">
                            <input
                                className="input hb-per-week"
                                type="number"
                                min={1}
                                max={31}
                                value={data.per_month}
                                aria-label="Times a month"
                                onChange={(e) => setData('per_month', e.target.value)}
                            />
                            <span>{data.per_month === '1' ? 'time a month, any day' : 'times a month, any days'}</span>
                        </div>
                    )}
                    {data.schedule === 'every' && (
                        <div className="hb-inline">
                            <span>Every</span>
                            <input
                                className="input hb-per-week"
                                type="number"
                                min={2}
                                max={365}
                                value={data.every_days}
                                aria-label="Every how many days"
                                onChange={(e) => setData('every_days', e.target.value)}
                            />
                            <span>days, counted from its start</span>
                        </div>
                    )}
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
                            <Select
                                className="input hb-per-week"
                                value={String(data.per_week)}
                                onChange={(v) => setData('per_week', v)}
                                label="Times a week"
                                options={[1, 2, 3, 4, 5, 6, 7].map((n) => ({ value: String(n), label: String(n) }))}
                            />
                            <span>{data.per_week === '1' ? 'time a week, any day' : 'times a week, any days'}</span>
                        </div>
                    )}
                </Field>
                {data.kind !== 'avoid' && (
                    <div className="vform-row">
                        <Field
                            id="h-target"
                            label={data.kind === 'minutes' ? 'Minutes a day' : data.kind === 'limit' ? 'At most a day' : 'How much a day'}
                            error={errors.target}
                        >
                            <input
                                id="h-target"
                                className="input"
                                type="number"
                                min={1}
                                max={data.kind === 'minutes' ? 1440 : 1000}
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
                )}
                {timed && (
                    <div className="vform-row">
                        <Field id="h-time" label="Time on the Planner" error={errors.at_time}>
                            <DatePick
                                id="h-time"
                                mode="time"
                                value={data.at_time}
                                onChange={(v) => setData('at_time', v)}
                                placeholder="Not on the Planner"
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
                    <Field id="h-starts" label="Starts" error={errors.starts_on}>
                        <DatePick
                            id="h-starts"
                            value={data.starts_on}
                            onChange={(v) => setData('starts_on', v)}
                            placeholder={habitId ? 'No start date' : data.schedule === 'every' ? 'Today' : 'Now'}
                        />
                    </Field>
                    <Field id="h-ends" label="Ends" error={errors.ends_on}>
                        <DatePick
                            id="h-ends"
                            value={data.ends_on}
                            onChange={(v) => setData('ends_on', v)}
                            placeholder="No end"
                            min={data.starts_on || undefined}
                        />
                    </Field>
                </div>
                <Field id="h-desc" label="What it is" error={errors.description}>
                    <textarea
                        id="h-desc"
                        className="input vform-text"
                        rows={2}
                        maxLength={2000}
                        value={data.description}
                        placeholder="20 pages of any book, not the course reader"
                        onChange={(e) => setData('description', e.target.value)}
                    />
                </Field>
                <Field id="h-why" label="Why it matters" error={errors.why}>
                    <textarea
                        id="h-why"
                        className="input vform-text"
                        rows={2}
                        maxLength={2000}
                        value={data.why}
                        placeholder="Reading every day keeps my head clear for the FYP"
                        onChange={(e) => setData('why', e.target.value)}
                    />
                </Field>
                {(goals.length > 0 || areas.length > 0) && (
                    <div className="vform-row">
                        <Field id="h-goals" label="Goals it serves" error={errors.goal_ids}>
                            <span className="hb-goal-pick" id="h-goals">
                                {data.goal_ids.map((id) => (
                                    <span key={id} className="note-proj-chip">
                                        <span>{goals.find((g) => g.id === id)?.title ?? 'Goal'}</span>
                                        <button
                                            type="button"
                                            className="note-task-x"
                                            aria-label="Take off this goal"
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
                                ))}
                                {goals.some((g) => !data.goal_ids.includes(g.id)) && (
                                    <Select
                                        className="note-project-sel"
                                        value=""
                                        label="Add a goal"
                                        placeholder={data.goal_ids.length ? '+ Goal' : 'No goal'}
                                        onChange={(v) => v && setData('goal_ids', [...data.goal_ids, Number(v)])}
                                        options={goals
                                            .filter((g) => !data.goal_ids.includes(g.id))
                                            .map((g) => ({ value: String(g.id), label: g.title }))}
                                    />
                                )}
                            </span>
                        </Field>
                        <Field id="h-area" label="Area" error={errors.area_id}>
                            <Select
                                id="h-area"
                                value={data.area_id}
                                onChange={(v) => setData('area_id', v)}
                                options={[{ value: '', label: 'No area' }, ...areas.map((a) => ({ value: String(a.id), label: a.name }))]}
                            />
                        </Field>
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
                        <Select
                            id="h-project"
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
                            {habitId ? 'Save' : 'Add'}
                        </button>
                    </span>
                </div>
            </form>
        </Modal>
    );
}
