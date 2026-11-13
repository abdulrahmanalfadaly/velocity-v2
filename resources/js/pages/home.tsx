import { StartFocus } from '@/components/velocity/focus';
import { Streak } from '@/components/velocity/habit-parts';
import { Icon } from '@/components/velocity/icon';
import type { PlanBlock } from '@/components/velocity/planner-parts';
import { HabitButton } from '@/components/velocity/ui';
import VelocityShell from '@/layouts/velocity-shell';
import { Clock, dueInfo, fmt1, fmtHours } from '@/lib/velocity';
import type { HabitToday, NotReadyTask, RankedTask, VelocityShared } from '@/types/velocity';
import { Link, router, usePage } from '@inertiajs/react';

interface Props extends VelocityShared {
    top: RankedTask[];
    total: number;
    overdue: number;
    dueSoon: number;
    upcoming: RankedTask[];
    plan: { items: PlanItem[]; booked: number; limit: number };
    habits: HabitToday[];
    routines: { id: number; name: string; done: number; of: number }[];
    attention: {
        overdue: RankedTask[];
        notReady: NotReadyTask[];
        milestones: { title: string; project: string; projectId: number; dueDate: string }[];
    };
    week: { days: { day: string; count: number }[]; done: number; onTime: number | null; avgScore: number | null };
}

type PlanItem =
    | {
          kind: 'event';
          key: string;
          title: string;
          calendar: 'class' | 'work' | 'personal';
          allDay: boolean;
          location: string | null;
          start: string;
          end: string;
      }
    | ({ kind: 'block'; key: string } & PlanBlock)
    | { kind: 'habit'; key: string; id: number; title: string; start: string; end: string; done: boolean; count: number; target: number };

export default function Home() {
    const { props } = usePage<Props>();
    const clock = Clock.from(props.clock);
    const first = props.auth.user?.name.split(' ')[0] ?? '';
    const [top, ...next] = props.top;

    return (
        <VelocityShell title="Home" section="home">
            <div className="main-inner">
                <header className="page-head">
                    <div>
                        <p className="eyebrow">{clock.today()}</p>
                        <h1 className="h-display">
                            {clock.greeting()}, {first}
                        </h1>
                        <p className="page-sub">
                            {props.total} tasks
                            {props.overdue > 0 && (
                                <>
                                    {' '}
                                    <span className="dotsep" /> <strong style={{ color: 'var(--critical-ink)' }}>{props.overdue} overdue</strong>
                                </>
                            )}{' '}
                            <span className="dotsep" /> {props.dueSoon} due in the next two days
                        </p>
                    </div>
                </header>
                <div className="home-grid">
                    <div className="col">
                        {top ? <DoNext task={top} next={next} total={props.total} clock={clock} /> : <EmptyHero />}
                        <TodayPlan plan={props.plan} clock={clock} hasTasks={props.total > 0} />
                        <Upcoming tasks={props.upcoming} clock={clock} />
                    </div>
                    <div className="col">
                        {props.habits.length > 0 && <Habits habits={props.habits} routines={props.routines} />}
                        <Attention attention={props.attention} clock={clock} />
                        <Week week={props.week} clock={clock} />
                    </div>
                </div>
            </div>
        </VelocityShell>
    );
}

