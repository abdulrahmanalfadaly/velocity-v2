import { StartFocus } from '@/components/velocity/focus';
import { Icon } from '@/components/velocity/icon';
import { TasksHead } from '@/components/velocity/tasks-head';
import VelocityShell from '@/layouts/velocity-shell';
import { Clock, dueInfo, fmtHours, goalVar } from '@/lib/velocity';
import type { RankedTask, VelocityShared } from '@/types/velocity';
import { Link, usePage } from '@inertiajs/react';

interface Props extends VelocityShared {
    rows: { project: { id: number; name: string; goalColor: number | null } | null; task: RankedTask }[];
    idle: { id: number; name: string; goalColor: number | null; why: string }[];
}

/** Next actions: each project's best task by the formula, in the order of those tasks; projects with nothing ready below. */
export default function NextActions() {
    const { props } = usePage<Props>();
    const clock = Clock.from(props.clock);

    return (
        <VelocityShell title="Next actions" section="tasks" active="tasks">
            <div className="main-inner">
                <TasksHead tab="next" count={props.rows.length} />
                {props.rows.length === 0 ? (
                    <p className="list-empty">Nothing is ready to work on.</p>
                ) : (
                    <section className="card na-list" aria-label="Next actions">
                        {props.rows.map(({ project, task }) => {
                            const due = task.dueAt ? dueInfo(task, clock) : null;
                            return (
                                <div className="na-row" key={task.id} style={{ ['--goal' as string]: goalVar(project?.goalColor) }}>
                                    <span className="rank-badge">{task.rank}</span>
                                    <div className="na-main">
                                        <Link className="row-title" href={`/tasks?task=${task.id}`}>
                                            {task.title}
                                        </Link>
                                        <div className="row-sub">
                                            {project ? (
                                                <Link className="na-proj" href={`/projects/${project.id}`}>
                                                    <i className="na-dot" aria-hidden="true" />
                                                    {project.name}
                                                </Link>
                                            ) : (
                                                <span className="na-proj">
                                                    <Icon name="inbox" className="ic-xs" />
                                                    No project
                                                </span>
                                            )}
                                            {due && (
                                                <>
                                                    <span className="dotsep" />
                                                    <span className={due.late ? 'is-late' : ''}>{due.text}</span>
                                                </>
                                            )}
                                            <span className="dotsep" />
                                            <span>{fmtHours(task.estHours)}</span>
                                        </div>
                                    </div>
                                    <StartFocus taskId={task.id} className="btn btn-secondary btn-sm" compact />
                                </div>
                            );
                        })}
                    </section>
                )}
                {props.idle.length > 0 && (
                    <section className="card na-list" aria-labelledby="na-idle">
                        <div className="card-head">
                            <h2 className="card-title" id="na-idle">
                                Nothing ready
                            </h2>
                        </div>
                        {props.idle.map((p) => (
                            <Link
                                key={p.id}
                                className="na-row is-idle"
                                href={`/projects/${p.id}`}
                                style={{ ['--goal' as string]: goalVar(p.goalColor) }}
                            >
                                <i className="na-dot" aria-hidden="true" />
                                <span className="row-title">{p.name}</span>
                                <span className="row-sub">{p.why}</span>
                            </Link>
                        ))}
                    </section>
                )}
            </div>
        </VelocityShell>
    );
}
