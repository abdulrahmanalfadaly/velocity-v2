import { Icon } from '@/components/velocity/icon';
import { TasksSwitch } from '@/components/velocity/tasks-head';
import { Ring } from '@/components/velocity/ui';
import VelocityShell from '@/layouts/velocity-shell';
import { Clock, goalVar } from '@/lib/velocity';
import type { VelocityShared } from '@/types/velocity';
import { Link, router, usePage } from '@inertiajs/react';
import { Fragment, useState } from 'react';

type Status = 'active' | 'planned' | 'on_hold' | 'completed' | 'archived';

interface Props extends VelocityShared {
    /** The area picked (its id), "none" for projects outside any area, or null for all. */
    area: string | null;
    projects: {
        id: number;
        name: string;
        status: Status;
        goal: { title: string; color: number } | null;
        goals: { id: number; title: string; color: number }[];
        area: string | null;
        areaId: number | null;
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

/** Projects, by area and status: progress, open tasks, due date and the rank of each one's best task. The Tasks bar goes back. */
export default function AllProjects() {
    const { props } = usePage<Props>();
    const clock = Clock.from(props.clock);
    const [tab, setTab] = useState<Status>('active');
    const areas = props.nav?.areas ?? [];
    const inArea = props.projects.filter((p) =>
        props.area === null ? true : props.area === 'none' ? p.areaId === null : String(p.areaId) === props.area,
    );
    const shown = inArea.filter((p) => p.status === tab);
    const areaName = props.area === null ? null : props.area === 'none' ? 'Other' : (areas.find((a) => String(a.id) === props.area)?.name ?? null);
    // Chips for the areas: all of them, each area, and "Other" when some projects are in none.
    const chips: [string | null, string][] = [
        [null, 'All areas'],
        ...areas.map((a): [string, string] => [String(a.id), a.name]),
        ...(areas.length && props.projects.some((p) => p.areaId === null) ? [['none', 'Other'] as [string, string]] : []),
    ];
    const pickArea = (a: string | null) =>
        router.get('/projects', a ? { area: a } : {}, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <VelocityShell title={areaName ?? 'Projects'} section="tasks" active={props.area ? `area:${props.area}` : 'projects'}>
            <div className="main-inner">
                <header className="page-head tasks-head">
                    <h1 className="h1">
                        {areaName ?? 'All projects'} <span className="count">{inArea.length}</span>
                    </h1>
                    <TasksSwitch current="projects" />
                </header>
                {chips.length > 1 && (
                    <div className="filters ap-areas" role="group" aria-label="Area">
                        {chips.map(([k, label]) => (
                            <button key={k ?? 'all'} className="filter" aria-pressed={props.area === k} onClick={() => pickArea(k)}>
                                {label}
                            </button>
                        ))}
                    </div>
                )}
                <div className="seg ev-seg ap-tabs" role="tablist" aria-label="Status">
                    {TABS.map(([k, label]) => {
                        const n = inArea.filter((p) => p.status === k).length;
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
                                style={{ ['--goal' as string]: goalVar(p.goal?.color), ['--g' as string]: goalVar(p.goal?.color) }}
                            >
                                <span className="ap-ring">
                                    <Ring pct={p.pct} size={32} stroke={4} />
                                </span>
                                <div className="na-main">
                                    <span className="row-title">{p.name}</span>
                                    <span className="row-sub">
                                        {p.goals.map((g) => (
                                            <Fragment key={g.id}>
                                                <i className="na-dot" aria-hidden="true" style={{ ['--goal' as string]: goalVar(g.color) }} />
                                                {g.title}
                                                <span className="dotsep" />
                                            </Fragment>
                                        ))}
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
