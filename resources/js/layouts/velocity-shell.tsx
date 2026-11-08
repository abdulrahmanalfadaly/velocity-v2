import { Icon, Logo } from '@/components/velocity/icon';
import { QuickAdd } from '@/components/velocity/quick-add';
import { SearchPalette } from '@/components/velocity/search-palette';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useAppearance } from '@/hooks/use-appearance';
import type { User } from '@/types';
import type { VelocityShared } from '@/types/velocity';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { type ReactNode, useCallback, useEffect, useState } from 'react';

export type Section = 'home' | 'tasks' | 'planner' | 'notes' | 'habits' | 'people' | 'review' | 'settings';

const SECTIONS: { key: Section; label: string; icon: string; href?: string; phase?: number }[] = [
    { key: 'home', label: 'Home', icon: 'home', href: '/home' },
    { key: 'tasks', label: 'Tasks', icon: 'tasks', href: '/tasks' },
    { key: 'planner', label: 'Planner', icon: 'planner', phase: 3 },
    { key: 'notes', label: 'Notes', icon: 'notes', phase: 4 },
    { key: 'habits', label: 'Habits', icon: 'habits', phase: 5 },
    { key: 'people', label: 'People', icon: 'people', phase: 6 },
    { key: 'review', label: 'Review', icon: 'review', href: '/history' },
];

type Overlay = null | 'search' | 'add' | 'drawer' | 'more';

interface ShellProps {
    title: string;
    section: Section;
    /** The highlighted place in the side menu: "upnext", "inbox", "waiting", "goals", "project:12". */
    active?: string;
    children: ReactNode;
    /** A right-hand panel (the task panel), shown beside the content. */
    panel?: ReactNode;
    /** Full-bleed content such as the Goals map. */
    canvas?: boolean;
    hideSidebar?: boolean;
}

