import { BarChart, SplitBars } from '@/components/velocity/charts';
import { DayMark } from '@/components/velocity/habit-parts';
import { Icon } from '@/components/velocity/icon';
import VelocityShell from '@/layouts/velocity-shell';
import { Clock, fmt1 } from '@/lib/velocity';
import type { HabitDay, VelocityShared } from '@/types/velocity';
import { Link, router, usePage } from '@inertiajs/react';
import { type ReactNode, useEffect, useRef } from 'react';

interface Numbers {
    tasks: number;
    onTime: number | null;
    avgScore: number | null;
    focus: number;
    booked: number;
    habitDays: { done: number; of: number };
    catchUps: number;
    people: number;
    journal: number;
    notes: number;
}

interface Report {
    kind: 'week' | 'month';
    start: string;
    last: string;
    inProgress: boolean;
    isCurrent: boolean;
    prev: { start: string; last: string };
    now: Numbers;
    before: Numbers;
    days: { date: string; future: boolean; done: number; focus: number }[];
    byProject: { id: number | null; name: string; color: number; minutes: number }[];
    finished: {
        id: number;
        title: string;
        project: string | null;
        completedAt: string;
        dueAt: string | null;
        onTime: boolean | null;
        score: number | null;
    }[];
    estimates: { id: number; title: string; guess: number; focused: number }[];
    habits: { id: number; name: string; weekly: boolean; perWeek: number | null; done: number; of: number; marks: HabitDay[] }[];
    highlights: string[];
}

interface Weekly {
    week: string;
    nextWeek: string;
    doneAt: string | null;
    inbox: number;
    overdue: number;
    waiting: { id: number; title: string; waitingFor: string | null; since: string | null }[];
    deadlines: { id: number; title: string; dueAt: string; rank: number | null }[];
    habits: { id: number; name: string; missed: number; skipped: number; done: number }[];
    catchUps: { personId: number; name: string; late: number }[];
    dates: { id: number; personId: number; title: string; next: string; days: number }[];
    booked: number;
}

interface Props extends VelocityShared {
    view: 'week' | 'month' | 'weeks' | 'weekly';
    today: string;
    report: Report | null;
    weeks: { start: string; done: number; onTime: number; withDue: number; focus: number }[];
    weekly: Weekly | null;
}

const keep = { preserveScroll: true, preserveState: true };
const at = (d: string) => new Date(`${d}T12:00:00Z`);
const day = (d: string, clock: Clock, opts: Intl.DateTimeFormatOptions) => clock.date(at(d), { ...opts, timeZone: 'UTC' });
const shift = (d: string, n: number) => new Date(Date.parse(`${d}T12:00:00Z`) + n * 864e5).toISOString().slice(0, 10);
/** 90 → "1 h 30 min" */
const hm = (m: number) => (m >= 60 ? `${Math.floor(m / 60)} h${m % 60 ? ` ${m % 60} min` : ''}` : `${m} min`);

export default function Review() {
    const { props } = usePage<Props>();
    const clock = Clock.from(props.clock);
    const title = { week: 'This week', month: 'This month', weeks: '12 weeks', weekly: 'Weekly review' }[props.view];
    return (
        <VelocityShell
            title={title}
            section="review"
            active={props.view}
            menu={({ autoFocus }) => <ReviewMenu view={props.view} autoFocus={autoFocus} />}
        >
            <div className="main-inner rv-page">
                {props.report && <LookBack r={props.report} clock={clock} />}
                {props.view === 'weeks' && <Weeks weeks={props.weeks} clock={clock} />}
                {props.weekly && <LookAhead w={props.weekly} clock={clock} />}
            </div>
        </VelocityShell>
    );
}

/** "+4 vs last week", "same as last week". */
function compare(now: number, before: number, r: Report, fmt: (n: number) => string = String) {
    const was =
        r.kind === 'week' ? (r.inProgress ? 'the same days last week' : 'last week') : r.inProgress ? 'the same days last month' : 'last month';
    if (now === before) return `Same as ${was}`;
    return `${now > before ? '+' : '−'}${fmt(Math.abs(now - before))} vs ${was}`;
}

