import { Icon } from '@/components/velocity/icon';
import VelocityShell from '@/layouts/velocity-shell';
import { Clock, fmt1 } from '@/lib/velocity';
import type { VelocityShared } from '@/types/velocity';
import { usePage } from '@inertiajs/react';

interface Props extends VelocityShared {
    weeks: { start: string; done: number; onTime: number; withDue: number }[];
    totals: { done: number; onTimePct: number | null; avgScore: number | null };
    recent: {
        id: number;
        title: string;
        project: string | null;
        completedAt: string;
        dueAt: string | null;
        onTime: boolean | null;
        score: number | null;
    }[];
}

/** The last 12 weeks: tasks done per week, and how many were on time. */
export default function History() {
    const { props } = usePage<Props>();
    const clock = Clock.from(props.clock);
    const max = Math.max(1, ...props.weeks.map((w) => w.done));

    return (
        <VelocityShell title="History" section="review">
            <div className="main-inner" style={{ maxWidth: 960 }}>
                <header className="page-head">
                    <div>
                        <p className="eyebrow">Review</p>
                        <h1 className="h1">History</h1>
                    </div>
                </header>
                <section className="card" style={{ paddingBottom: 18 }}>
                    <div className="stats" style={{ padding: '18px 18px 6px' }}>
                        <div className="stat">
                            <b>{props.totals.done}</b>
                            <span>tasks done</span>
                        </div>
                        <div className="stat">
                            <b>{props.totals.onTimePct == null ? '–' : `${props.totals.onTimePct}%`}</b>
                            <span>on time</span>
                        </div>
                        <div className="stat">
                            <b>{props.totals.avgScore == null ? '–' : fmt1(props.totals.avgScore)}</b>
                            <span>avg. priority when done</span>
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
                        aria-label={`Tasks done per week: ${props.weeks.map((w) => `${clock.date(w.start + 'T12:00:00Z')} ${w.done}`).join(', ')}`}
                    >
                        <div className="week-bars" style={{ gridTemplateColumns: 'repeat(12, 1fr)', height: 160, gap: 8 }}>
                            {props.weeks.map((w) => {
                                const late = w.withDue - w.onTime;
                                const none = w.done - w.withDue;
                                const label = `Week of ${clock.date(w.start + 'T12:00:00Z')}: ${w.done} done, ${w.onTime} on time, ${late} late`;
                                return (
                                    <span
                                        key={w.start}
                                        title={label}
                                        style={{
                                            display: 'flex',
                                            flexDirection: 'column-reverse',
                                            gap: 2,
                                            height: `${(w.done / max) * 100}%`,
                                            minHeight: 3,
                                        }}
                                    >
                                        {w.onTime > 0 && (
                                            <i style={{ flex: w.onTime, background: 'var(--violet)', borderRadius: '0 0 0 0', minHeight: 2 }} />
                                        )}
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
                <section className="card" style={{ marginTop: 20 }}>
                    <div className="card-head">
                        <h2 className="card-title">
                            <Icon name="check" />
                            Recently done
                        </h2>
                    </div>
                    <div style={{ paddingBottom: 8 }}>
                        {props.recent.map((r) => (
                            <div className="att" key={r.id}>
                                <span className={`att-ic${r.onTime === false ? ' is-critical' : ''}`}>
                                    <Icon name={r.onTime === false ? 'alert' : 'check'} />
                                </span>
                                <div>
                                    <div className="att-title">{r.title}</div>
                                    <div className="att-detail">
                                        {r.project ?? 'No project'} · done{' '}
                                        {clock.date(r.completedAt, { weekday: 'short', day: 'numeric', month: 'short' })}
                                        {r.onTime === false ? ' · late' : r.onTime ? ' · on time' : ''}
                                    </div>
                                </div>
                                <span className="score-num" style={{ fontSize: 13 }}>
                                    {r.score != null ? fmt1(r.score) : ''}
                                </span>
                            </div>
                        ))}
                    </div>
                </section>
            </div>
        </VelocityShell>
    );
}
