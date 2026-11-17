import type { VelocityShared } from '@/types/velocity';
import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

export type TasksTab = 'upnext' | 'waiting' | 'next' | 'all';

/** The top of the main Tasks pages: the title, the page's own buttons, and the tabs between its four views. */
export function TasksHead({ tab, count, actions }: { tab: TasksTab; count?: number; actions?: ReactNode }) {
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
                {actions && <div className="page-actions">{actions}</div>}
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
