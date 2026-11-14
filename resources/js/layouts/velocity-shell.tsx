import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { FocusDone, FocusTimer } from '@/components/velocity/focus';
import { ProjectDialog } from '@/components/velocity/forms';
import { Icon, Logo } from '@/components/velocity/icon';
import { QuickAdd } from '@/components/velocity/quick-add';
import { Reminders } from '@/components/velocity/reminders';
import { SearchPalette } from '@/components/velocity/search-palette';
import { Toast, useToast } from '@/components/velocity/toast';
import { useAppearance } from '@/hooks/use-appearance';
import { Clock } from '@/lib/velocity';
import type { User } from '@/types';
import type { NavProject, VelocityShared } from '@/types/velocity';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { type ReactNode, useCallback, useEffect, useRef, useState } from 'react';

export type Section = 'home' | 'tasks' | 'planner' | 'notes' | 'habits' | 'people' | 'review' | 'settings' | 'search';

const SECTIONS: { key: Section; label: string; icon: string; href?: string; phase?: number }[] = [
    { key: 'home', label: 'Home', icon: 'home', href: '/home' },
    { key: 'tasks', label: 'Tasks', icon: 'tasks', href: '/tasks' },
    { key: 'planner', label: 'Planner', icon: 'planner', href: '/planner' },
    { key: 'notes', label: 'Notes', icon: 'notes', href: '/notes' },
    { key: 'habits', label: 'Habits', icon: 'habits', href: '/habits' },
    { key: 'people', label: 'People', icon: 'people', href: '/people' },
    { key: 'review', label: 'Review', icon: 'review', href: '/review' },
];

type Overlay = null | 'search' | 'add' | 'drawer' | 'more' | 'project';

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
    /** Escape with nothing else open (and not typing in a field): close the panel. */
    onEscape?: () => void;
    /** The section's own side menu (Tasks has its built-in one). */
    menu?: (opts: { autoFocus: boolean }) => ReactNode;
}