function DoNext({ task, next, total, clock }: { task: RankedTask; next: RankedTask[]; total: number; clock: Clock }) {
    const due = dueInfo(task, clock);
    const done = task.steps.filter((s) => s.done).length;
    return (
        <section className="card" aria-labelledby="do-next">
            <div className="hero">
                <div className="hero-top">
                    <span className="pill-label" id="do-next">
                        <Icon name="zap" />
                        Do next
                    </span>
                    <div className="hero-score">
                        <b>{fmt1(task.score)}</b>
                        <span>#1 of {total}</span>
                    </div>
                </div>
                <h2 className="hero-title">{task.title}</h2>
                <div className="meta">
                    {task.project && <span>{task.project.name}</span>}
                    {task.project && <span className="dotsep" />}
                    <span className={`chip ${due.cls}`}>
                        <Icon name="clock" />
                        {due.text}
                    </span>
                    <span className="dotsep" />
                    <span>{fmtHours(task.estHours)} of work</span>
                    {task.status === 'doing' && <span className="chip is-doing">Doing</span>}
                </div>
                <div className="hero-actions">
                    <StartFocus taskId={task.id} />
                    <button
                        className="btn btn-secondary btn-lg"
                        onClick={() => router.post(route('tasks.complete', task.id), {}, { preserveScroll: true, preserveState: true })}
                    >
                        <Icon name="check" />
                        Done
                    </button>
                    <span className="spacer" />
                    {task.steps.length > 0 && (
                        <span className="progress-mini">
                            <span className="track">
                                <i style={{ width: `${(done / task.steps.length) * 100}%` }} />
                            </span>
                            {done} of {task.steps.length} steps
                        </span>
                    )}
                </div>
            </div>
            {next.length > 0 && (
                <div className="then">
                    <div className="then-head">
                        <span className="then-label">Then</span>
                        <Link className="card-link" href="/tasks">
                            See all {total}
                            <Icon name="arrowR" className="ic-sm" />
                        </Link>
                    </div>
                    {next.map((n) => {
                        const d = dueInfo(n, clock);
                        return (
                            <Link key={n.id} className="mini-row" href={`/tasks?task=${n.id}`}>
                                <span className="rank-badge">{n.rank}</span>
                                <div>
                                    <div className="row-title">{n.title}</div>
                                    <div className="row-sub">
                                        {n.project && (
                                            <>
                                                <span>{n.project.name}</span>
                                                <span className="dotsep" />
                                            </>
                                        )}
                                        <span className={d.late ? 'is-late' : ''}>{d.text}</span>
                                        <span className="dotsep" />
                                        <span>{fmtHours(n.estHours)}</span>
                                    </div>
                                </div>
                                <span className="score-num">{fmt1(n.score)}</span>
                            </Link>
                        );
                    })}
                </div>
            )}
        </section>
    );
}

function EmptyHero() {
    return (
        <section className="card hero">
            <span className="pill-label">
                <Icon name="zap" />
                Do next
            </span>
            <h2 className="hero-title">Nothing to do</h2>
        </section>
    );
}

const PLAN_ICON = { class: 'cap', work: 'briefcase', personal: 'heart' };

/** Today on the Planner: classes, events and booked work, with where "now" is. */
function TodayPlan({ plan, clock, hasTasks }: { plan: Props['plan']; clock: Clock; hasTasks: boolean }) {
    const now = clock.now.getTime();
    const today = clock.localInput(clock.now.toISOString()).slice(0, 10);
    const href = `/planner?date=${today}&view=day`;
    const nowAt = plan.items.findIndex((i) => !(i.kind === 'event' && i.allDay) && Date.parse(i.start) > now);
    const rows = plan.items.map((i) => {
        const timed = !(i.kind === 'event' && i.allDay);
        const current = timed && Date.parse(i.start) <= now && now < Date.parse(i.end);
        const past = timed && Date.parse(i.end) <= now;
        const minutes = Math.round((Date.parse(i.end) - Date.parse(i.start)) / 60000);
        return (
            <li
                key={i.key}
                className={`tl-item${i.kind === 'block' ? ' is-task' : ''}${current ? ' is-now' : ''}${past || (i.kind === 'habit' && i.done) ? ' is-past' : ''}`}
            >
                <span className="tl-time">{timed ? clock.time(i.start) : 'All day'}</span>
                <span className="tl-rail">
                    <span className="tl-dot" />
                </span>
                <Link className="tl-block" href={href}>
                    <div className="tl-title">{i.title}</div>
                    <div className="tl-sub">
                        {i.kind === 'habit' ? (
                            <>
                                <Icon name={i.done ? 'check' : 'habits'} className="ic-xs" />
                                {clock.time(i.start)}–{clock.time(i.end)}
                                {i.target > 1 ? ` · ${i.count} of ${i.target}` : i.done ? ' · done' : ''}
                            </>
                        ) : i.kind === 'block' ? (
                            <>
                                {i.rank && (
                                    <>
                                        <Icon name="rank" className="ic-xs" />#{i.rank} ·{' '}
                                    </>
                                )}
                                {fmtHours(minutes / 60)}
                            </>
                        ) : (
                            <>
                                <Icon name={PLAN_ICON[i.calendar]} className="ic-xs" />
                                {timed && `${clock.time(i.start)}–${clock.time(i.end)}`}
                                {i.location && ` · ${i.location}`}
                            </>
                        )}
                    </div>
                </Link>
            </li>
        );
    });
    if (nowAt >= 0) {
        rows.splice(
            nowAt,
            0,
            <li key="now" className="tl-now" aria-label={`Now, ${clock.time(clock.now)}`}>
                <span>{clock.time(clock.now)}</span>
                <i />
            </li>,
        );
    }
    const planDay = () => router.post(route('planner.plan'), { date: today, span: 'day' }, { preserveScroll: true });
    return (
        <section className="card" aria-labelledby="plan-title">
            <div className="card-head">
                <h2 className="card-title" id="plan-title">
                    <Icon name="planner" />
                    Today's plan
                </h2>
                {plan.booked > 0 ? (
                    <span className="card-sub">
                        {fmtHours(plan.booked / 60)} / {fmtHours(plan.limit)} booked
                    </span>
                ) : (
                    hasTasks && (
                        <button className="btn btn-secondary btn-sm" onClick={planDay}>
                            <Icon name="wand" />
                            Plan day
                        </button>
                    )
                )}
            </div>
            {plan.items.length === 0 ? <p className="card-empty">Nothing on today.</p> : <ol className="tl">{rows}</ol>}
            <div className="card-foot">
                <Link className="card-link" href={href}>
                    Open Planner
                    <Icon name="arrowR" className="ic-xs" />
                </Link>
            </div>
        </section>
    );
}