function LookBack({ r, clock }: { r: Report; clock: Clock }) {
    const go = (date?: string) => router.get(route('review.index', { view: r.kind, date }), {}, keep);
    const heading =
        r.kind === 'month'
            ? day(r.start, clock, { month: 'long', year: 'numeric' })
            : r.isCurrent
              ? 'This week'
              : `Week of ${day(r.start, clock, { day: 'numeric', month: 'short' })}`;
    const n = r.now;
    const b = r.before;
    const pct = (d: { done: number; of: number }) => (d.of ? Math.round((d.done / d.of) * 100) : null);
    const bars = (pick: (d: Report['days'][number]) => number, what: (v: number) => string) =>
        r.days.map((d) => ({
            key: d.date,
            label: r.kind === 'week' ? day(d.date, clock, { weekday: 'narrow' }) : String(Number(d.date.slice(8))),
            value: pick(d),
            future: d.future,
            now: d.date === clock.localInput(clock.now.toISOString()).slice(0, 10),
            title: `${day(d.date, clock, { weekday: 'short', day: 'numeric', month: 'short' })}: ${d.future ? 'still to come' : what(pick(d))}`,
        }));
    const longer = r.estimates.filter((e) => e.focused > e.guess * 60 + 5).length;

    return (
        <>
            <header className="page-head">
                <div>
                    <h1 className="h1">{heading}</h1>
                    <p className="page-sub">
                        {day(r.start, clock, { day: 'numeric', month: 'short' })} –{' '}
                        {day(r.last, clock, { day: 'numeric', month: 'short', year: 'numeric' })}
                        {r.inProgress && ' · so far'}
                    </p>
                </div>
                <div className="page-actions">
                    <button
                        className="icon-btn"
                        aria-label={r.kind === 'week' ? 'Previous week' : 'Previous month'}
                        onClick={() => go(shift(r.start, -1))}
                    >
                        <Icon name="chevL" />
                    </button>
                    {!r.isCurrent && (
                        <button className="btn btn-secondary btn-sm" onClick={() => go()}>
                            {r.kind === 'week' ? 'This week' : 'This month'}
                        </button>
                    )}
                    <button
                        className="icon-btn"
                        aria-label={r.kind === 'week' ? 'Next week' : 'Next month'}
                        disabled={r.isCurrent}
                        onClick={() => go(shift(r.last, 1))}
                    >
                        <Icon name="chevR" />
                    </button>
                </div>
            </header>

            {r.highlights.length > 0 && (
                <ul className="rv-highlights">
                    {r.highlights.map((h) => (
                        <li key={h}>{h}</li>
                    ))}
                </ul>
            )}

            <section className="rv-tiles" aria-label="In numbers">
                <Tile icon="check" label="Tasks done" value={String(n.tasks)} sub={compare(n.tasks, b.tasks, r)}>
                    {n.onTime !== null && `${n.onTime}% on time`}
                    {n.avgScore !== null && ` · avg. priority ${fmt1(n.avgScore)}`}
                </Tile>
                <Tile icon="focus" label="Focused" value={hm(n.focus)} sub={compare(n.focus, b.focus, r, hm)}>
                    {n.booked > 0 && `${hm(n.booked)} booked on the Planner`}
                </Tile>
                <Tile
                    icon="habits"
                    label="Habits"
                    value={pct(n.habitDays) === null ? '–' : `${pct(n.habitDays)}%`}
                    sub={n.habitDays.of ? `${n.habitDays.done} of ${n.habitDays.of} days done` : 'Nothing due'}
                >
                    {pct(b.habitDays) !== null && pct(n.habitDays) !== null && compare(pct(n.habitDays)!, pct(b.habitDays)!, r, (v) => `${v} points`)}
                </Tile>
                <Tile icon="people" label="In touch" value={String(n.people)} sub={n.people === 1 ? 'person' : 'people'}>
                    {n.catchUps > 0 && `${n.catchUps} ${n.catchUps === 1 ? 'catch-up' : 'catch-ups'}`}
                </Tile>
                <Tile icon="notes" label="Written" value={String(n.notes)} sub={n.notes === 1 ? 'note' : 'notes'}>
                    {n.journal > 0 && `${n.journal} journal ${n.journal === 1 ? 'entry' : 'entries'}`}
                </Tile>
            </section>

            <div className="rv-grid">
                <section className="card rv-card">
                    <h2 className="rv-title">Tasks done each day</h2>
                    <BarChart
                        bars={bars(
                            (d) => d.done,
                            (v) => `${v} done`,
                        )}
                        label={`Tasks done each day: ${r.days
                            .filter((d) => !d.future)
                            .map((d) => `${day(d.date, clock, { weekday: 'short', day: 'numeric' })} ${d.done}`)
                            .join(', ')}`}
                        table={['Day', 'Tasks done']}
                    />
                </section>
                <section className="card rv-card">
                    <h2 className="rv-title">Focus each day</h2>
                    <BarChart
                        bars={bars((d) => d.focus, hm)}
                        label={`Minutes focused each day: ${r.days
                            .filter((d) => !d.future)
                            .map((d) => `${day(d.date, clock, { weekday: 'short', day: 'numeric' })} ${d.focus}`)
                            .join(', ')}`}
                        table={['Day', 'Minutes focused']}
                    />
                </section>
            </div>

            {r.byProject.length > 0 && (
                <section className="card rv-card">
                    <h2 className="rv-title">Where the focus went</h2>
                    <SplitBars
                        unit="Minutes focused by project"
                        rows={r.byProject.map((p) => ({
                            key: String(p.id ?? 0),
                            name: p.name,
                            value: p.minutes,
                            color: `var(--goal-${p.color})`,
                            text: hm(p.minutes),
                        }))}
                    />
                </section>
            )}

            {r.habits.length > 0 && (
                <section className="card rv-card">
                    <h2 className="rv-title">Habits</h2>
                    <ul className="rv-habits">
                        {r.habits.map((h) => (
                            <li key={h.id}>
                                <Link href={`/habits?habit=${h.id}`} className="rv-habit-name">
                                    {h.name}
                                </Link>
                                <span className="rv-habit-count">
                                    {h.weekly
                                        ? `${h.done} ${h.done === 1 ? 'run' : 'runs'} · ${h.perWeek} a week`
                                        : h.of
                                          ? `${h.done} of ${h.of} days`
                                          : 'Nothing due'}
                                </span>
                                <span className={`rv-marks${r.kind === 'month' ? ' is-month' : ''}`}>
                                    {h.marks.map((m) => (
                                        <DayMark key={m.date} day={m} target={1} clock={clock} />
                                    ))}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {r.estimates.length > 0 && (
                <section className="card rv-card">
                    <h2 className="rv-title">First guess and time focused</h2>
                    <p className="rv-note">
                        Longer than guessed on {longer} of {r.estimates.length} finished {r.estimates.length === 1 ? 'task' : 'tasks'}.
                    </p>
                    <table className="rv-table">
                        <thead>
                            <tr>
                                <th scope="col">Task</th>
                                <th scope="col">Guess</th>
                                <th scope="col">Focused</th>
                                <th scope="col">Difference</th>
                            </tr>
                        </thead>
                        <tbody>
                            {r.estimates.map((e) => {
                                const diff = e.focused - Math.round(e.guess * 60);
                                return (
                                    <tr key={e.id}>
                                        <th scope="row">
                                            <Link href={`/tasks?task=${e.id}`}>{e.title}</Link>
                                        </th>
                                        <td>{hm(Math.round(e.guess * 60))}</td>
                                        <td>{hm(e.focused)}</td>
                                        <td>{diff === 0 ? 'Spot on' : `${diff > 0 ? '+' : '−'}${hm(Math.abs(diff))}`}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </section>
            )}

            <section className="card rv-card">
                <h2 className="rv-title">
                    Finished <span className="rv-count">{r.finished.length}</span>
                </h2>
                {r.finished.length === 0 ? (
                    <p className="rv-note">Nothing finished in these days.</p>
                ) : (
                    <ul className="rv-finished">
                        {r.finished.map((t) => (
                            <li key={t.id}>
                                <Icon name="check" className="ic-xs" />
                                <Link href={`/tasks?task=${t.id}`}>{t.title}</Link>
                                <span>
                                    {t.project ? `${t.project} · ` : ''}
                                    {clock.date(t.completedAt, { weekday: 'short', day: 'numeric', month: 'short' })}
                                    {t.onTime === false ? ' · after its due date' : ''}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </>
    );
}

function Tile({ icon, label, value, sub, children }: { icon: string; label: string; value: string; sub: string; children?: ReactNode }) {
    return (
        <div className="rv-tile">
            <span className="rv-tile-label">
                <Icon name={icon} className="ic-sm" />
                {label}
            </span>
            <b>{value}</b>
            <span className="rv-tile-sub">{sub}</span>
            {children && <span className="rv-tile-more">{children}</span>}
        </div>
    );
}

/** The last 12 weeks side by side. */
function Weeks({ weeks, clock }: { weeks: Props['weeks']; clock: Clock }) {
    const done = weeks.reduce((s, w) => s + w.done, 0);
    const withDue = weeks.reduce((s, w) => s + w.withDue, 0);
    const onTime = weeks.reduce((s, w) => s + w.onTime, 0);
    const label = (w: Props['weeks'][number]) => day(w.start, clock, { day: 'numeric', month: 'short' });
    return (
        <>
            <header className="page-head">
                <div>
                    <h1 className="h1">12 weeks</h1>
                    <p className="page-sub">
                        {done} tasks done{withDue ? ` · ${Math.round((onTime / withDue) * 100)}% on time` : ''} ·{' '}
                        {hm(weeks.reduce((s, w) => s + w.focus, 0))} focused
                    </p>
                </div>
            </header>
            <div className="rv-grid">
                <section className="card rv-card">
                    <h2 className="rv-title">Tasks done each week</h2>
                    <BarChart
                        bars={weeks.map((w, i) => ({
                            key: w.start,
                            label: label(w),
                            value: w.done,
                            now: i === weeks.length - 1,
                            title: `Week of ${label(w)}: ${w.done} done${w.withDue ? `, ${w.onTime} of ${w.withDue} on time` : ''}`,
                        }))}
                        label={`Tasks done each week: ${weeks.map((w) => `${label(w)} ${w.done}`).join(', ')}`}
                        table={['Week of', 'Tasks done']}
                    />
                </section>
                <section className="card rv-card">
                    <h2 className="rv-title">Focus each week</h2>
                    <BarChart
                        bars={weeks.map((w, i) => ({
                            key: w.start,
                            label: label(w),
                            value: Math.round((w.focus / 60) * 10) / 10,
                            now: i === weeks.length - 1,
                            title: `Week of ${label(w)}: ${hm(w.focus)}`,
                        }))}
                        label={`Hours focused each week: ${weeks.map((w) => `${label(w)} ${hm(w.focus)}`).join(', ')}`}
                        table={['Week of', 'Hours focused']}
                    />
                </section>
            </div>
            <section className="card rv-card">
                <h2 className="rv-title">Week by week</h2>
                <table className="rv-table">
                    <thead>
                        <tr>
                            <th scope="col">Week of</th>
                            <th scope="col">Done</th>
                            <th scope="col">On time</th>
                            <th scope="col">Focused</th>
                        </tr>
                    </thead>
                    <tbody>
                        {[...weeks].reverse().map((w) => (
                            <tr key={w.start}>
                                <th scope="row">
                                    <Link href={route('review.index', { view: 'week', date: w.start })}>{label(w)}</Link>
                                </th>
                                <td>{w.done}</td>
                                <td>{w.withDue ? `${w.onTime} of ${w.withDue}` : '–'}</td>
                                <td>{hm(w.focus)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </>
    );
}

/** Before the new week: clear, check, plan. */
function LookAhead({ w, clock }: { w: Weekly; clock: Clock }) {
    const row = (icon: string, title: string, sub: string, href?: string, busy = false) => (
        <li className={`rv-step${busy ? ' is-busy' : ''}`}>
            <Icon name={icon} />
            <span>
                <b>{title}</b>
                <small>{sub}</small>
            </span>
            {href && (
                <Link className="btn btn-secondary btn-sm" href={href}>
                    Open
                </Link>
            )}
        </li>
    );
    return (
        <>
            <header className="page-head">
                <div>
                    <h1 className="h1">Weekly review</h1>
                    <p className="page-sub">
                        Before the week of {day(w.nextWeek, clock, { day: 'numeric', month: 'short' })}
                        {w.doneAt && ` · done ${clock.date(w.doneAt, { weekday: 'short', day: 'numeric', month: 'short' })}`}
                    </p>
                </div>
                <div className="page-actions">
                    {w.doneAt ? (
                        <span className="chip">
                            <Icon name="check" />
                            Done this week
                        </span>
                    ) : (
                        <button className="btn btn-primary" onClick={() => router.post(route('review.done'), {}, keep)}>
                            <Icon name="check" />
                            Mark as done
                        </button>
                    )}
                </div>
            </header>

            <section className="card rv-card">
                <h2 className="rv-title">Clear</h2>
                <ul className="rv-steps">
                    {row(
                        'inbox',
                        w.inbox ? `${w.inbox} in the Inbox` : 'Inbox is empty',
                        w.inbox ? 'Not in a project yet' : 'Nothing to sort',
                        w.inbox ? '/tasks/inbox' : undefined,
                        w.inbox > 0,
                    )}
                    {row(
                        'flag',
                        w.overdue ? `${w.overdue} overdue` : 'Nothing overdue',
                        w.overdue ? 'Past their due time' : 'All on time',
                        w.overdue ? '/tasks' : undefined,
                        w.overdue > 0,
                    )}
                </ul>
            </section>

            <section className="card rv-card">
                <h2 className="rv-title">Check</h2>
                <ul className="rv-steps">
                    {row(
                        'hourglass',
                        w.waiting.length ? `${w.waiting.length} waiting` : 'Nothing waiting',
                        w.waiting.length ? 'Oldest first' : 'Nothing on hold',
                        w.waiting.length ? '/tasks/waiting' : undefined,
                        w.waiting.length > 0,
                    )}
                </ul>
                {w.waiting.length > 0 && (
                    <ul className="rv-finished">
                        {w.waiting.map((t) => (
                            <li key={t.id}>
                                <Icon name="hourglass" className="ic-xs" />
                                <Link href={`/tasks?task=${t.id}`}>{t.title}</Link>
                                <span>
                                    {t.waitingFor ?? ''}
                                    {t.since && `${t.waitingFor ? ' · ' : ''}since ${clock.date(t.since, { day: 'numeric', month: 'short' })}`}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                <ul className="rv-steps">
                    {row(
                        'habits',
                        w.habits.length
                            ? `${w.habits.length} ${w.habits.length === 1 ? 'habit' : 'habits'} missed this week`
                            : 'No habits missed this week',
                        w.habits.map((h) => `${h.name} (${h.missed})`).join(', ') || 'All on track',
                        w.habits.length ? '/habits?view=week' : undefined,
                        w.habits.length > 0,
                    )}
                    {row(
                        'people',
                        [
                            w.catchUps.length ? `${w.catchUps.length} to catch up with` : null,
                            w.dates.length ? `${w.dates.length} ${w.dates.length === 1 ? 'date' : 'dates'} coming up` : null,
                        ]
                            .filter(Boolean)
                            .join(' · ') || 'Nobody to catch up with',
                        [...w.catchUps.map((c) => c.name), ...w.dates.map((d) => `${d.title} ${d.days === 0 ? 'today' : `in ${d.days} days`}`)].join(
                            ' · ',
                        ) || 'No dates in the next two weeks',
                        w.catchUps.length || w.dates.length ? '/people?view=attention' : undefined,
                        w.catchUps.length > 0,
                    )}
                </ul>
            </section>

            <section className="card rv-card">
                <h2 className="rv-title">Plan</h2>
                {w.deadlines.length > 0 && (
                    <ul className="rv-finished">
                        {w.deadlines.map((t) => (
                            <li key={t.id}>
                                {t.rank ? <b className="rv-rank">#{t.rank}</b> : <Icon name="flag" className="ic-xs" />}
                                <Link href={`/tasks?task=${t.id}`}>{t.title}</Link>
                                <span>due {clock.date(t.dueAt, { weekday: 'short', day: 'numeric', month: 'short' })}</span>
                            </li>
                        ))}
                    </ul>
                )}
                <ul className="rv-steps">
                    <li className="rv-step">
                        <Icon name="planner" />
                        <span>
                            <b>{w.deadlines.length ? `${w.deadlines.length} due next week` : 'Nothing due next week'}</b>
                            <small>{w.booked ? `${hm(w.booked)} booked so far` : 'Nothing booked yet'}</small>
                        </span>
                        <button
                            className="btn btn-primary btn-sm"
                            onClick={() => router.post(route('planner.plan'), { date: w.nextWeek, span: 'week' }, keep)}
                        >
                            <Icon name="wand" />
                            Plan next week
                        </button>
                    </li>
                </ul>
            </section>
        </>
    );
}

function ReviewMenu({ view, autoFocus }: { view: Props['view']; autoFocus: boolean }) {
    const first = useRef<HTMLAnchorElement>(null);
    useEffect(() => {
        if (autoFocus) first.current?.focus();
    }, [autoFocus]);
    const item = (key: Props['view'], icon: string, label: string, ref?: typeof first) => (
        <Link
            ref={ref}
            className={`nav-item${view === key ? ' is-active' : ''}`}
            href={route('review.index', key === 'week' ? {} : { view: key })}
            aria-current={view === key ? 'page' : undefined}
        >
            <Icon name={icon} />
            <span className="label">{label}</span>
        </Link>
    );
    return (
        <>
            <div className="side-head">
                <span className="side-title">Review</span>
            </div>
            <nav className="nav" aria-label="Review">
                {item('week', 'calendar', 'This week', first)}
                {item('month', 'planner', 'This month')}
                {item('weeks', 'review', '12 weeks')}
                <div className="nav-label">Look ahead</div>
                {item('weekly', 'check', 'Weekly review')}
            </nav>
        </>
    );
}
