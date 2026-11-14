import { Field, TextField } from '@/components/velocity/field';
import { Modal } from '@/components/velocity/forms';
import {
    DayEditor,
    DayMark,
    dayName,
    formOf,
    HabitDialog,
    type HabitForm,
    HabitMenu,
    type HabitRow,
    habitSub,
    keep,
    Streak,
} from '@/components/velocity/habit-parts';
import { Icon } from '@/components/velocity/icon';
import { HabitButton } from '@/components/velocity/ui';
import VelocityShell from '@/layouts/velocity-shell';
import { Clock } from '@/lib/velocity';
import type { HabitDay, HabitToday, VelocityShared } from '@/types/velocity';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { type FormEvent, useEffect, useRef, useState } from 'react';

type HabitDetail = HabitToday & {
    best: number;
    rate: { done: number; of: number; unit: 'days' | 'weeks' };
    total: number;
    history: HabitDay[];
    since: string;
    form: HabitForm;
    routines: { id: number; name: string }[];
};

interface Props extends VelocityShared {
    view: string;
    today: string;
    week: { start: string; isThisWeek: boolean; days: string[] };
    habits: HabitRow[];
    routine: { id: number; name: string; habitIds: number[] } | null;
    habit: HabitDetail | null;
    menu: { today: number; all: number; archived: number; routines: { id: number; name: string; done: number; of: number }[] };
    choices: { id: number; name: string }[];
    projects: { id: number; name: string }[];
}

type Spot = { x: number; y: number };
const below = (el: HTMLElement): Spot => {
    const r = el.getBoundingClientRect();
    return { x: r.left, y: r.bottom + 6 };
};
const shiftWeek = (d: string, n: number) => new Date(Date.parse(`${d}T12:00:00Z`) + n * 7 * 864e5).toISOString().slice(0, 10);

export default function Habits() {
    const { props } = usePage<Props>();
    const clock = Clock.from(props.clock);
    const view = props.view;
    const [dialog, setDialog] = useState<null | { id?: number; form?: Partial<HabitForm> }>(null);
    const [routineDialog, setRoutineDialog] = useState<null | 'new' | 'edit'>(null);
    const [menu, setMenu] = useState<null | ({ habit: HabitToday } & Spot)>(null);
    const [cell, setCell] = useState<null | ({ habit: HabitToday; day: HabitDay } & Spot)>(null);

    const url = (extra: Record<string, string | number | undefined> = {}) =>
        route('habits.index', {
            view: view === 'today' ? undefined : view,
            week: view === 'week' && !props.week.isThisWeek ? props.week.start : undefined,
            ...extra,
        });
    const open = (id: number | null) => router.get(url({ habit: id ?? undefined }), {}, keep);
    const newHabit = () => setDialog({ form: {} });
    useEffect(() => {
        const url = new URL(window.location.href);
        if (url.searchParams.get('new') === '1') {
            setDialog({ form: {} });
            url.searchParams.delete('new');
            window.history.replaceState(window.history.state, '', url);
        }
    }, []);
    const edit = (h: HabitToday) => setDialog({ id: h.id, form: formOf(h) });
    const rowProps = {
        clock,
        activeId: props.habit?.id,
        onOpen: open,
        onMenu: (habit: HabitToday, el: HTMLElement) => setMenu({ habit, ...below(el) }),
        onCell: (habit: HabitToday, day: HabitDay, el: HTMLElement) => setCell({ habit, day, ...below(el) }),
    };

    let content;
    if (view === 'week') content = <WeekView props={props} clock={clock} rowProps={rowProps} onNew={newHabit} />;
    else if (view === 'archived') content = <ArchivedView habits={props.habits} onOpen={open} />;
    else if (props.routine) content = <RoutineView props={props} rowProps={rowProps} onEdit={() => setRoutineDialog('edit')} />;
    else content = <ListView props={props} clock={clock} rowProps={rowProps} onNew={newHabit} all={view === 'all'} />;

    return (
        <VelocityShell
            title={
                props.routine?.name ??
                (view === 'week' ? 'This week' : view === 'all' ? 'All habits' : view === 'archived' ? 'Archived habits' : 'Habits')
            }
            section="habits"
            active={view}
            menu={({ autoFocus }) => (
                <HabitsMenu props={props} autoFocus={autoFocus} onNewHabit={newHabit} onNewRoutine={() => setRoutineDialog('new')} />
            )}
            panel={
                props.habit ? (
                    <HabitPanel
                        key={props.habit.id}
                        h={props.habit}
                        clock={clock}
                        onClose={() => open(null)}
                        onEdit={() => props.habit && setDialog({ id: props.habit.id, form: props.habit.form })}
                        onCell={rowProps.onCell}
                    />
                ) : undefined
            }
            onEscape={() => (menu ? setMenu(null) : cell ? setCell(null) : props.habit ? open(null) : undefined)}
        >
            <div className="main-inner hb-page">{content}</div>
            {dialog && <HabitDialog habitId={dialog.id} initial={dialog.form} projects={props.projects} onClose={() => setDialog(null)} />}
            {routineDialog && (
                <RoutineDialog
                    routine={routineDialog === 'edit' ? props.routine : null}
                    choices={props.choices}
                    onClose={() => setRoutineDialog(null)}
                />
            )}
            {menu && (
                <HabitMenu
                    habit={menu.habit}
                    today={props.today}
                    x={menu.x}
                    y={menu.y}
                    onEdit={() => edit(menu.habit)}
                    onClose={() => setMenu(null)}
                />
            )}
            {cell && <DayEditor habit={cell.habit} day={cell.day} x={cell.x} y={cell.y} clock={clock} onClose={() => setCell(null)} />}
        </VelocityShell>
    );
}

