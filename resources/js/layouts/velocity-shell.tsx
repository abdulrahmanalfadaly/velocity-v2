import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { FocusDone, FocusHabit, FocusTimer } from '@/components/velocity/focus';
import { ProjectDialog } from '@/components/velocity/forms';
import { Pop } from '@/components/velocity/habit-parts';
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
import { type CSSProperties, type ReactNode, useCallback, useEffect, useRef, useState } from 'react';

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
    /** The highlighted place in the Tasks menu: "tasks" (its main pages), "area:3", "area:none", "projects", "project:12". */
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
    const [projectArea, setProjectArea] = useState<number | undefined>(undefined);
    const sideMenu = (autoFocus: boolean) =>
        menu ? (
            menu({ autoFocus })
        ) : (
            <TasksMenu
                active={active}
                onNewProject={(areaId) => {
                    setProjectArea(areaId);
                    setOverlay('project');
                }}
                autoFocus={autoFocus}
            />
        );
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
            } else if (e.key === 'Escape' && onEscape && !document.querySelector('[aria-modal="true"], [role="dialog"], [role="menu"], .sel-pop')) {
                // A popover on top (a day, a catch-up, a picker) closes on its own; the panel under it stays.
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
                data-wall={props.wallpaper?.name}
                style={props.wallpaper?.url ? ({ ['--wall-img' as string]: `url("${props.wallpaper.url}")` } as CSSProperties) : undefined}
            >
                {/* The first Tab on any page: straight to the page itself, past the menus. */}
                <a className="skip-link" href="#main">
                    Skip to content
                </a>
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

                <main className="main" id="main" tabIndex={-1}>
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
                                    <Link className="more-item" href="/settings/profile">
                                        <Icon name="settings" />
                                        <span>Settings</span>
                                    </Link>
                                </div>
                            </div>
                        )}
                    </div>
                )}

                {overlay === 'project' && <ProjectDialog areaId={projectArea} onClose={close} />}

                <Reminders clock={Clock.from(props.clock)} onOpen={openTask} />
                <FocusDone />
                <FocusHabit />
                <FocusTimer variant="float" />
                <Toast toast={toast} onDone={() => setToast(null)} />
            </div>
        </>
    );
}

const AREA_ICONS = ['folder', 'cap', 'briefcase', 'heart', 'home', 'book', 'target', 'flame'];
const keepState = { preserveScroll: true, preserveState: true };

/**
 * An area's header in the Tasks menu: its name opens the area's projects, the arrow folds it (remembered on
 * this device), and a menu renames it, changes its icon, adds a project or removes it.
 */
function AreaHead({
    area,
    active,
    folded,
    onFold,
    onNewProject,
}: {
    area: { id: number; name: string; icon: string | null };
    active: boolean;
    folded: boolean;
    onFold: () => void;
    onNewProject: (areaId: number) => void;
}) {
    const [menu, setMenu] = useState<{ x: number; y: number } | null>(null);
    const [renaming, setRenaming] = useState(false);
    const [name, setName] = useState(area.name);
    const rename = () => {
        setRenaming(false);
        if (name.trim() && name.trim() !== area.name) router.patch(route('areas.update', area.id), { name: name.trim() }, keepState);
        else setName(area.name);
    };
    if (renaming) {
        return (
            <form
                className="nav-area"
                onSubmit={(e) => {
                    e.preventDefault();
                    rename();
                }}
            >
                <Icon name={area.icon ?? 'folder'} />
                <input
                    className="nav-area-input"
                    value={name}
                    maxLength={80}
                    autoFocus
                    aria-label="Area name"
                    onChange={(e) => setName(e.target.value)}
                    onBlur={rename}
                    onKeyDown={(e) => {
                        if (e.key === 'Escape') {
                            e.stopPropagation();
                            setName(area.name);
                            setRenaming(false);
                        }
                    }}
                />
            </form>
        );
    }
    return (
        <div className="nav-area">
            <Link
                href={`/projects?area=${area.id}`}
                className={`nav-area-link${active ? ' is-active' : ''}`}
                aria-current={active ? 'page' : undefined}
                title={`${area.name}: its projects`}
            >
                <Icon name={area.icon ?? 'folder'} />
                <span>{area.name}</span>
            </Link>
            <button
                type="button"
                className="icon-btn nav-area-fold"
                aria-expanded={!folded}
                aria-label={folded ? `Show the projects in ${area.name}` : `Fold ${area.name}`}
                onClick={onFold}
            >
                <Icon name={folded ? 'chevR' : 'chevD'} className="ic-xs" />
            </button>
            <button
                type="button"
                className="icon-btn nav-area-more"
                aria-label={`More for ${area.name}`}
                onClick={(e) => {
                    const r = e.currentTarget.getBoundingClientRect();
                    setMenu({ x: r.left, y: r.bottom + 4 });
                }}
            >
                <Icon name="more" />
            </button>
            {menu && (
                <Pop x={menu.x} y={menu.y} label={area.name} onClose={() => setMenu(null)} width={230}>
                    <button
                        className="bmenu-item"
                        onClick={() => {
                            setMenu(null);
                            setRenaming(true);
                        }}
                    >
                        <Icon name="sliders" />
                        Rename
                    </button>
                    <div className="area-icons" role="radiogroup" aria-label="Icon">
                        {AREA_ICONS.map((i) => (
                            <button
                                key={i}
                                type="button"
                                role="radio"
                                aria-checked={(area.icon ?? 'folder') === i}
                                aria-label={i}
                                onClick={() => {
                                    setMenu(null);
                                    router.patch(route('areas.update', area.id), { icon: i }, keepState);
                                }}
                            >
                                <Icon name={i} />
                            </button>
                        ))}
                    </div>
                    <button
                        className="bmenu-item"
                        onClick={() => {
                            setMenu(null);
                            onNewProject(area.id);
                        }}
                    >
                        <Icon name="plus" />
                        New project here
                    </button>
                    <button
                        className="bmenu-item"
                        onClick={() => {
                            setMenu(null);
                            router.delete(route('areas.destroy', area.id), keepState);
                        }}
                    >
                        <Icon name="trash" />
                        Remove area
                    </button>
                </Pop>
            )}
        </div>
    );
}