export default function VelocityShell({ title, section, active, children, panel, canvas = false, hideSidebar = false, onEscape, menu }: ShellProps) {
    const { props } = usePage<VelocityShared>();
    const [overlay, setOverlay] = useState<Overlay>(null);
    const [toast, setToast] = useToast();
    const { appearance, updateAppearance } = useAppearance();
    const user = props.auth.user;
    // The section's side menu: a sidebar on wide screens, a drawer on phones and tablets (also on the Goals map).
    const hasMenu = section === 'tasks' || !!menu;
    const hasSidebar = hasMenu && !hideSidebar;
    const sectionLabel = SECTIONS.find((s) => s.key === section)?.label ?? title;
    const sideMenu = (autoFocus: boolean) =>
        menu ? menu({ autoFocus }) : <TasksMenu active={active} onNewProject={() => setOverlay('project')} autoFocus={autoFocus} />;
    const isDark =
        appearance === 'dark' || (appearance === 'system' && typeof window !== 'undefined' && matchMedia('(prefers-color-scheme: dark)').matches);

    const close = useCallback(() => setOverlay(null), []);
    // A reminder's "Open": its panel on Up next (or wherever the task is shown on this page).
    const openTask = useCallback((id: number) => router.visit(`/tasks?task=${id}`, { preserveScroll: true }), []);
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const mod = e.ctrlKey || e.metaKey;
            if (mod && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setOverlay(e.shiftKey ? 'add' : 'search');
            } else if (e.key === 'Escape' && overlay) {
                setOverlay(null);
            } else if (e.key === 'Escape' && onEscape && !document.querySelector('[aria-modal="true"]')) {
                const typing = e.target instanceof Element && e.target.closest('input, textarea, select, [contenteditable="true"]');
                if (!typing) onEscape();
            }
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [overlay, onEscape]);
    useEffect(() => router.on('navigate', () => setOverlay(null)), []);
    // Coming back to this tab after a while (say, after ticking things off on the phone): fetch fresh data quietly.
    useEffect(() => {
        let hiddenAt = 0;
        const onVisibility = () => {
            if (document.hidden) hiddenAt = Date.now();
            else if (hiddenAt && Date.now() - hiddenAt > 20_000) router.reload(); // keeps scroll and typed text
        };
        document.addEventListener('visibilitychange', onVisibility);
        return () => document.removeEventListener('visibilitychange', onVisibility);
    }, []);

    const soon = (s: (typeof SECTIONS)[number]) => setToast({ text: `${s.label} isn’t built yet.`, undo: null });
    const sectionLink = (s: (typeof SECTIONS)[number], cls: string, withLabel = true) =>
        s.href ? (
            <Link
                key={s.key}
                href={s.href}
                className={`${cls}${s.key === section ? ' is-active' : ''}`}
                aria-current={s.key === section ? 'page' : undefined}
                title={s.label}
            >
                <Icon name={s.icon} />
                {withLabel && <span className="label">{s.label}</span>}
            </Link>
        ) : (
            <button key={s.key} type="button" className={cls} title={s.label} onClick={() => soon(s)}>
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
            <div
                className={`app${hasSidebar ? '' : ' no-sidebar'}${panel ? ' has-panel' : ''}${canvas ? ' is-canvas' : ''}${props.focusSession ? ' has-focus' : ''}`}
            >
                <header className="gtop">
                    {hasMenu && (
                        <button className="icon-btn gtop-menu" onClick={() => setOverlay('drawer')} aria-label={`Open the ${sectionLabel} menu`}>
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
                        <FocusTimer variant="chip" />
                        {themeButton}
                        {account}
                    </div>
                </header>

                <header className="topbar">
                    {hasMenu ? (
                        <button className="topbar-section" onClick={() => setOverlay('drawer')} aria-label={`Open the ${sectionLabel} menu`}>
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
                    <aside className="sidebar" aria-label={`${sectionLabel} menu`}>
                        {sideMenu(false)}
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
                        {overlay === 'search' && <SearchPalette onClose={close} onAdd={() => setOverlay('add')} />}
                        {overlay === 'add' && (
                            <QuickAdd onClose={close} projectId={active?.startsWith('project:') ? Number(active.slice(8)) : undefined} />
                        )}
                        {overlay === 'drawer' && (
                            <div className="drawer" role="dialog" aria-modal="true" aria-label={`${sectionLabel} menu`}>
                                {sideMenu(true)}
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

                {overlay === 'project' && <ProjectDialog onClose={close} />}

                <Reminders clock={Clock.from(props.clock)} onOpen={openTask} />
                <FocusDone />
                <FocusTimer variant="float" />
                <Toast toast={toast} onDone={() => setToast(null)} />
            </div>
        </>
    );
}

/** The Tasks side menu: only places inside Tasks. */
function TasksMenu({ active, onNewProject, autoFocus = false }: { active?: string; onNewProject: () => void; autoFocus?: boolean }) {
    const { props } = usePage<VelocityShared>();
    const nav = props.nav;
    const first = useRef<HTMLButtonElement>(null);
    useEffect(() => {
        if (autoFocus) first.current?.focus();
    }, [autoFocus]);
    if (!nav) return null;
    const project = (p: NavProject) => (
        <Link
            key={p.id}
            href={`/projects/${p.id}`}
            className={`nav-item nav-proj${active === `project:${p.id}` ? ' is-active' : ''}`}
            aria-current={active === `project:${p.id}` ? 'page' : undefined}
        >
            <span className="label">{p.name}</span>
            {p.open > 0 && <span className="count">{p.open}</span>}
        </Link>
    );
    const hasProjects = nav.loose.length > 0 || nav.areas.some((a) => a.projects.length > 0);
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
                <button ref={first} className="icon-btn" onClick={onNewProject} aria-label="New project" title="New project">
                    <Icon name="plus" />
                </button>
            </div>
            <nav className="nav" aria-label="Tasks">
                {item('inbox', 'inbox', 'Inbox', '/tasks/inbox', nav.counts.inbox)}
                {item('upnext', 'rank', 'Up next', '/tasks', nav.counts.upNext)}
                {item('waiting', 'hourglass', 'Not ready yet', '/tasks/waiting', nav.counts.waiting)}
                <div className="nav-label">Projects</div>
                {nav.areas
                    .filter((a) => a.projects.length > 0)
                    .map((a) => (
                        <div key={a.id}>
                            <div className="nav-area">
                                <Icon name={a.icon ?? 'folder'} />
                                {a.name}
                            </div>
                            {a.projects.map(project)}
                        </div>
                    ))}
                {nav.loose.length > 0 && (
                    <div>
                        {nav.areas.some((a) => a.projects.length > 0) && (
                            <div className="nav-area">
                                <Icon name="folder" />
                                Other
                            </div>
                        )}
                        {nav.loose.map(project)}
                    </div>
                )}
                {!hasProjects && (
                    <button type="button" className="nav-item" onClick={onNewProject}>
                        <Icon name="plus" />
                        <span className="label">New project</span>
                    </button>
                )}
                <div className="nav-label">More</div>
                {item('goals', 'target', 'Goals map', '/goals', nav.counts.goals)}
                {item('weights', 'sliders', 'Weights', '/weights')}
                {item('history', 'review', 'History', '/review?view=weeks')}
                {item('templates', 'file', 'Templates', '/tasks/templates')}
                {item('archive', 'archive', 'Archive', '/tasks/archive')}
            </nav>
        </>
    );
}