interface RowProps {
    clock: Clock;
    activeId?: number;
    onOpen: (id: number) => void;
    onMenu: (h: HabitToday, el: HTMLElement) => void;
    onCell: (h: HabitToday, d: HabitDay, el: HTMLElement) => void;
}

/** One habit: tick, name and progress, the last 7 days, streak, more. */
function Row({ h, step, ...p }: RowProps & { h: HabitRow; step?: number }) {
    return (
        <div className={`hb-row${p.activeId === h.id ? ' is-active' : ''}`}>
            {step != null && <span className="hb-step">{step}</span>}
            <HabitButton habit={h} />
            <button className="hb-main" onClick={() => p.onOpen(h.id)}>
                <span className={`hb-name${h.doneToday ? ' is-done' : ''}`}>{h.name}</span>
                <span className="hb-sub">{habitSub(h)}</span>
            </button>
            <span className="hb-dots" role="group" aria-label={`${h.name}, the last 7 days`}>
                {h.days.map((d) => (
                    <DayMark key={d.date} day={d} target={h.target} clock={p.clock} onClick={(el) => p.onCell(h, d, el)} />
                ))}
            </span>
            <Streak habit={h} />
            <button className="icon-btn" aria-label={`More for ${h.name}`} onClick={(e) => p.onMenu(h, e.currentTarget)}>
                <Icon name="more" />
            </button>
        </div>
    );
}

function Group({ title, rows, rowProps, steps }: { title: string; rows: HabitRow[]; rowProps: RowProps; steps?: Map<number, number> }) {
    if (rows.length === 0) return null;
    return (
        <div className="hb-group">
            <h2 className="hb-group-title">
                {title}
                <span>{rows.length}</span>
            </h2>
            {rows.map((h) => (
                <Row key={h.id} h={h} step={steps?.get(h.id)} {...rowProps} />
            ))}
        </div>
    );
}

