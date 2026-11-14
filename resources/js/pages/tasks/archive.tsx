import { Icon } from '@/components/velocity/icon';
import VelocityShell from '@/layouts/velocity-shell';
import { Clock, goalVar } from '@/lib/velocity';
import type { VelocityShared } from '@/types/velocity';
import { Link, router, usePage } from '@inertiajs/react';

interface Props extends VelocityShared {
    tasks: { id: number; title: string; project: string | null; deletedAt: string | null }[];
    projects: { id: number; name: string; archivedAt: string | null; open: number }[];
    goals: { id: number; title: string; color: number; archivedAt: string | null }[];
    notes: { id: number; title: string; where: string; deletedAt: string | null }[];
}

const keep = { preserveScroll: true, preserveState: true };

/** Deleted tasks and notes, archived projects and goals, each with Restore. */
export default function Archive() {
    const { props } = usePage<Props>();
    const clock = Clock.from(props.clock);
    const when = (iso: string | null) => (iso ? clock.date(iso, { day: 'numeric', month: 'short', year: 'numeric' }) : '');
    const empty = !props.tasks.length && !props.notes.length && !props.projects.length && !props.goals.length;

    return (
        <VelocityShell title="Archive" section="tasks" active="archive">
            <div className="main-inner">
                <header className="page-head">
                    <div>
                        <p className="eyebrow is-section">Tasks</p>
                        <h1 className="h1">Archive</h1>
                    </div>
                </header>
                {empty && <p className="list-empty">Nothing archived or deleted.</p>}

                {props.projects.length > 0 && (
                    <section className="card archive-card" aria-labelledby="ar-projects">
                        <div className="card-head">
                            <h2 className="card-title" id="ar-projects">
                                <Icon name="folder" />
                                Projects
                            </h2>
                        </div>
                        {props.projects.map((p) => (
                            <div className="fold-row" key={p.id}>
                                <span className="att-ic">
                                    <Icon name="folder" />
                                </span>
                                <div>
                                    <Link className="row-title" href={`/projects/${p.id}`}>
                                        {p.name}
                                    </Link>
                                    <div className="row-sub">
                                        Archived {when(p.archivedAt)}
                                        {p.open > 0 && ` · ${p.open} open ${p.open === 1 ? 'task' : 'tasks'}`}
                                    </div>
                                </div>
                                <button className="btn btn-secondary btn-sm" onClick={() => router.post(route('projects.unarchive', p.id), {}, keep)}>
                                    Restore
                                </button>
                            </div>
                        ))}
                    </section>
                )}

                {props.goals.length > 0 && (
                    <section className="card archive-card" aria-labelledby="ar-goals">
                        <div className="card-head">
                            <h2 className="card-title" id="ar-goals">
                                <Icon name="target" />
                                Goals
                            </h2>
                        </div>
                        {props.goals.map((g) => (
                            <div className="fold-row" key={g.id}>
                                <span className="att-ic" style={{ color: goalVar(g.color) }}>
                                    <Icon name="target" />
                                </span>
                                <div>
                                    <div className="row-title">{g.title}</div>
                                    <div className="row-sub">Archived {when(g.archivedAt)}</div>
                                </div>
                                <button className="btn btn-secondary btn-sm" onClick={() => router.post(route('goals.unarchive', g.id), {}, keep)}>
                                    Restore
                                </button>
                            </div>
                        ))}
                    </section>
                )}

                {props.tasks.length > 0 && (
                    <section className="card archive-card" aria-labelledby="ar-tasks">
                        <div className="card-head">
                            <h2 className="card-title" id="ar-tasks">
                                <Icon name="tasks" />
                                Deleted tasks
                            </h2>
                        </div>
                        {props.tasks.map((t) => (
                            <div className="fold-row" key={t.id}>
                                <span className="att-ic">
                                    <Icon name="archive" />
                                </span>
                                <div>
                                    <div className="row-title">{t.title}</div>
                                    <div className="row-sub">
                                        {t.project ?? 'No project'} · deleted {when(t.deletedAt)}
                                    </div>
                                </div>
                                <button className="btn btn-secondary btn-sm" onClick={() => router.post(route('tasks.restore', t.id), {}, keep)}>
                                    Restore
                                </button>
                            </div>
                        ))}
                    </section>
                )}

                {props.notes.length > 0 && (
                    <section className="card archive-card" aria-labelledby="ar-notes">
                        <div className="card-head">
                            <h2 className="card-title" id="ar-notes">
                                <Icon name="notes" />
                                Deleted notes
                            </h2>
                        </div>
                        {props.notes.map((n) => (
                            <div className="fold-row" key={n.id}>
                                <span className="att-ic">
                                    <Icon name="archive" />
                                </span>
                                <div>
                                    <div className="row-title">{n.title}</div>
                                    <div className="row-sub">
                                        {n.where} · deleted {when(n.deletedAt)}
                                    </div>
                                </div>
                                <button className="btn btn-secondary btn-sm" onClick={() => router.post(route('notes.restore', n.id), {}, keep)}>
                                    Restore
                                </button>
                            </div>
                        ))}
                    </section>
                )}
            </div>
        </VelocityShell>
    );
}
