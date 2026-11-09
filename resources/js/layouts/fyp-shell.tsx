import { TaskFormOverlay } from '@/components/fyp/task-form';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { Icon, Logo } from '@/components/velocity/icon';
import { Toast, useToast } from '@/components/velocity/toast';
import { useAppearance } from '@/hooks/use-appearance';
import type { User } from '@/types';
import type { VelocityShared } from '@/types/velocity';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download, Layers, LogOut, Settings } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

export type FypPage = 'tasks' | 'history' | 'weights' | 'settings';

const PAGES: { key: FypPage; label: string; short: string; icon: string; href: string }[] = [
    { key: 'tasks', label: 'My tasks', short: 'Tasks', icon: 'list', href: '/tasks' },
    { key: 'history', label: 'History', short: 'History', icon: 'review', href: '/history' },
    { key: 'weights', label: 'Weights & method', short: 'Weights', icon: 'sliders', href: '/weights' },
];

/**
 * FYP mode: Velocity exactly as the report describes it – My tasks, History,
 * and Weights & method – like the first version.
 */
export default function FypShell({ title, page, children, panel }: { title: string; page: FypPage; children: ReactNode; panel?: ReactNode }) {
    const { props } = usePage<VelocityShared>();
    const user = props.auth.user;
    const [toast, setToast] = useToast();
    const [adding, setAdding] = useState(false);
    const [scenario, setScenario] = useState(false);
    const { appearance, updateAppearance } = useAppearance();
    const isDark =
        appearance === 'dark' || (appearance === 'system' && typeof window !== 'undefined' && matchMedia('(prefers-color-scheme: dark)').matches);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setAdding(true);
            } else if (e.key === 'Escape') {
                setScenario(false);
            }
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, []);
    useEffect(
        () =>
            router.on('navigate', () => {
                setAdding(false);
                setScenario(false);
            }),
        [],
    );

    const themeButton = (
        <button className="icon-btn" aria-label="Switch light or dark" onClick={() => updateAppearance(isDark ? 'light' : 'dark')}>
            <Icon name={isDark ? 'sun' : 'moon'} />
        </button>
    );
    const initials = (user?.name ?? '?')
        .split(' ')
        .map((w) => w[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
    const account = user && (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button className="avatar-btn" aria-label={`Account: ${user.name}`}>
                    <span className="avatar">{initials}</span>
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent className="w-64" align="end">
                <DropdownMenuLabel className="p-0 font-normal">
                    <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                        <UserInfo user={user as unknown as User} showEmail />
                    </div>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={() => setScenario(true)}>
                    <Layers className="mr-2" />
                    Load scenario tasks
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <a href={route('fyp.export')} download>
                        <Download className="mr-2" />
                        Export ranking (JSON)
                    </a>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link className="block w-full" href={route('mode.edit')}>
                        <Settings className="mr-2" />
                        Settings
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link className="block w-full" method="post" href={route('logout')} as="button">
                        <LogOut className="mr-2" />
                        Log out
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );

    return (
        <>
            <Head title={title} />
            <div className={`app no-sidebar fyp${panel ? ' has-panel' : ''}`}>
                <header className="gtop">
                    <Link href="/tasks" className="brand">
                        <Logo />
                        <span className="brand-name">Velocity</span>
                    </Link>
                    <nav className="tabs" aria-label="Pages">
                        {PAGES.map((p) => (
                            <Link
                                key={p.key}
                                href={p.href}
                                className={`tab-top${p.key === page ? ' is-active' : ''}`}
                                aria-current={p.key === page ? 'page' : undefined}
                                title={p.label}
                            >
                                <Icon name={p.icon} />
                                <span className="label">{p.label}</span>
                            </Link>
                        ))}
                    </nav>
                    <div className="gtop-right">
                        <button className="btn btn-primary fyp-new" onClick={() => setAdding(true)} title="New task (Ctrl Shift K)">
                            <Icon name="plus" />
                            <span>New task</span>
                        </button>
                        {themeButton}
                        {account}
                    </div>
                </header>

                <header className="topbar">
                    <Logo />
                    <span className="topbar-title">{PAGES.find((p) => p.key === page)?.short ?? title}</span>
                    <span className="topbar-gap" />
                    {themeButton}
                    {account}
                </header>

                <main className="main" id="main">
                    {children}
                </main>

                {panel && (
                    <aside className="panel" aria-label="Why this ranking">
                        {panel}
                    </aside>
                )}

                <nav className="tabbar fyp-tabbar" aria-label="Pages">
                    {PAGES.slice(0, 2).map((p) => (
                        <Link
                            key={p.key}
                            href={p.href}
                            className={`tab${p.key === page ? ' is-active' : ''}`}
                            aria-current={p.key === page ? 'page' : undefined}
                        >
                            <Icon name={p.icon} />
                            <span>{p.short}</span>
                        </Link>
                    ))}
                    <button className="tab-add" onClick={() => setAdding(true)} aria-label="New task">
                        <Icon name="plus" />
                    </button>
                    <Link
                        href={PAGES[2].href}
                        className={`tab${page === 'weights' ? ' is-active' : ''}`}
                        aria-current={page === 'weights' ? 'page' : undefined}
                    >
                        <Icon name={PAGES[2].icon} />
                        <span>{PAGES[2].short}</span>
                    </Link>
                </nav>

                {adding && <TaskFormOverlay onClose={() => setAdding(false)} />}

                {scenario && (
                    <div
                        className="overlay"
                        onClick={(e) => {
                            if (e.target === e.currentTarget) setScenario(false);
                        }}
                    >
                        <div className="dialog fyp-confirm" role="alertdialog" aria-modal="true" aria-labelledby="scenario-title">
                            <h2 id="scenario-title">Load scenario set</h2>
                            <p>Add the ten tasks of the standard scenario set (Table 3.4) to your list? Their due dates are set relative to now.</p>
                            <div className="fyp-confirm-actions">
                                <button className="btn btn-ghost" onClick={() => setScenario(false)}>
                                    Cancel
                                </button>
                                <button
                                    className="btn btn-primary"
                                    autoFocus
                                    onClick={() => router.post(route('fyp.scenario'), {}, { onFinish: () => setScenario(false) })}
                                >
                                    Load tasks
                                </button>
                            </div>
                        </div>
                    </div>
                )}

                <Toast toast={toast} onDone={() => setToast(null)} />
            </div>
        </>
    );
}