function Upcoming({ tasks, clock }: { tasks: RankedTask[]; clock: Clock }) {
    return (
        <section className="card" aria-labelledby="up-title">
            <div className="card-head">
                <h2 className="card-title" id="up-title">
                    <Icon name="planner" />
                    Coming up
                </h2>
                <span className="card-sub">Deadlines in the next 7 days</span>
            </div>
            {tasks.length === 0 ? (
                <p className="card-empty">No deadlines this week.</p>
            ) : (
                <ol className="tl">
                    {tasks.map((t, i) => {
                        const d = dueInfo(t, clock);
                        return (
                            <li key={t.id} className={`tl-item is-task${i === 0 ? ' is-now' : ''}`}>
                                <span className="tl-time">{d.late ? 'Late' : clock.dayDiff(t.dueAt!) === 0 ? 'Today' : clock.weekday(t.dueAt!)}</span>
                                <span className="tl-rail">
                                    <span className="tl-dot" />
                                </span>
                                <Link className="tl-block" href={`/tasks?task=${t.id}`}>
                                    <div className="tl-title">{t.title}</div>
                                    <div className="tl-sub">
                                        <Icon name="rank" className="ic-xs" />#{t.rank} · <span className={d.late ? 'is-late' : ''}>{d.text}</span> ·{' '}
                                        {fmtHours(t.estHours)}
                                    </div>
                                </Link>
                            </li>
                        );
                    })}
                </ol>
            )}
        </section>
    );
}

function Habits({ habits, routines }: { habits: HabitToday[]; routines: Props['routines'] }) {
    const done = habits.filter((h) => h.group === 'done').length;
    return (
        <section className="card" aria-labelledby="habits-title">
            <div className="card-head">
                <h2 className="card-title" id="habits-title">
                    <Icon name="habits" />
                    Habits today
                </h2>
                <span className="card-sub">
                    {done} of {habits.length} done
                </span>
            </div>
            {routines.length > 0 && (
                <div className="home-routines">
                    {routines.map((r) => (
                        <Link key={r.id} className={`p-linked-note${r.done === r.of ? ' is-done' : ''}`} href={`/habits?view=r${r.id}`}>
                            <Icon name={r.done === r.of ? 'check' : 'list'} className="ic-sm" />
                            <span>{r.name}</span>
                            <b>
                                {r.done} / {r.of}
                            </b>
                        </Link>
                    ))}
                </div>
            )}
            <div style={{ paddingBottom: 8 }}>
                {habits.map((h) => (
                    <div className="habit" key={h.id}>
                        <HabitButton habit={h} />
                        <div>
                            <div className={`habit-name${h.doneToday ? ' is-done' : ''}`}>{h.name}</div>
                            <div className="habit-detail">
                                {h.week
                                    ? `${h.week.done} of ${h.week.need} this week`
                                    : h.target > 1
                                      ? `${h.today} of ${h.target}${h.unit ? ` ${h.unit}` : ''}`
                                      : h.detail}
                                {h.project ? ` · ${h.project.name}` : ''}
                            </div>
                        </div>
                        <Streak habit={h} />
                    </div>
                ))}
            </div>
            <div className="card-foot">
                <Link className="card-link" href="/habits">
                    Open Habits
                    <Icon name="arrowR" className="ic-xs" />
                </Link>
            </div>
        </section>
    );
}

