import { Icon } from '@/components/velocity/icon';
import type { VelocityShared } from '@/types/velocity';
import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

export type TasksTab = 'upnext' | 'waiting' | 'next' | 'all';

/**
 * The top of the main Tasks pages: the title; the switch (Up next's List / Board / Timeline, then the Goals map
 * and Projects, which open their own pages); the page's own buttons; and the tabs between its four views.
 */
export function TasksHead({ tab, count, layouts, actions }: { tab: TasksTab; count?: number; layouts?: ReactNode; actions?: ReactNode }) {
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
                <div className="page-actions">
                    <div className="layout-seg" role="group" aria-label="Show as">
                        {layouts}
                        <Link href="/goals" title="Goals map">
                            <Icon name="target" className="ic-sm" />
                            <span className="btn-label">Map</span>
                        </Link>
                        <Link href="/projects" title="Projects">
                            <Icon name="folder" className="ic-sm" />
                            <span className="btn-label">Projects</span>
                        </Link>
                    </div>
                    {actions}
                </div>
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