/** "New area" in the bar at the bottom of the Tasks menu: the button becomes a name box; Enter adds it. */
function NewArea() {
    const [open, setOpen] = useState(false);
    const [name, setName] = useState('');
    if (!open) {
        return (
            <button type="button" className="side-foot-new" onClick={() => setOpen(true)}>
                <Icon name="plus" />
                <span className="label">New area</span>
            </button>
        );
    }
    return (
        <form
            className="side-foot-form"
            onSubmit={(e) => {
                e.preventDefault();
                if (name.trim()) router.post(route('areas.store'), { name: name.trim() }, keepState);
                setName('');
                setOpen(false);
            }}
        >
            <Icon name="folder" />
            <input
                className="nav-area-input"
                value={name}
                maxLength={80}
                autoFocus
                placeholder="Area name"
                aria-label="New area name"
                onChange={(e) => setName(e.target.value)}
                onBlur={() => !name.trim() && setOpen(false)}
                onKeyDown={(e) => {
                    if (e.key === 'Escape') {
                        e.stopPropagation();
                        setOpen(false);
                    }
                }}
            />
        </form>
    );
}

const STATUS_TAG: Record<string, string> = { planned: 'Planned', on_hold: 'On hold' };

function TasksMenu({ active, onNewProject, autoFocus = false }: { active?: string; onNewProject: (areaId?: number) => void; autoFocus?: boolean }) {
    const { props } = usePage<VelocityShared>();
    const nav = props.nav;
    const list = useRef<HTMLElement>(null);
    useEffect(() => {
        if (autoFocus) list.current?.querySelector<HTMLElement>('a, button')?.focus();
    }, [autoFocus]);
    // Folded areas, remembered on this device.
    const [folded, setFolded] = useState<number[]>(() => {
        try {
            return JSON.parse(localStorage.getItem('velocity.foldedAreas') ?? '[]');
        } catch {
            return [];
        }
    });
    const fold = (id: number) => {
        const next = folded.includes(id) ? folded.filter((x) => x !== id) : [...folded, id];
        setFolded(next);
        localStorage.setItem('velocity.foldedAreas', JSON.stringify(next));
    };
    if (!nav) return null;
    const project = (p: NavProject) => (
        <Link
            key={p.id}
            href={`/projects/${p.id}`}
            className={`nav-item nav-proj${active === `project:${p.id}` ? ' is-active' : ''}${p.status && STATUS_TAG[p.status] ? ' is-held' : ''}`}
            aria-current={active === `project:${p.id}` ? 'page' : undefined}
        >
            <span className="label">{p.name}</span>
            {p.status && STATUS_TAG[p.status] ? (
                <span className="nav-tag">{STATUS_TAG[p.status]}</span>
            ) : (
                p.open > 0 && <span className="count">{p.open}</span>
            )}
        </Link>
    );
    // Projects outside any area: "Other" under the areas, or "Projects" when there are no areas. Its name opens them.
    const looseKey = nav.areas.length ? 'area:none' : 'projects';
    return (
        <div className="side-col">
            <nav ref={list} className="nav nav-areas" aria-label="Areas and projects">
                {nav.areas.map((a) => (
                    <div key={a.id} className="nav-group">
                        <AreaHead
                            area={a}
                            active={active === `area:${a.id}`}
                            folded={folded.includes(a.id)}
                            onFold={() => fold(a.id)}
                            onNewProject={onNewProject}
                        />
                        {!folded.includes(a.id) && a.projects.map(project)}
                    </div>
                ))}
                {(nav.loose.length > 0 || !nav.areas.length) && (
                    <div className="nav-group">
                        <div className="nav-area">
                            <Link
                                href={nav.areas.length ? '/projects?area=none' : '/projects'}
                                className={`nav-area-link${active === looseKey ? ' is-active' : ''}`}
                                aria-current={active === looseKey ? 'page' : undefined}
                            >
                                <Icon name="folder" />
                                <span>{nav.areas.length ? 'Other' : 'Projects'}</span>
                            </Link>
                        </div>
                        {nav.loose.map(project)}
                    </div>
                )}
            </nav>
            {/* Always at the bottom of the menu: a new area, or a new project. */}
            <div className="side-foot">
                <NewArea />
                <button type="button" className="icon-btn" onClick={() => onNewProject()} aria-label="New project" title="New project">
                    <Icon name="folderPlus" />
                </button>
            </div>
        </div>
    );
}
