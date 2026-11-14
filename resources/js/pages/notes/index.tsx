import { Icon } from '@/components/velocity/icon';
import { MonthCalendar } from '@/components/velocity/month-calendar';
import { type NoteDetail, NoteEditor, type Saved } from '@/components/velocity/note-editor';
import VelocityShell from '@/layouts/velocity-shell';
import { Clock } from '@/lib/velocity';
import type { VelocityShared } from '@/types/velocity';
import { Link, router, usePage } from '@inertiajs/react';
import { type CSSProperties, useEffect, useMemo, useRef, useState } from 'react';

interface NoteItem {
    id: number;
    title: string;
    untitled: boolean;
    preview: string;
    pinned: boolean;
    updatedAt: string | null;
    project: { id: number; name: string; color: number | null } | null;
    journalDate: string | null;
    eventDate: string | null;
    calendar: 'class' | 'work' | 'personal' | null;
}

interface Props extends VelocityShared {
    in: string;
    q: string;
    today: string;
    notes: NoteItem[];
    note: NoteDetail | null;
    menu: {
        all: number;
        pinned: number;
        journal: number;
        loose: number;
        projects: { id: number; name: string; count: number }[];
        events: { id: number; title: string; calendar: 'class' | 'work' | 'personal'; count: number }[];
    };
    projects: { id: number; name: string }[];
    taskOptions: { id: number; title: string }[];
    personOptions: { id: number; name: string }[];
    journalDays: string[];
}

const keep = { preserveScroll: true, preserveState: true };
const CAL_ICON = { class: 'cap', work: 'briefcase', personal: 'heart' } as const;
const at = (d: string) => new Date(`${d}T12:00:00Z`);