/** Today (grouped: to do, done, skipped, not today) or All habits (one list). */
function ListView({ props, clock, rowProps, onNew, all }: { props: Props; clock: Clock; rowProps: RowProps; onNew: () => void; all: boolean }) {
    const by = (g: HabitToday['group']) => props.habits.filter((h) => h.group === g);
    const todo = by('todo');
    return (
        <>
            <header className="page-head">
                <div>
                    <h1 className="h1">
                        {all ? 'All habits' : 'Today'} <span className="count">{all ? props.habits.length : `${todo.length} to do`}</span>
                    </h1>
                    {!all && <p className="page-sub">{clock.today()}</p>}
                </div>
                <div className="page-actions">
                    <button className="btn btn-primary" onClick={onNew}>
                        <Icon name="plus" />
                        New habit
                    </button>
                </div>
            </header>
            {props.habits.length === 0 ? (
                <section className="card hb-empty">
                    <Icon name="habits" />
                    <p>No habits yet.</p>
                    <button className="btn btn-secondary" onClick={onNew}>
                        New habit
                    </button>
                </section>
            ) : all ? (
                <section className="card hb-card">
                    {props.habits.map((h) => (
                        <Row key={h.id} h={h} {...rowProps} />
                    ))}
                </section>
            ) : (
                <section className="card hb-card">
                    <Group title="To do" rows={todo} rowProps={rowProps} />
                    <Group title="Done" rows={by('done')} rowProps={rowProps} />
                    <Group title="Skipped" rows={by('skipped')} rowProps={rowProps} />
                    {by('off').length > 0 && (
                        <details className="hb-off">
                            <summary>
                                Not today <span>{by('off').length}</span>
                            </summary>
                            {by('off').map((h) => (
                                <Row key={h.id} h={h} {...rowProps} />
                            ))}
                        </details>
                    )}
                </section>
            )}
        </>
    );
}

