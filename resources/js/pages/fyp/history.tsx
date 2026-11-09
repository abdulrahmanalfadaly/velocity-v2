import { Icon } from '@/components/velocity/icon';
import FypShell from '@/layouts/fyp-shell';
import { Clock, fmt1 } from '@/lib/velocity';
import type { VelocityShared } from '@/types/velocity';
import { router, usePage } from '@inertiajs/react';

interface Props extends VelocityShared {
    weeks: { start: string; done: number; onTime: number; withDue: number }[];
    totals: { done: number; onTimePct: number | null; avgScore: number | null };
    recent: { id: number; title: string; completedAt: string; dueAt: string | null; onTime: boolean | null; score: number | null }[];
    activity: { id: number; type: string; title: string | null; at: string | null }[];
}

const VERB: Record<string, string> = {
    created: 'Added',
    updated: 'Edited',
    completed: 'Completed',
    reopened: 'Reopened',
    deleted: 'Deleted',
    restored: 'Restored',
};

/** History (FR10): tasks completed per week, and how often they beat the due date. */
export default function FypHistory() {
    const { props } = usePage<Props>();
    const clock = Clock.from(props.clock);
    const max = Math.max(1, ...props.weeks.map((w) => w.done));
    const when = (iso: string) => clock.date(iso, { weekday: 'short', day: 'numeric', month: 'short' }) + ', ' + clock.time(iso);

    return (
        <FypShell title="History" page="history">
            <div className="main-inner" style={{ maxWidth: 960 }}>
                <header className="page-head">
                    <div>
                        <p className="eyebrow">Progress</p>
                        <h1 className="h1">History</h1>
                        <p className="page-sub">What you finished, week by week, and how often you beat the due date.</p>
                    </div>
                </header>

                <section className="card" style={{ paddingBottom: 18 }} aria-labelledby="weekly-title">
                    <div className="fyp-card-head" style={{ padding: '18px 18px 0' }}>
                        <h2 id="weekly-title">
                            <Icon name="review" />
                            Tasks completed per week
                        </h2>
                        <span className="muted">Last {props.weeks.length} weeks, Monday to Sunday</span>
                    </div>
                    <div className="stats" style={{ padding: '14px 18px 6px' }}>
                        <div className="stat">
                            <b>{props.totals.done}</b>
                            <span>tasks completed</span>
                        </div>
                        <div className="stat">
                            <b>{props.totals.onTimePct == null ? '–' : `${props.totals.onTimePct}%`}</b>
                            <span>on time (of tasks with a due date)</span>
                        </div>
                        <div className="stat">
                            <b>{props.totals.avgScore == null ? '–' : fmt1(props.totals.avgScore)}</b>
                            <span>average score at completion</span>
                        </div>
                    </div>
                    <div className="legend" style={{ padding: '6px 18px 0' }}>
                        <span>
                            <i className="sw" style={{ background: 'var(--violet)' }} />
                            On time
                        </span>
                        <span>
                            <i className="sw" style={{ background: 'var(--critical)' }} />
                            Late
                        </span>
                        <span>
                            <i className="sw" style={{ background: 'var(--track)' }} />
                            No due date
                        </span>
                    </div>
                    <div
                        className="week"
                        role="img"
                        aria-label={`Tasks completed per week: ${props.weeks.map((w) => `${clock.date(w.start + 'T12:00:00Z')} ${w.done}`).join(', ')}`}
                    >
                        <div className="week-bars" style={{ gridTemplateColumns: 'repeat(12, 1fr)', height: 160, gap: 8 }}>
                            {props.weeks.map((w) => {
                                const late = w.withDue - w.onTime;
                                const none = w.done - w.withDue;
                                return (
                                    <span
                                        key={w.start}
                                        title={`Week of ${clock.date(w.start + 'T12:00:00Z')}: ${w.done} completed, ${w.onTime} on time, ${late} late`}
                                        style={{
                                            display: 'flex',
                                            flexDirection: 'column-reverse',
                                            gap: 2,
                                            height: `${(w.done / max) * 100}%`,
                                            minHeight: 3,
                                        }}
                                    >
                                        {w.onTime > 0 && <i style={{ flex: w.onTime, background: 'var(--violet)', minHeight: 2 }} />}
                                        {late > 0 && <i style={{ flex: late, background: 'var(--critical)', minHeight: 2 }} />}
                                        {none > 0 && <i style={{ flex: none, background: 'var(--track)', minHeight: 2 }} />}
                                        {w.done === 0 && <i className="is-zero" style={{ flex: 1 }} />}
                                    </span>
                                );
                            })}
                        </div>
                        <div className="week-labels" style={{ gridTemplateColumns: 'repeat(12, 1fr)', gap: 8 }}>
                            {props.weeks.map((w, i) => (
                                <span key={w.start} className={i === props.weeks.length - 1 ? 'is-today' : ''}>
                                    {clock.date(w.start + 'T12:00:00Z')}
                                </span>
                            ))}
                        </div>
                    </div>
                </section>

                <div className="fyp-history-grid">
                    <section className="card" aria-labelledby="done-title">
                        <div className="card-head">
                            <h2 className="card-title" id="done-title">
                                <Icon name="check" />
                                Completed tasks
                            </h2>
                        </div>
                        <div style={{ paddingBottom: 8 }}>
                            {props.recent.length === 0 && <p className="card-empty">Nothing completed in the last 12 weeks.</p>}
                            {props.recent.map((r) => (
                                <div className="att" key={r.id}>
                                    <span className={`att-ic${r.onTime === false ? ' is-critical' : ''}`}>
                                        <Icon name={r.onTime === false ? 'alert' : 'check'} />
                                    </span>
                                    <div>
                                        <div className="att-title">{r.title}</div>
                                        <div className="att-detail">
                                            {when(r.completedAt)}
                                            {r.onTime === false ? ' · late' : r.onTime ? ' · on time' : ''}
                                            {r.score != null ? ` · score ${fmt1(r.score)}` : ''}
                                        </div>
                                    </div>
                                    <button
                                        className="btn btn-ghost btn-sm"
                                        onClick={() => router.post(route('tasks.reopen', r.id), {}, { preserveScroll: true })}
                                    >
                                        Reopen
                                    </button>
                                </div>
                            ))}
                        </div>
                    </section>

                    <section className="card" aria-labelledby="activity-title">
                        <div className="card-head">
                            <h2 className="card-title" id="activity-title">
                                <Icon name="clock" />
                                Recent activity
                            </h2>
                        </div>
                        <ol className="fyp-activity">
                            {props.activity.length === 0 && <li className="card-empty">No activity yet.</li>}
                            {props.activity.map((a) => (
                                <li key={a.id}>
                                    <span className="fyp-activity-verb">{VERB[a.type] ?? a.type}</span>
                                    <span className="fyp-activity-title">{a.title ?? 'a task'}</span>
                                    <span className="muted">{a.at ? when(a.at) : ''}</span>
                                </li>
                            ))}
                        </ol>
                    </section>
                </div>
            </div>
        </FypShell>
    );
}