export default function VelocityShell({ title, section, active, children, panel, canvas = false, hideSidebar = false }: ShellProps) {
    const { props } = usePage<VelocityShared>();
    const [overlay, setOverlay] = useState<Overlay>(null);
    const [toast, setToast] = useState<{ text: string; undo: VelocityShared['flash']['undo'] } | null>(null);
    const { appearance, updateAppearance } = useAppearance();
    const user = props.auth.user;
    const hasSidebar = section === 'tasks' && !hideSidebar;
    const isDark = appearance === 'dark' || (appearance === 'system' && typeof window !== 'undefined' && matchMedia('(prefers-color-scheme: dark)').matches);

    // One-shot messages from the server ("Done: …", with Undo).
    useEffect(() => {
        if (props.flash?.toast) setToast({ text: props.flash.toast, undo: props.flash.undo });
    }, [props.flash]);
    useEffect(() => {
        if (!toast) return;
        const t = setTimeout(() => setToast(null), 5000);
        return () => clearTimeout(t);
    }, [toast]);

    const close = useCallback(() => setOverlay(null), []);
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const mod = e.ctrlKey || e.metaKey;
            if (mod && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setOverlay(e.shiftKey ? 'add' : 'search');
            } else if (e.key === 'Escape' && overlay) {
                setOverlay(null);
            }
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [overlay]);
    useEffect(() => router.on('navigate', () => setOverlay(null)), []);

    const soon = (s: (typeof SECTIONS)[number]) => setToast({ text: `${s.label} gets its own screens in Phase ${s.phase}.`, undo: null });
    const sectionLink = (s: (typeof SECTIONS)[number], cls: string, withLabel = true) =>
        s.href ? (
            <Link key={s.key} href={s.href} className={`${cls}${s.key === section ? ' is-active' : ''}`} aria-current={s.key === section ? 'page' : undefined} title={s.label}>
                <Icon name={s.icon} />
                {withLabel && <span className="label">{s.label}</span>}
            </Link>
        ) : (
            <button key={s.key} type="button" className={cls} title={`${s.label} · Phase ${s.phase}`} onClick={() => soon(s)}>
                <Icon name={s.icon} />
                {withLabel && <span className="label">{s.label}</span>}
            </button>
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
            <DropdownMenuContent className="w-56" align="end">
                <UserMenuContent user={user as unknown as User} />
            </DropdownMenuContent>
        </DropdownMenu>
    );

    return (
        <>
            <Head title={title} />
            <div className={`app${hasSidebar ? '' : ' no-sidebar'}${panel ? ' has-panel' : ''}${canvas ? ' is-canvas' : ''}`}>
                <header className="gtop">
                    {hasSidebar && (
                        <button className="icon-btn gtop-menu" onClick={() => setOverlay('drawer')} aria-label="Open the Tasks menu">
                            <Icon name="menu" />
                        </button>
                    )}
                    <Link href="/home" className="brand">
                        <Logo />
                        <span className="brand-name">Velocity</span>
                    </Link>
                    <nav className="tabs" aria-label="Sections">
                        {SECTIONS.map((s) => sectionLink(s, 'tab-top'))}
                    </nav>
                    <div className="gtop-right">
                        <button className="gsearch" onClick={() => setOverlay('search')} aria-label="Search">
                            <Icon name="search" className="ic-sm" />
                            <span>Search</span>
                            <kbd>Ctrl K</kbd>
                        </button>
                        <button className="sb-new" onClick={() => setOverlay('add')} aria-label="Add something" title="Add (Ctrl Shift K)">
                            <Icon name="plus" />
                        </button>
                        {themeButton}
                        {account}
                    </div>
                </header>

                <header className="topbar">
                    {hasSidebar ? (
                        <button className="topbar-section" onClick={() => setOverlay('drawer')} aria-label="Open the Tasks menu">
                            {SECTIONS.find((s) => s.key === section)?.label}
                            <Icon name="chevD" className="ic-sm" />
                        </button>
                    ) : (
                        <>
                            <Logo />
                            <span className="topbar-title">{SECTIONS.find((s) => s.key === section)?.label ?? title}</span>
                        </>
                    )}
                    <span className="topbar-gap" />
                    <button className="icon-btn" onClick={() => setOverlay('search')} aria-label="Search">
                        <Icon name="search" />
                    </button>
                    {themeButton}
                    {account}
                </header>

                {hasSidebar && (
                    <aside className="sidebar" aria-label="Tasks menu">
                        <TasksMenu active={active} />
                    </aside>
                )}

                <main className="main" id="main">
                    {children}
                </main>

                {panel && (
                    <aside className="panel" aria-label="Task details">
                        {panel}
                    </aside>
                )}

                <nav className="tabbar" aria-label="Sections">
                    {sectionLink(SECTIONS[0], 'tab')}
                    {sectionLink(SECTIONS[1], 'tab')}
                    <button className="tab-add" onClick={() => setOverlay('add')} aria-label="Add something">
                        <Icon name="plus" />
                    </button>
                    {sectionLink(SECTIONS[2], 'tab')}
                    <button className="tab" onClick={() => setOverlay('more')} aria-label="More sections">
                        <Icon name="menu" />
                        <span>More</span>
                    </button>
                </nav>

                {overlay && (
                    <div
                        className={`overlay${overlay === 'drawer' ? ' is-drawer' : ''}`}
                        onClick={(e) => {
                            if (e.target === e.currentTarget) close();
                        }}
                    >
                        {overlay === 'search' && <SearchPalette onClose={close} />}
                        {overlay === 'add' && <QuickAdd onClose={close} projectId={active?.startsWith('project:') ? Number(active.slice(8)) : undefined} />}
                        {overlay === 'drawer' && (
                            <div className="drawer" role="dialog" aria-modal="true" aria-label="Tasks menu">
                                <TasksMenu active={active} />
                            </div>
                        )}
                        {overlay === 'more' && (
                            <div className="dialog more-sheet" role="dialog" aria-modal="true" aria-label="More sections">
                                <p className="more-title">More sections</p>
                                <div className="more-grid">
                                    {SECTIONS.slice(3).map((s) =>
                                        s.href ? (
                                            <Link key={s.key} className="more-item" href={s.href}>
                                                <Icon name={s.icon} />
                                                <span>{s.label}</span>
                                            </Link>
                                        ) : (
                                            <button key={s.key} className="more-item" onClick={() => soon(s)}>
                                                <Icon name={s.icon} />
                                                <span>{s.label}</span>
                                            </button>
                                        ),
                                    )}
                                    <Link className="more-item" href="/weights">
                                        <Icon name="sliders" />
                                        <span>Weights</span>
                                    </Link>
                                    <Link className="more-item" href="/settings/profile">
                                        <Icon name="settings" />
                                        <span>Settings</span>
                                    </Link>
                                </div>
                            </div>
                        )}
                    </div>
                )}

                {toast && (
                    <div className="toast" role="status">
                        <span>{toast.text}</span>
                        {toast.undo && (
                            <button
                                type="button"
                                onClick={() => {
                                    const u = toast.undo!;
                                    setToast(null);
                                    router.post(route(u.action === 'reopen' ? 'tasks.reopen' : 'tasks.restore', u.id), {}, { preserveScroll: true });
                                }}
                            >
                                Undo
                            </button>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

/** The Tasks side menu: only places inside Tasks. */
function TasksMenu({ active }: { active?: string }) {
    const { props } = usePage<VelocityShared>();
    const nav = props.nav;
    if (!nav) return null;
    const item = (key: string, icon: string, label: string, href: string, count?: number | null) => (
        <Link className={`nav-item${active === key ? ' is-active' : ''}`} href={href} aria-current={active === key ? 'page' : undefined}>
            <Icon name={icon} />
            <span className="label">{label}</span>
            {count != null && count > 0 && <span className="count">{count}</span>}
        </Link>
    );
    return (
        <>
            <div className="side-head">
                <span className="side-title">Tasks</span>
            </div>
            <nav className="nav" aria-label="Tasks">
                {item('upnext', 'rank', 'Up next', '/tasks', nav.counts.upNext)}
                {item('waiting', 'hourglass', 'Not ready yet', '/tasks#not-ready', nav.counts.waiting)}
                <div className="nav-label">Projects</div>
                {nav.areas.map((a) => (
                    <div key={a.id}>
                        <div className="nav-area">
                            <Icon name={a.icon ?? 'folder'} />
                            {a.name}
                        </div>
                        {a.projects.map((p) => (
                            <Link
                                key={p.id}
                                href={`/projects/${p.id}`}
                                className={`nav-item nav-proj${active === `project:${p.id}` ? ' is-active' : ''}`}
                                aria-current={active === `project:${p.id}` ? 'page' : undefined}
                            >
                                <span className="label">{p.name}</span>
                                {p.open > 0 && <span className="count">{p.open}</span>}
                            </Link>
                        ))}
                    </div>
                ))}
                <div className="nav-label">More</div>
                {item('goals', 'target', 'Goals map', '/goals', nav.counts.goals)}
                {item('weights', 'sliders', 'Weights & method', '/weights')}
                {item('history', 'review', 'History', '/history')}
            </nav>
        </>
    );
}
