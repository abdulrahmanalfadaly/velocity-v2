import { BulkSet } from '@/components/velocity/bulk-set';
import { Icon } from '@/components/velocity/icon';
import { Select } from '@/components/velocity/select';
import { TaskPanel } from '@/components/velocity/task-panel';
import VelocityShell from '@/layouts/velocity-shell';
import { Clock } from '@/lib/velocity';
import type { BaseTask, NotReadyTask, RankedTask, TaskDetail, VelocityShared } from '@/types/velocity';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type Tab = 'open' | 'done' | 'cancelled' | 'archived';
type Row = (RankedTask | NotReadyTask | BaseTask) & { rank?: number; why?: string };

interface Props extends VelocityShared {
    tab: Tab;
    q: string;
    project: string | null;
    rows: Row[];
    total: number;
    counts: Record<Tab, number>;
    selected: TaskDetail | null;
    projects: { id: number; name: string }[];
}

const TABS: [Tab, string][] = [
    ['open', 'Open'],
    ['done', 'Done'],
    ['cancelled', 'Cancelled'],
    ['archived', 'Archived'],
];

/** Every task, finished and archived ones too: find one, open it, or act on several. Open tasks keep the formula's order. */
export default function AllTasks() {
    const { props } = usePage<Props>();
    const clock = Clock.from(props.clock);
    const [q, setQ] = useState(props.q);
    const [picked, setPicked] = useState<number[]>([]);
    const first = useRef(true);
    // The address keeps the tab, search and project (Open is the default tab).
    const params = (over: Record<string, string | number | null> = {}) => {
        const all: Record<string, string | number | null> = { tab: props.tab, q: props.q || null, project: props.project, ...over };
        if (all.tab === 'open') all.tab = null;
        return Object.fromEntries(Object.entries(all).filter(([, v]) => v != null && v !== ''));
    };
    const go = (over: Record<string, string | number | null>) => {
        setPicked([]);
        router.get('/tasks/all', params(over), { preserveState: true, preserveScroll: true, replace: true });
    };
    const open = (id: number | null) =>
        router.get('/tasks/all', params({ task: id }), { preserveState: true, preserveScroll: true, only: ['selected'] });

    // Searching as you type, a moment after the last key.
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const t = setTimeout(() => go({ q: q.trim() || null }), 250);
        return () => clearTimeout(t);
    }, [q]); // eslint-disable-line react-hooks/exhaustive-deps

    const day = (iso: string | null) => (iso ? clock.date(iso, { weekday: 'short', day: 'numeric', month: 'short' }) : '');
    const sub = (r: Row) => {
        const parts = [r.project?.name ?? 'Inbox'];
        if (r.status === 'done') parts.push(`Done ${day(r.completedAt)}`);
        else if (r.status === 'cancelled') parts.push(`Cancelled ${day(r.cancelledAt)}${r.cancelReason ? ` · ${r.cancelReason}` : ''}`);
        else if (r.why) parts.push(r.why);
        else if (r.dueAt) parts.push(`Due ${day(r.dueAt)}`);
        if (r.archivedAt) parts.push(`archived ${day(r.archivedAt)}`);
        return parts.join(' · ');
    };
    const toggle = (id: number) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]));
    const bulk = (action: string, extra: Record<string, string | number | null> = {}) =>
        router.post(
            route('tasks.bulk'),
            { ids: picked, action, ...extra },
            { preserveScroll: true, preserveState: true, onSuccess: () => setPicked([]) },
        );

    return (
        <VelocityShell
            title="All tasks"
            section="tasks"
            active="all"
            panel={
                props.selected ? (
                    <TaskPanel key={props.selected.id} task={props.selected} clock={clock} projects={props.projects} onClose={() => open(null)} />
                ) : undefined
            }
            onEscape={() => (picked.length ? setPicked([]) : props.selected ? open(null) : undefined)}
        >
            <div className="main-inner">
                <header className="page-head">
                    <div>
                        <p className="eyebrow is-section">Tasks</p>
                        <h1 className="h1">
                            All tasks <span className="count">{props.total}</span>
                        </h1>
                    </div>
                </header>
                <div className="toolbar all-toolbar">
                    <div className="filters" role="group" aria-label="Show">
                        {TABS.map(([k, label]) => (
                            <button key={k} className="filter" aria-pressed={props.tab === k} onClick={() => go({ tab: k })}>
                                {label} <span className="n">{props.counts[k]}</span>
                            </button>
                        ))}
                    </div>
                    <div className="all-find">
                        <input
                            className="input all-search"
                            type="search"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Find a task"
                            aria-label="Find a task"
                            maxLength={100}
                        />
                        <Select
                            className="prop-input"
                            label="Project"
                            value={props.project ?? ''}
                            onChange={(v) => go({ project: v || null })}
                            options={[
                                { value: '', label: 'All projects' },
                                { value: 'inbox', label: 'Inbox (no project)' },
                                ...props.projects.map((p) => ({ value: String(p.id), label: p.name })),
                            ]}
                        />
                    </div>
                </div>

                {props.rows.length === 0 ? (
                    <p className="list-empty">{props.q || props.project ? 'No tasks match.' : 'No tasks here.'}</p>
                ) : (
                    <div className="card all-list">
                        {props.rows.map((r) => (
                            <div
                                key={r.id}
                                className={`fold-row is-link${props.selected?.id === r.id ? ' is-selected' : ''}${picked.includes(r.id) ? ' is-picked' : ''}`}
                                tabIndex={0}
                                onClick={(e) => (e.shiftKey || picked.length ? toggle(r.id) : open(props.selected?.id === r.id ? null : r.id))}
                                onKeyDown={(e) =>
                                    (e.key === 'Enter' || e.key === ' ') && e.target === e.currentTarget && (e.preventDefault(), open(r.id))
                                }
                            >
                                <button
                                    type="button"
                                    className={`pick${picked.includes(r.id) ? ' is-on' : ''}`}
                                    aria-label={`Select “${r.title}”`}
                                    aria-pressed={picked.includes(r.id)}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        toggle(r.id);
                                    }}
                                >
                                    <Icon name="check" />
                                </button>
                                <div>
                                    <div className={`row-title${r.status === 'done' || r.status === 'cancelled' ? ' is-finished' : ''}`}>
                                        {r.title}
                                    </div>
                                    <div className="row-sub">{sub(r)}</div>
                                </div>
                                {r.rank != null ? <span className="rank-badge">{r.rank}</span> : <span />}
                            </div>
                        ))}
                        {props.total > props.rows.length && (
                            <p className="all-more">
                                Showing {props.rows.length} of {props.total}. Search or pick a project to find the rest.
                            </p>
                        )}
                    </div>
                )}
            </div>
            {picked.length > 0 && (
                <div className="bulkbar" role="toolbar" aria-label="Actions for the selected tasks">
                    <b>{picked.length} selected</b>
                    <button className="link" onClick={() => setPicked(picked.length === props.rows.length ? [] : props.rows.map((r) => r.id))}>
                        {picked.length === props.rows.length ? 'None' : 'All'}
                    </button>
                    <span className="bulk-sep" />
                    {props.tab === 'open' ? (
                        <>
                            <button className="btn btn-primary btn-sm" onClick={() => bulk('complete')}>
                                <Icon name="check" className="ic-sm" />
                                Done
                            </button>
                            <button className="btn btn-secondary btn-sm" onClick={() => bulk('cancel')}>
                                <Icon name="ban" className="ic-sm" />
                                Cancel
                            </button>
                            <BulkSet onSet={bulk} />
                        </>
                    ) : (
                        <>
                            <button className="btn btn-primary btn-sm" onClick={() => bulk('reopen')}>
                                <Icon name="arrowL" className="ic-sm" />
                                Reopen
                            </button>
                            {props.tab === 'archived' ? (
                                <button className="btn btn-secondary btn-sm" onClick={() => bulk('unarchive')}>
                                    <Icon name="archive" className="ic-sm" />
                                    Out of the archive
                                </button>
                            ) : (
                                <button className="btn btn-secondary btn-sm" onClick={() => bulk('archive')}>
                                    <Icon name="archive" className="ic-sm" />
                                    Archive
                                </button>
                            )}
                        </>
                    )}
                    <button className="btn btn-secondary btn-sm" onClick={() => bulk('delete')}>
                        <Icon name="trash" className="ic-sm" />
                        Delete
                    </button>
                    <span className="bulk-sep" />
                    <button className="icon-btn" onClick={() => setPicked([])} aria-label="Stop selecting" title="Stop selecting (Esc)">
                        <Icon name="x" />
                    </button>
                </div>
            )}
        </VelocityShell>
    );
}