function Attention({ attention, clock }: { attention: Props['attention']; clock: Clock }) {
    const rows = [
        ...attention.overdue.map((t) => ({
            key: `o${t.id}`,
            icon: 'alert',
            critical: true,
            title: t.title,
            detail: dueInfo(t, clock).long,
            href: `/tasks?task=${t.id}`,
            action: 'Open',
        })),
        ...attention.notReady.map((t) => ({
            key: `n${t.id}`,
            icon: t.kind === 'waiting' ? 'hourglass' : t.kind === 'blocked' ? 'lock' : 'calendar',
            critical: false,
            title: t.title,
            detail: t.why,
            href: `/tasks?task=${t.id}`,
            action: t.kind === 'waiting' ? 'Follow up' : '',
        })),
        ...attention.milestones.map((m) => ({
            key: `m${m.projectId}-${m.title}`,
            icon: 'flag',
            critical: false,
            title: `${m.title} · ${m.project}`,
            detail: `Milestone due ${clock.date(m.dueDate + 'T12:00:00Z')}`,
            href: `/projects/${m.projectId}`,
            action: 'Open',
        })),
    ];
    return (
        <section className="card" aria-labelledby="att-title">
            <div className="card-head">
                <h2 className="card-title" id="att-title">
                    <Icon name="bell" />
                    Needs attention
                </h2>
            </div>
            {rows.length === 0 ? (
                <p className="card-empty">All clear.</p>
            ) : (
                <div style={{ paddingBottom: 8 }}>
                    {rows.map((r) => (
                        <div className="att" key={r.key}>
                            <span className={`att-ic${r.critical ? ' is-critical' : ''}`}>
                                <Icon name={r.icon} />
                            </span>
                            <div>
                                <div className="att-title">{r.title}</div>
                                <div className="att-detail">{r.detail}</div>
                            </div>
                            {r.action ? (
                                <Link className="btn btn-ghost btn-sm" href={r.href}>
                                    {r.action}
                                </Link>
                            ) : (
                                <span />
                            )}
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}

function Week({ week, clock }: { week: Props['week']; clock: Clock }) {
    const max = Math.max(1, ...week.days.map((d) => d.count));
    return (
        <section className="card" aria-labelledby="week-title">
            <div className="card-head">
                <h2 className="card-title" id="week-title">
                    <Icon name="review" />
                    Last 7 days
                </h2>
                <Link className="card-link" href="/history">
                    History
                    <Icon name="arrowR" className="ic-sm" />
                </Link>
            </div>
            <div className="stats">
                <div className="stat">
                    <b>{week.done}</b>
                    <span>{week.done === 1 ? 'task done' : 'tasks done'}</span>
                </div>
                <div className="stat">
                    <b>{week.onTime == null ? '–' : `${week.onTime}%`}</b>
                    <span>on time</span>
                </div>
                <div className="stat">
                    <b>{week.avgScore == null ? '–' : fmt1(week.avgScore)}</b>
                    <span>avg. priority</span>
                </div>
            </div>
            <div
                className="week"
                role="img"
                aria-label={`Tasks done per day: ${week.days.map((d) => `${clock.weekday(d.day + 'T12:00:00Z')} ${d.count}`).join(', ')}`}
            >
                <div className="week-bars">
                    {week.days.map((d) => (
                        <i
                            key={d.day}
                            className={d.count ? '' : 'is-zero'}
                            style={{ height: `${d.count ? (d.count / max) * 100 : 3}%` }}
                            title={`${clock.date(d.day + 'T12:00:00Z', { weekday: 'short', day: 'numeric', month: 'short' })}: ${d.count} done`}
                        />
                    ))}
                </div>
                <div className="week-labels">
                    {week.days.map((d, i) => (
                        <span key={d.day} className={i === week.days.length - 1 ? 'is-today' : ''}>
                            {clock.weekday(d.day + 'T12:00:00Z').slice(0, 1)}
                        </span>
                    ))}
                </div>
            </div>
        </section>
    );
}