export default function Notes() {
    const { props } = usePage<Props>();
    const clock = Clock.from(props.clock);
    const view = props.in;
    const [saved, setSaved] = useState<Record<number, Saved>>({});
    const [gone, setGone] = useState<number[]>([]);
    const [q, setQ] = useState(props.q);

    // The list as the server sent it, with what was saved since (the server only sees it on the next visit).
    const items = props.notes
        .filter((n) => !gone.includes(n.id) || n.id === props.note?.id)
        .map((n) => {
            const s = saved[n.id];
            return s && (!n.updatedAt || s.updatedAt >= n.updatedAt)
                ? { ...n, title: s.title, preview: s.preview, updatedAt: s.updatedAt, untitled: false }
                : n;
        });

    const project = /^p(\d+)$/.exec(view)?.[1];
    const event = /^e(\d+)$/.exec(view)?.[1];
    const heading =
        view === 'journal'
            ? 'Journal'
            : view === 'pinned'
              ? 'Pinned'
              : view === 'loose'
                ? 'Not in a project'
                : project
                  ? (props.menu.projects.find((p) => p.id === Number(project))?.name ??
                    props.projects.find((p) => p.id === Number(project))?.name ??
                    'Project')
                  : event
                    ? (props.menu.events.find((e) => e.id === Number(event))?.title ?? 'Class')
                    : 'All notes';

    const params = (extra: Record<string, string | number | undefined>) => ({
        in: view === 'all' ? undefined : view,
        q: q.trim() || undefined,
        ...extra,
    });
    const href = (id: number) => route('notes.index', params({ note: id }));
    const newNote = () => router.post(route('notes.store'), { project_id: project ? Number(project) : null, in: view });
    const writeToday = () => router.post(route('notes.journal'), {});

    // Searching the list: a moment after typing stops.
    const first = useRef(true);
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const t = setTimeout(() => router.get(route('notes.index'), params({ note: props.note?.id }), { ...keep, replace: true }), 260);
        return () => clearTimeout(t);
    }, [q]); // eslint-disable-line react-hooks/exhaustive-deps

    const journalNav = useMemo(() => {
        const d = props.note?.journalDate;
        if (!d || props.journalDays.length === 0) return undefined;
        const before = props.journalDays.filter((x) => x < d);
        return { prev: before[before.length - 1], next: props.journalDays.find((x) => x > d) };
    }, [props.note?.journalDate, props.journalDays]);

    const menu = ({ autoFocus }: { autoFocus: boolean }) => (
        <NotesMenu props={props} clock={clock} view={view} autoFocus={autoFocus} onNew={newNote} onToday={writeToday} />
    );

    return (
        <VelocityShell title={props.note ? props.note.title || props.note.label : 'Notes'} section="notes" active={view} menu={menu}>
            <div className={`notes${props.note ? ' has-note' : ''}`}>
                <section className="notes-list" aria-label={heading}>
                    <div className="notes-head">
                        <h1 className="notes-h">{heading}</h1>
                        <span className="notes-count">{items.length}</span>
                        {view === 'journal' ? (
                            <button className="btn btn-primary btn-sm" onClick={writeToday}>
                                <Icon name="journal" />
                                Today
                            </button>
                        ) : (
                            !event && (
                                <button className="btn btn-primary btn-sm" onClick={newNote}>
                                    <Icon name="plus" />
                                    New note
                                </button>
                            )
                        )}
                    </div>
                    <label className="notes-search">
                        <Icon name="search" className="ic-sm" />
                        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search notes" aria-label="Search notes" type="search" />
                    </label>
                    {items.length === 0 ? (
                        <p className="notes-empty">{q.trim() ? 'No notes match.' : view === 'journal' ? 'No entries yet.' : 'No notes here yet.'}</p>
                    ) : (
                        <ul className="notes-items">
                            {items.map((n) => (
                                <li key={n.id}>
                                    <Link
                                        href={href(n.id)}
                                        preserveState
                                        preserveScroll
                                        className={`ni${n.id === props.note?.id ? ' is-active' : ''}`}
                                        aria-current={n.id === props.note?.id ? 'page' : undefined}
                                    >
                                        <span className="ni-top">
                                            <span className={`ni-title${n.untitled ? ' is-untitled' : ''}`}>{n.title}</span>
                                            {n.pinned && <Icon name="pin" className="ic-xs ni-pin" />}
                                        </span>
                                        {n.preview && <span className="ni-preview">{n.preview}</span>}
                                        <span className="ni-meta">
                                            {n.calendar && <Icon name={CAL_ICON[n.calendar]} className="ic-xs" />}
                                            <span>{itemDate(n, clock)}</span>
                                            {n.project && !project && (
                                                <span className="ni-proj" style={{ '--g': `var(--goal-${n.project.color ?? 0})` } as CSSProperties}>
                                                    {n.project.name}
                                                </span>
                                            )}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="notes-pane">
                    {props.note ? (
                        <NoteEditor
                            key={props.note.id}
                            note={props.note}
                            projects={props.projects}
                            taskOptions={props.taskOptions}
                            personOptions={props.personOptions}
                            clock={clock}
                            journalNav={journalNav}
                            onSaved={(s) => setSaved((m) => ({ ...m, [s.id]: s }))}
                            onDiscarded={(id) => setGone((g) => [...g, id])}
                            onBack={() => router.get(route('notes.index'), params({}), keep)}
                        />
                    ) : (
                        <div className="notes-none">
                            <Icon name={view === 'journal' ? 'journal' : 'notes'} />
                            {view === 'journal' ? (
                                <button className="btn btn-secondary" onClick={writeToday}>
                                    Write today’s entry
                                </button>
                            ) : (
                                <button className="btn btn-secondary" onClick={newNote}>
                                    New note
                                </button>
                            )}
                        </div>
                    )}
                </section>
            </div>
        </VelocityShell>
    );
}

/** A journal entry shows its day; other notes when they were last changed. */
function itemDate(n: NoteItem, clock: Clock) {
    if (n.journalDate) return clock.date(at(n.journalDate), { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' });
    if (n.eventDate) return clock.date(at(n.eventDate), { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' });
    if (!n.updatedAt) return '';
    const d = clock.dayDiff(n.updatedAt);
    return d === 0
        ? clock.time(n.updatedAt)
        : d === -1
          ? 'Yesterday'
          : clock.date(n.updatedAt, d < -300 ? { day: 'numeric', month: 'short', year: 'numeric' } : undefined);
}

function NotesMenu({
    props,
    clock,
    view,
    autoFocus,
    onNew,
    onToday,
}: {
    props: Props;
    clock: Clock;
    view: string;
    autoFocus: boolean;
    onNew: () => void;
    onToday: () => void;
}) {
    const first = useRef<HTMLButtonElement>(null);
    useEffect(() => {
        if (autoFocus) first.current?.focus();
    }, [autoFocus]);
    const [month, setMonth] = useState((props.note?.journalDate ?? props.today).slice(0, 7));
    const days = useMemo(() => new Set(props.journalDays), [props.journalDays]);
    const m = props.menu;
    const item = (key: string, icon: string, label: string, count?: number) => (
        <Link
            key={key}
            className={`nav-item${view === key ? ' is-active' : ''}`}
            href={route('notes.index', key === 'all' ? {} : { in: key })}
            aria-current={view === key ? 'page' : undefined}
        >
            <Icon name={icon} />
            <span className="label">{label}</span>
            {count != null && count > 0 && <span className="count">{count}</span>}
        </Link>
    );
    return (
        <>
            <div className="side-head">
                <span className="side-title">Notes</span>
                <button
                    ref={first}
                    className="icon-btn"
                    onClick={view === 'journal' ? onToday : onNew}
                    aria-label={view === 'journal' ? 'Today’s entry' : 'New note'}
                    title={view === 'journal' ? 'Today’s entry' : 'New note'}
                >
                    <Icon name="plus" />
                </button>
            </div>
            {view === 'journal' && (
                <MonthCalendar
                    month={month}
                    today={props.today}
                    marked={days}
                    chosen={props.note?.journalDate ? [props.note.journalDate] : []}
                    clock={clock}
                    onMonth={setMonth}
                    onPick={(date) => router.post(route('notes.journal'), { date })}
                />
            )}
            <nav className="nav" aria-label="Notes">
                {item('all', 'notes', 'All notes', m.all)}
                {item('pinned', 'pin', 'Pinned', m.pinned)}
                {item('journal', 'journal', 'Journal', m.journal)}
                {item('loose', 'file', 'Not in a project', m.loose)}
                {m.events.length > 0 && <div className="nav-label">Classes and events</div>}
                {m.events.map((e) => item(`e${e.id}`, CAL_ICON[e.calendar], e.title, e.count))}
                {m.projects.length > 0 && <div className="nav-label">Projects</div>}
                {m.projects.map((p) => item(`p${p.id}`, 'folder', p.name, p.count))}
            </nav>
        </>
    );
}