/** A week at a time: every habit's seven days; click a day to fix it. */
function WeekView({ props, clock, rowProps, onNew }: { props: Props; clock: Clock; rowProps: RowProps; onNew: () => void }) {
    const { week } = props;
    const go = (start?: string) => router.get(route('habits.index', { view: 'week', week: start }), {}, keep);
    const last = week.days[6];
    return (
        <>
            <header className="page-head">
                <div>
                    <h1 className="h1">
                        {week.isThisWeek ? 'This week' : `Week of ${dayName(week.start, clock, { day: 'numeric', month: 'short' })}`}
                    </h1>
                    <p className="page-sub">
                        {dayName(week.start, clock, { day: 'numeric', month: 'short' })} –{' '}
                        {dayName(last, clock, { day: 'numeric', month: 'short', year: 'numeric' })}
                    </p>
                </div>
                <div className="page-actions">
                    <button className="icon-btn" aria-label="Previous week" onClick={() => go(shiftWeek(week.start, -1))}>
                        <Icon name="chevL" />
                    </button>
                    {!week.isThisWeek && (
                        <button className="btn btn-secondary btn-sm" onClick={() => go()}>
                            This week
                        </button>
                    )}
                    <button className="icon-btn" aria-label="Next week" disabled={week.isThisWeek} onClick={() => go(shiftWeek(week.start, 1))}>
                        <Icon name="chevR" />
                    </button>
                    <button className="btn btn-primary" onClick={onNew}>
                        <Icon name="plus" />
                        New habit
                    </button>
                </div>
            </header>
            {props.habits.length === 0 ? (
                <section className="card hb-empty">
                    <p>No habits yet.</p>
                </section>
            ) : (
                <div className="card hb-week-wrap">
                    <table className="hb-week">
                        <thead>
                            <tr>
                                <th scope="col" className="hb-week-name">
                                    Habit
                                </th>
                                {week.days.map((d) => (
                                    <th key={d} scope="col" className={d === props.today ? 'is-today' : undefined}>
                                        {dayName(d, clock, { weekday: 'short' })}
                                        <small>{Number(d.slice(8))}</small>
                                    </th>
                                ))}
                                <th scope="col">
                                    <span className="sr-only">Streak</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {props.habits.map((h) => (
                                <tr key={h.id} className={rowProps.activeId === h.id ? 'is-active' : undefined}>
                                    <th scope="row" className="hb-week-name">
                                        <button onClick={() => rowProps.onOpen(h.id)}>
                                            <b>{h.name}</b>
                                            <small>{h.label}</small>
                                        </button>
                                    </th>
                                    {h.days.map((d) => (
                                        <td key={d.date}>
                                            <DayMark day={d} target={h.target} clock={clock} big onClick={(el) => rowProps.onCell(h, d, el)} />
                                        </td>
                                    ))}
                                    <td>
                                        <Streak habit={h} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            <Legend />
        </>
    );
}

function Legend() {
    const marks: [HabitDay['state'], string][] = [
        ['done', 'Done'],
        ['partial', 'Part done'],
        ['skipped', 'Skipped'],
        ['missed', 'Missed'],
        ['off', 'Not on the schedule'],
    ];
    return (
        <ul className="hb-legend" aria-label="Key">
            {marks.map(([s, label]) => (
                <li key={s}>
                    <span className={`hd hd-${s}`}>
                        {s === 'done' ? <Icon name="check" /> : s === 'skipped' ? <Icon name="minus" /> : s === 'partial' ? <span>2</span> : null}
                    </span>
                    {label}
                </li>
            ))}
        </ul>
    );
}

function RoutineView({ props, rowProps, onEdit }: { props: Props; rowProps: RowProps; onEdit: () => void }) {
    const routine = props.routine!;
    const steps = new Map(routine.habitIds.map((id, i) => [id, i + 1]));
    const now = props.habits.filter((h) => h.group !== 'off');
    const later = props.habits.filter((h) => h.group === 'off');
    const done = now.filter((h) => h.group === 'done').length;
    return (
        <>
            <header className="page-head">
                <div>
                    <h1 className="h1">
                        {routine.name} <span className="count">{now.length ? `${done} of ${now.length} today` : 'Nothing today'}</span>
                    </h1>
                </div>
                <div className="page-actions">
                    <button className="btn btn-secondary" onClick={onEdit}>
                        <Icon name="sliders" />
                        Edit
                    </button>
                    <button
                        className="icon-btn"
                        aria-label={`Delete “${routine.name}”`}
                        title="Delete"
                        onClick={() => router.delete(route('routines.destroy', routine.id), keep)}
                    >
                        <Icon name="trash" />
                    </button>
                </div>
            </header>
            {props.habits.length === 0 ? (
                <section className="card hb-empty">
                    <p>No habits in this routine.</p>
                    <button className="btn btn-secondary" onClick={onEdit}>
                        Add habits
                    </button>
                </section>
            ) : (
                <section className="card hb-card">
                    <Group title="Today" rows={now} rowProps={rowProps} steps={steps} />
                    <Group title="Not today" rows={later} rowProps={rowProps} steps={steps} />
                </section>
            )}
        </>
    );
}

function ArchivedView({ habits, onOpen }: { habits: HabitRow[]; onOpen: (id: number) => void }) {
    return (
        <>
            <header className="page-head">
                <h1 className="h1">
                    Archived <span className="count">{habits.length}</span>
                </h1>
            </header>
            <section className="card hb-card">
                {habits.length === 0 && <p className="card-empty hb-none">Nothing archived.</p>}
                {habits.map((h) => (
                    <div key={h.id} className="hb-row is-archived">
                        <button className="hb-main" onClick={() => onOpen(h.id)}>
                            <span className="hb-name">{h.name}</span>
                            <span className="hb-sub">{h.label}</span>
                        </button>
                        <button className="btn btn-secondary btn-sm" onClick={() => router.post(route('habits.unarchive', h.id), {}, keep)}>
                            Restore
                        </button>
                        <button
                            className="icon-btn"
                            aria-label={`Delete “${h.name}”`}
                            onClick={() => router.delete(route('habits.destroy', h.id), keep)}
                        >
                            <Icon name="trash" />
                        </button>
                    </div>
                ))}
            </section>
        </>
    );
}

/** The habit's panel: today, streaks, how often it got done, sixteen weeks of days. */
function HabitPanel({
    h,
    clock,
    onClose,
    onEdit,
    onCell,
}: {
    h: HabitDetail;
    clock: Clock;
    onClose: () => void;
    onEdit: () => void;
    onCell: (h: HabitToday, d: HabitDay, el: HTMLElement) => void;
}) {
    const unit = (n: number) => (h.streakUnit === 'week' ? (n === 1 ? 'week' : 'weeks') : n === 1 ? 'day' : 'days');
    return (
        <>
            <div className="panel-head">
                <div className="crumbs">
                    <span>{h.project?.name ?? 'Habits'}</span>
                </div>
                <div className="panel-tools">
                    <button className="icon-btn" aria-label="Edit habit" title="Edit" onClick={onEdit}>
                        <Icon name="sliders" />
                    </button>
                    <button
                        className="icon-btn"
                        aria-label={h.archived ? 'Restore habit' : 'Archive habit'}
                        title={h.archived ? 'Restore' : 'Archive'}
                        onClick={() => router.post(route(h.archived ? 'habits.unarchive' : 'habits.archive', h.id), {}, keep)}
                    >
                        <Icon name="archive" />
                    </button>
                    <button
                        className="icon-btn"
                        aria-label="Delete habit"
                        title="Delete"
                        onClick={() => router.delete(route('habits.destroy', h.id), keep)}
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
                    {!h.archived && <HabitButton habit={h} />}
                    <h2 className="p-title">{h.name}</h2>
                </div>
                <p className="hb-p-sub">{habitSub(h)}</p>
                <dl className="hb-stats">
                    <div>
                        <dt>Streak</dt>
                        <dd>
                            {h.streak} <small>{unit(h.streak)}</small>
                        </dd>
                    </div>
                    <div>
                        <dt>Best</dt>
                        <dd>
                            {h.best} <small>{unit(h.best)}</small>
                        </dd>
                    </div>
                    <div>
                        <dt>{h.rate.unit === 'weeks' ? 'Last 8 weeks' : 'Last 30 days'}</dt>
                        <dd>
                            {h.rate.done}
                            <small> of {h.rate.of}</small>
                        </dd>
                    </div>
                    <div>
                        <dt>Times done</dt>
                        <dd>{h.total}</dd>
                    </div>
                </dl>
                <section className="p-section">
                    <div className="p-section-head">
                        <h3 className="p-section-title">Last 16 weeks</h3>
                    </div>
                    <div className="hb-cal" role="group" aria-label="Last 16 weeks, Monday first">
                        {h.history.map((d) => (
                            <DayMark key={d.date} day={d} target={h.target} clock={clock} onClick={(el) => onCell(h, d, el)} />
                        ))}
                    </div>
                    <div className="hb-cal-foot">
                        <span>{dayName(h.history[0].date, clock, { day: 'numeric', month: 'short' })}</span>
                        <span>Today</span>
                    </div>
                </section>
                {h.routines.length > 0 && (
                    <section className="p-section">
                        <div className="p-section-head">
                            <h3 className="p-section-title">Routines</h3>
                        </div>
                        <div className="p-linked">
                            {h.routines.map((r) => (
                                <Link key={r.id} className="p-linked-note" href={route('habits.index', { view: `r${r.id}` })}>
                                    <Icon name="list" className="ic-sm" />
                                    <span>{r.name}</span>
                                </Link>
                            ))}
                        </div>
                    </section>
                )}
                {h.project && (
                    <section className="p-section">
                        <Link className="card-link" href={`/projects/${h.project.id}`}>
                            Open {h.project.name}
                            <Icon name="arrowR" className="ic-xs" />
                        </Link>
                    </section>
                )}
            </div>
        </>
    );
}

/** A routine: its name and its habits in order (drag, or Up / Down). */
function RoutineDialog({ routine, choices, onClose }: { routine: Props['routine']; choices: Props['choices']; onClose: () => void }) {
    const form = useForm({ name: routine?.name ?? '', habit_ids: routine?.habitIds ?? [] });
    const { data, setData, processing } = form;
    const errors = form.errors as Record<string, string | undefined>;
    const dragFrom = useRef<number | null>(null);
    const name = (id: number) => choices.find((c) => c.id === id)?.name ?? 'Habit';
    const ids = data.habit_ids.filter((id) => choices.some((c) => c.id === id));
    const move = (from: number, to: number) => {
        if (to < 0 || to >= ids.length || from === to) return;
        const next = [...ids];
        const [x] = next.splice(from, 1);
        next.splice(to, 0, x);
        setData('habit_ids', next);
    };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, habit_ids: ids }));
        if (routine) form.patch(route('routines.update', routine.id), { ...keep, onSuccess: onClose });
        else form.post(route('routines.store'), { onSuccess: onClose });
    };
    return (
        <Modal title={routine ? 'Edit routine' : 'New routine'} onClose={onClose}>
            <form className="vform" onSubmit={submit}>
                <TextField
                    id="r-name"
                    label="Name"
                    value={data.name}
                    onValue={(v) => setData('name', v)}
                    error={errors.name}
                    required
                    maxLength={80}
                    placeholder="Morning"
                />
                <Field id="r-add" label="Habits, in order" error={errors.habit_ids}>
                    {ids.length > 0 && (
                        <ol className="rt-list">
                            {ids.map((id, i) => (
                                <li
                                    key={id}
                                    draggable
                                    onDragStart={() => (dragFrom.current = i)}
                                    onDragOver={(e) => e.preventDefault()}
                                    onDrop={(e) => {
                                        e.preventDefault();
                                        if (dragFrom.current !== null) move(dragFrom.current, i);
                                        dragFrom.current = null;
                                    }}
                                >
                                    <span className="rt-num">{i + 1}</span>
                                    <span className="rt-name">{name(id)}</span>
                                    <button
                                        type="button"
                                        className="icon-btn"
                                        aria-label={`Move “${name(id)}” up`}
                                        disabled={i === 0}
                                        onClick={() => move(i, i - 1)}
                                    >
                                        <Icon name="chevU" className="ic-sm" />
                                    </button>
                                    <button
                                        type="button"
                                        className="icon-btn"
                                        aria-label={`Move “${name(id)}” down`}
                                        disabled={i === ids.length - 1}
                                        onClick={() => move(i, i + 1)}
                                    >
                                        <Icon name="chevD" className="ic-sm" />
                                    </button>
                                    <button
                                        type="button"
                                        className="icon-btn"
                                        aria-label={`Take out “${name(id)}”`}
                                        onClick={() =>
                                            setData(
                                                'habit_ids',
                                                ids.filter((x) => x !== id),
                                            )
                                        }
                                    >
                                        <Icon name="x" className="ic-sm" />
                                    </button>
                                </li>
                            ))}
                        </ol>
                    )}
                    <select
                        id="r-add"
                        className="input"
                        value=""
                        onChange={(e) => e.target.value && setData('habit_ids', [...ids, Number(e.target.value)])}
                    >
                        <option value="">Add a habit…</option>
                        {choices
                            .filter((c) => !ids.includes(c.id))
                            .map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                    </select>
                </Field>
                <div className="vform-foot">
                    <span />
                    <span className="vform-foot-right">
                        <button type="button" className="btn btn-secondary" onClick={onClose}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={processing}>
                            {routine ? 'Save' : 'Add'}
                        </button>
                    </span>
                </div>
            </form>
        </Modal>
    );
}

function HabitsMenu({
    props,
    autoFocus,
    onNewHabit,
    onNewRoutine,
}: {
    props: Props;
    autoFocus: boolean;
    onNewHabit: () => void;
    onNewRoutine: () => void;
}) {
    const first = useRef<HTMLButtonElement>(null);
    useEffect(() => {
        if (autoFocus) first.current?.focus();
    }, [autoFocus]);
    const m = props.menu;
    const item = (key: string, icon: string, label: string, count?: number | string) => (
        <Link
            key={key}
            className={`nav-item${props.view === key ? ' is-active' : ''}`}
            href={route('habits.index', key === 'today' ? {} : { view: key })}
            aria-current={props.view === key ? 'page' : undefined}
        >
            <Icon name={icon} />
            <span className="label">{label}</span>
            {count != null && count !== 0 && <span className="count">{count}</span>}
        </Link>
    );
    return (
        <>
            <div className="side-head">
                <span className="side-title">Habits</span>
                <button ref={first} className="icon-btn" onClick={onNewHabit} aria-label="New habit" title="New habit">
                    <Icon name="plus" />
                </button>
            </div>
            <nav className="nav" aria-label="Habits">
                {item('today', 'check', 'Today', m.today)}
                {item('week', 'columns', 'This week')}
                {item('all', 'habits', 'All habits', m.all)}
                <div className="nav-label">
                    Routines
                    <button className="icon-btn nav-label-btn" onClick={onNewRoutine} aria-label="New routine" title="New routine">
                        <Icon name="plus" className="ic-sm" />
                    </button>
                </div>
                {m.routines.map((r) => item(`r${r.id}`, 'list', r.name, r.of ? `${r.done}/${r.of}` : undefined))}
                {m.routines.length === 0 && (
                    <button type="button" className="nav-item" onClick={onNewRoutine}>
                        <Icon name="plus" />
                        <span className="label">New routine</span>
                    </button>
                )}
                <div className="nav-label">More</div>
                {item('archived', 'archive', 'Archived', m.archived)}
            </nav>
        </>
    );
}
