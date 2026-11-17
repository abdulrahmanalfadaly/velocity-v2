import { Icon } from '@/components/velocity/icon';
import type { VelocityShared } from '@/types/velocity';
import { Link, router, usePage } from '@inertiajs/react';

export type TasksTab = 'upnext' | 'waiting' | 'next' | 'all';
export type Layout = 'list' | 'board' | 'timeline';

const LAYOUTS: [Layout, string, string][] = [
    ['list', 'List', 'list'],
    ['board', 'Board', 'columns'],
    ['timeline', 'Timeline', 'calendar'],
];

/**
 * The bar on the Tasks pages and the Projects page: List, Board and Timeline (Up next's layouts, remembered on
 * this device) and Projects, then the Goals map. Away from Up next, a layout opens Up next in it.
 */
export function TasksSwitch({ current, onLayout }: { current: Layout | 'projects' | null; onLayout?: (l: Layout) => void }) {
    const pick = (l: Layout) => {
        if (onLayout) return onLayout(l);
        localStorage.setItem('velocity.layout', l);
        router.visit('/tasks');
    };
    return (
        <div className="page-actions">
            <div className="layout-seg" role="group" aria-label="Show as">
                {LAYOUTS.map(([k, label, icon]) => (
                    <button key={k} type="button" aria-pressed={current === k} onClick={() => pick(k)} title={label}>
                        <Icon name={icon} className="ic-sm" />
                        <span className="btn-label">{label}</span>
                    </button>
                ))}
                <Link href="/projects" aria-current={current === 'projects' ? 'page' : undefined} title="Projects">
                    <Icon name="folder" className="ic-sm" />
                    <span className="btn-label">Projects</span>
                </Link>
            </div>
            <Link href="/goals" className="btn btn-secondary" title="Goals map">
                <Icon name="target" />
                <span className="btn-label">Map</span>
            </Link>
        </div>
    );
}

/** The top of the main Tasks pages: the title, the bar, and the tabs between its four views. */
export function TasksHead({
    tab,
    count,
    layout = null,
    onLayout,
}: {
    tab: TasksTab;
    count?: number;
    /** Up next's layout, pressed in the bar (none on the other tabs). */
    layout?: Layout | null;
    onLayout?: (l: Layout) => void;
}) {
    const { props } = usePage<VelocityShared>();
    const counts = props.nav?.counts;
    const tabs: [TasksTab, string, string, number | null | undefined][] = [
        ['upnext', 'Up next', '/tasks', counts?.upNext],
        ['waiting', 'Not ready', '/tasks/waiting', counts?.waiting],
        ['next', 'Next actions', '/tasks/next', null],
        ['all', 'All', '/tasks/all', null],
    ];
    return (
        <>
            <header className="page-head tasks-head">
                <h1 className="h1">Tasks {count != null && <span className="count">{`${count} ${count === 1 ? 'task' : 'tasks'}`}</span>}</h1>
                <TasksSwitch current={layout} onLayout={onLayout} />
            </header>
            <nav className="view-tabs" aria-label="Tasks views">
                {tabs.map(([k, label, href, n]) => (
                    <Link key={k} href={href} aria-current={tab === k ? 'page' : undefined} preserveScroll={false}>
                        {label}
                        {n ? <span className="n">{n}</span> : null}
                    </Link>
                ))}
            </nav>
        </>
    );
}
