import { Icon } from '@/components/velocity/icon';
import { Ring } from '@/components/velocity/ui';
import VelocityShell from '@/layouts/velocity-shell';
import { Clock, goalVar } from '@/lib/velocity';
import type { VelocityShared } from '@/types/velocity';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Status = 'active' | 'planned' | 'on_hold' | 'completed' | 'archived';

interface Props extends VelocityShared {
    projects: {
        id: number;
        name: string;
        status: Status;
        goal: { title: string; color: number } | null;
        area: string | null;
        open: number;
        done: number;
        pct: number;
        dueDate: string | null;
        completedAt: string | null;
        bestRank: number | null;
    }[];
}

const TABS: [Status, string][] = [
    ['active', 'Active'],
    ['planned', 'Planned'],
    ['on_hold', 'On hold'],
    ['completed', 'Completed'],
    ['archived', 'Archived'],
];

/** All projects, by status: progress, open tasks, due date and the rank of each one's best task. */
export default function AllProjects() {
    const { props } = usePage<Props>();
    const clock = Clock.from(props.clock);
    const [tab, setTab] = useState<Status>('active');
    const shown = props.projects.filter((p) => p.status === tab);

    return (
        <VelocityShell title="All projects" section="tasks" active="projects">
            <div className="main-inner">
                <header className="page-head">
                    <div>
                        <p className="eyebrow is-section">Tasks</p>
                        <h1 className="h1">
                            All projects <span className="count">{props.projects.length}</span>
                        </h1>
                    </div>
                </header>
                <div className="seg ev-seg ap-tabs" role="tablist" aria-label="Status">
                    {TABS.map(([k, label]) => {
                        const n = props.projects.filter((p) => p.status === k).length;
                        return (
                            <button key={k} type="button" role="tab" aria-selected={tab === k} aria-checked={tab === k} onClick={() => setTab(k)}>
                                {label}
                                {n > 0 && <span className="hint"> {n}</span>}
                            </button>
                        );
                    })}
                </div>
                {shown.length === 0 ? (
                    <p className="list-empty">No projects here.</p>
                ) : (
                    <section className="card na-list" aria-label={TABS.find((t) => t[0] === tab)![1]}>
                        {shown.map((p) => (
                            <Link
                                key={p.id}
                                className="na-row ap-row"
                                href={`/projects/${p.id}`}
                                style={{ ['--goal' as string]: goalVar(p.goal?.color) }}
                            >
                                <span className="ap-ring">
                                    <Ring pct={p.pct} size={32} stroke={4} />
                                </span>
                                <div className="na-main">
                                    <span className="row-title">{p.name}</span>
                                    <span className="row-sub">
                                        {p.goal && (
                                            <>
                                                <i className="na-dot" aria-hidden="true" />
                                                {p.goal.title}
                                                <span className="dotsep" />
                                            </>
                                        )}
                                        {p.area && (
                                            <>
                                                {p.area}
                                                <span className="dotsep" />
                                            </>
                                        )}
                                        {p.done} of {p.open + p.done} done
                                        {p.open > 0 && ` · ${p.open} open`}
                                        {p.dueDate &&
                                            ` · due ${clock.date(`${p.dueDate}T12:00:00Z`, { day: 'numeric', month: 'short', timeZone: 'UTC' })}`}
                                        {p.completedAt && ` · completed ${clock.date(p.completedAt)}`}
                                    </span>
                                </div>
                                {p.bestRank ? (
                                    <span className="rank-badge" title="Its best task in your whole list">
                                        {p.bestRank}
                                    </span>
                                ) : (
                                    <Icon name="chevR" className="ic-sm" />
                                )}
                            </Link>
                        ))}
                    </section>
                )}
            </div>
        </VelocityShell>
    );
}
