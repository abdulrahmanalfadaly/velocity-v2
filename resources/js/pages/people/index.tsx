import { Icon } from '@/components/velocity/icon';
import {
    Avatar,
    CATCH_ICON,
    CatchUpPop,
    ContactLine,
    DATE_ICON,
    DateDialog,
    dateLine,
    dayLabel,
    everyText,
    firstName,
    keep,
    nextLine,
    type PersonDate,
    type PersonDetail,
    PersonDialog,
    type PersonRow,
} from '@/components/velocity/people-parts';
import { Picker } from '@/components/velocity/pick';
import VelocityShell from '@/layouts/velocity-shell';
import { Clock } from '@/lib/velocity';
import type { VelocityShared } from '@/types/velocity';
import { Link, router, usePage } from '@inertiajs/react';
import { type FormEvent, useEffect, useRef, useState } from 'react';

interface Props extends VelocityShared {
    view: string;
    today: string;
    people: PersonRow[];
    upcoming: PersonDate[];
    group: { id: number; name: string } | null;
    person: PersonDetail | null;
    menu: { all: number; attention: number; dates: number; archived: number; groups: { id: number; name: string; count: number }[] };
    groups: { id: number; name: string }[];
    taskOptions: { id: number; title: string }[];
    noteOptions: { id: number; title: string }[];
    projects: { id: number; name: string }[];
}

const TITLES: Record<string, string> = { all: 'People', attention: 'Needs attention', dates: 'Birthdays & dates', archived: 'Archived' };

export default function People() {
    const { props } = usePage<Props>();
    const clock = Clock.from(props.clock);
    const view = props.view;
    const [dialog, setDialog] = useState<null | 'new' | 'edit'>(null);
    const [dateDialog, setDateDialog] = useState<null | { date?: PersonDate }>(null);
    const open = (id: number | null) =>
        router.get(route('people.index', { view: view === 'all' ? undefined : view, person: id ?? undefined }), {}, keep);
    const title = props.group?.name ?? TITLES[view] ?? 'People';

    return (
        <VelocityShell
            title={props.person?.name ?? title}
            section="people"
            active={view}
            menu={({ autoFocus }) => <PeopleMenu props={props} autoFocus={autoFocus} onNew={() => setDialog('new')} />}
            panel={
                props.person ? (
                    <PersonPanel
                        key={props.person.id}
                        p={props.person}
                        props={props}
                        clock={clock}
                        onClose={() => open(null)}
                        onEdit={() => setDialog('edit')}
                        onDate={(date) => setDateDialog({ date })}
                    />
                ) : undefined
            }
            onEscape={() => (props.person ? open(null) : undefined)}
        >
            <div className="main-inner pp-page">
                <header className="page-head">
                    <div>
                        <h1 className="h1">
                            {title} <span className="count">{view === 'dates' ? props.upcoming.length : props.people.length}</span>
                        </h1>
                        {view === 'dates' && <p className="page-sub">The next 60 days</p>}
                    </div>
                    <div className="page-actions">
                        {props.group && <GroupTools group={props.group} />}
                        {view !== 'archived' && (
                            <button className="btn btn-primary" onClick={() => setDialog('new')}>
                                <Icon name="plus" />
                                New person
                            </button>
                        )}
                    </div>
                </header>
                {view === 'dates' ? (
                    <DatesList dates={props.upcoming} clock={clock} activeId={props.person?.id} onOpen={open} />
                ) : props.people.length === 0 ? (
                    <section className="card hb-empty">
                        <Icon name="people" />
                        <p>{view === 'attention' ? 'Nobody needs you right now.' : view === 'archived' ? 'Nobody archived.' : 'No one here yet.'}</p>
                        {view === 'all' && (
                            <button className="btn btn-secondary" onClick={() => setDialog('new')}>
                                New person
                            </button>
                        )}
                    </section>
                ) : (
                    <section className="card pp-list">
                        {props.people.map((p) => {
                            const next = nextLine(p, clock);
                            return (
                                <div key={p.id} className={`pp-row${props.person?.id === p.id ? ' is-active' : ''}`}>
                                    <button
                                        className="pp-row-btn"
                                        onClick={() => open(p.id)}
                                        aria-current={props.person?.id === p.id ? 'true' : undefined}
                                    >
                                        <Avatar initials={p.initials} />
                                        <span className="pp-row-main">
                                            <span className="pp-name">{p.name}</span>
                                            <span className="pp-sub">{[p.relationship, p.organisation].filter(Boolean).join(' · ') || ' '}</span>
                                        </span>
                                        {next && (
                                            <span className={`pp-next${next.due ? ' is-due' : ''}`}>
                                                <Icon name={next.icon} className="ic-xs" />
                                                {next.text}
                                            </span>
                                        )}
                                    </button>
                                    {view === 'archived' && (
                                        <button
                                            className="btn btn-secondary btn-sm"
                                            onClick={() => router.post(route('people.unarchive', p.id), {}, keep)}
                                        >
                                            Restore
                                        </button>
                                    )}
                                </div>
                            );
                        })}
                    </section>
                )}
            </div>
            {dialog && (
                <PersonDialog
                    personId={dialog === 'edit' ? props.person?.id : undefined}
                    initial={dialog === 'edit' ? props.person?.form : undefined}
                    groups={props.groups}
                    view={view}
                    onClose={() => setDialog(null)}
                />
            )}
            {dateDialog && props.person && <DateDialog personId={props.person.id} date={dateDialog.date} onClose={() => setDateDialog(null)} />}
        </VelocityShell>
    );
}

/** Birthdays and dates coming round, by day. */
function DatesList({ dates, clock, activeId, onOpen }: { dates: PersonDate[]; clock: Clock; activeId?: number; onOpen: (id: number) => void }) {
    if (dates.length === 0)
        return (
            <section className="card hb-empty">
                <Icon name="gift" />
                <p>Nothing in the next 60 days.</p>
            </section>
        );
    return (
        <section className="card pp-list">
            {dates.map((d) => (
                <div key={d.id} className={`pp-row${activeId === d.personId ? ' is-active' : ''}`}>
                    <button className="pp-row-btn" onClick={() => onOpen(d.personId)}>
                        <span className="pp-day" aria-hidden="true">
                            <b>{dayLabel(d.next!, clock, { day: 'numeric' })}</b>
                            <small>{dayLabel(d.next!, clock, { month: 'short' })}</small>
                        </span>
                        <span className="pp-row-main">
                            <span className="pp-name">
                                <Icon name={DATE_ICON[d.kind]} className="ic-xs" /> {d.title}
                            </span>
                            <span className="pp-sub">
                                {dayLabel(d.next!, clock, { weekday: 'long' })}
                                {d.turns ? (d.kind === 'birthday' ? ` · turns ${d.turns}` : ` · ${d.turns} years`) : ''}
                            </span>
                        </span>
                        <span className={`pp-next${d.days !== null && d.days <= 7 ? ' is-due' : ''}`}>
                            {d.days === 0 ? 'Today' : d.days === 1 ? 'Tomorrow' : `In ${d.days} days`}
                        </span>
                    </button>
                </div>
            ))}
        </section>
    );
}

function GroupTools({ group }: { group: { id: number; name: string } }) {
    const [renaming, setRenaming] = useState(false);
    const [name, setName] = useState(group.name);
    if (renaming)
        return (
            <form
                className="pp-rename"
                onSubmit={(e) => {
                    e.preventDefault();
                    router.patch(route('person-groups.update', group.id), { name }, { ...keep, onSuccess: () => setRenaming(false) });
                }}
            >
                <input className="input" value={name} onChange={(e) => setName(e.target.value)} maxLength={60} aria-label="Group name" autoFocus />
                <button type="submit" className="btn btn-secondary btn-sm">
                    Save
                </button>
            </form>
        );
    return (
        <>
            <button className="icon-btn" aria-label="Rename group" title="Rename" onClick={() => setRenaming(true)}>
                <Icon name="sliders" />
            </button>
            <button
                className="icon-btn"
                aria-label={`Delete the group “${group.name}”`}
                title="Delete group"
                onClick={() => router.delete(route('person-groups.destroy', group.id))}
            >
                <Icon name="trash" />
            </button>
        </>
    );
}

/** The person's panel: how to reach them, keeping in touch, dates, tasks, notes, projects, about. */
function PersonPanel({
    p,
    props,
    clock,
    onClose,
    onEdit,
    onDate,
}: {
    p: PersonDetail;
    props: Props;
    clock: Clock;
    onClose: () => void;
    onEdit: () => void;
    onDate: (d?: PersonDate) => void;
}) {
    const [catchAt, setCatchAt] = useState<null | { x: number; y: number }>(null);
    const [about, setAbout] = useState(p.about ?? '');
    const [newTask, setNewTask] = useState('');
    useEffect(() => setAbout(p.about ?? ''), [p.about]);
    const k = p.keepInTouch;
    const link = (type: 'task' | 'note' | 'project', id: number) => router.post(route('people.link', p.id), { type, id }, keep);
    const unlink = (type: 'task' | 'note' | 'project', id: number) => router.post(route('people.unlink', p.id), { type, id }, keep);
    const waiting = p.tasks.filter((t) => t.status === 'waiting');
    const others = p.tasks.filter((t) => t.status !== 'waiting');
    const late = p.catchUp?.late ?? null;
    const addTask = (e: FormEvent) => {
        e.preventDefault();
        if (!newTask.trim()) return;
        router.post(route('people.new-task', p.id), { title: newTask }, { ...keep, onSuccess: () => setNewTask('') });
    };
    const taskRow = (t: PersonDetail['tasks'][number]) => (
        <li key={t.id} className={`pp-task${t.status === 'done' ? ' is-done' : ''}`}>
            <Link href={`/tasks?task=${t.id}`}>
                {t.status === 'done' ? <Icon name="check" className="ic-xs" /> : t.rank ? <b>#{t.rank}</b> : <Icon name="tasks" className="ic-xs" />}
                <span>{t.title}</span>
            </Link>
            <button className="note-task-x" aria-label={`Unlink “${t.title}”`} onClick={() => unlink('task', t.id)}>
                <Icon name="x" className="ic-xs" />
            </button>
        </li>
    );

    return (
        <>
            <div className="panel-head">
                <div className="crumbs">
                    <span>{p.groups[0]?.name ?? 'People'}</span>
                </div>
                <div className="panel-tools">
                    <button className="icon-btn" aria-label="Edit person" title="Edit" onClick={onEdit}>
                        <Icon name="sliders" />
                    </button>
                    <button
                        className="icon-btn"
                        aria-label={p.archived ? 'Restore' : 'Archive'}
                        title={p.archived ? 'Restore' : 'Archive'}
                        onClick={() => router.post(route(p.archived ? 'people.unarchive' : 'people.archive', p.id), {}, keep)}
                    >
                        <Icon name="archive" />
                    </button>
                    <button
                        className="icon-btn"
                        aria-label="Delete person"
                        title="Delete"
                        onClick={() => router.delete(route('people.destroy', p.id), keep)}
                    >
                        <Icon name="trash" />
                    </button>
                    <button className="icon-btn" onClick={onClose} aria-label="Close">
                        <Icon name="x" />
                    </button>
                </div>
            </div>
            <div className="panel-body">
                <div className="pp-head">
                    <Avatar initials={p.initials} big />
                    <div>
                        <h2 className="p-title">{p.name}</h2>
                        {(p.relationship || p.organisation) && (
                            <p className="hb-p-sub">{[p.relationship, p.organisation].filter(Boolean).join(' · ')}</p>
                        )}
                    </div>
                </div>
                {p.groups.length > 0 && (
                    <div className="pp-chips">
                        {p.groups.map((g) => (
                            <Link key={g.id} className="chip chip-link" href={route('people.index', { view: `g${g.id}` })}>
                                {g.name}
                            </Link>
                        ))}
                    </div>
                )}
                {p.contacts.length > 0 && (
                    <div className="pp-contacts">
                        {p.contacts.map((c, i) => (
                            <ContactLine key={i} c={c} />
                        ))}
                    </div>
                )}

                <section className="p-section">
                    <div className="p-section-head">
                        <h3 className="p-section-title">Keep in touch</h3>
                        <button
                            className="btn btn-secondary btn-sm"
                            onClick={(e) => {
                                const r = e.currentTarget.getBoundingClientRect();
                                setCatchAt({ x: r.right - 300, y: r.bottom + 6 });
                            }}
                        >
                            <Icon name="message" className="ic-sm" />
                            Talked today
                        </button>
                    </div>
                    <p className="pp-touch">
                        {k.every ? (
                            <>
                                {everyText(k.every)}
                                {k.next && (
                                    <span className={late !== null && late >= 0 ? 'is-due' : undefined}>
                                        {' · '}
                                        {late !== null && late > 0
                                            ? `${late} ${late === 1 ? 'day' : 'days'} late`
                                            : late === 0
                                              ? 'catch up today'
                                              : `next ${dayLabel(k.next, clock)}`}
                                    </span>
                                )}
                            </>
                        ) : (
                            <button className="link" onClick={onEdit}>
                                Set how often
                            </button>
                        )}
                        {k.last && ` · last ${dayLabel(k.last, clock)}`}
                    </p>
                    {k.history.length > 0 && (
                        <ul className="pp-history">
                            {k.history.map((h) => (
                                <li key={h.id}>
                                    <Icon name={CATCH_ICON[h.kind]} className="ic-sm" />
                                    <span className="pp-history-day">{dayLabel(h.on, clock)}</span>
                                    <span className="pp-history-note">{h.note}</span>
                                    <button
                                        className="note-task-x"
                                        aria-label={`Remove ${dayLabel(h.on, clock)}`}
                                        onClick={() => router.delete(route('catch-ups.destroy', h.id), keep)}
                                    >
                                        <Icon name="x" className="ic-xs" />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="p-section">
                    <div className="p-section-head">
                        <h3 className="p-section-title">Dates</h3>
                        <button className="btn btn-ghost btn-sm" onClick={() => onDate()}>
                            <Icon name="plus" className="ic-sm" />
                            Add date
                        </button>
                    </div>
                    {p.dates.length === 0 ? (
                        <p className="p-files-empty">No birthday or dates yet.</p>
                    ) : (
                        <ul className="pp-dates">
                            {p.dates.map((d) => (
                                <li key={d.id}>
                                    <button onClick={() => onDate(d)}>
                                        <Icon name={DATE_ICON[d.kind]} className="ic-sm" />
                                        <span className="pp-date-main">
                                            <b>{dateLine(d, clock).split(' · ')[0]}</b>
                                            <small>
                                                {d.day}{' '}
                                                {clock.date(new Date(Date.UTC(2000, d.month - 1, d.day, 12)), { month: 'long', timeZone: 'UTC' })}
                                                {d.year ? ` ${d.year}` : ''}
                                                {' · '}
                                                {dateLine(d, clock).split(' · ').slice(1).join(' · ')}
                                            </small>
                                        </span>
                                        {d.remindDays !== null && <Icon name="bell" className="ic-xs" label="Reminder on" />}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="p-section">
                    <div className="p-section-head">
                        <h3 className="p-section-title">Tasks</h3>
                    </div>
                    {waiting.length > 0 && (
                        <>
                            <p className="pp-sublabel">Waiting on {firstName(p.name)}</p>
                            <ul className="pp-tasks">{waiting.map(taskRow)}</ul>
                        </>
                    )}
                    {others.length > 0 && <ul className="pp-tasks">{others.map(taskRow)}</ul>}
                    <form className="pp-newtask" onSubmit={addTask}>
                        <input
                            className="input"
                            value={newTask}
                            onChange={(e) => setNewTask(e.target.value)}
                            placeholder={`New task for ${firstName(p.name)}`}
                            aria-label={`New task for ${p.name}`}
                            maxLength={255}
                        />
                        <Picker
                            label="Link task"
                            find="Find a task"
                            options={props.taskOptions}
                            taken={p.tasks.map((t) => t.id)}
                            onPick={(id) => link('task', id)}
                        />
                    </form>
                </section>

                <section className="p-section">
                    <div className="p-section-head">
                        <h3 className="p-section-title">Notes</h3>
                    </div>
                    <div className="p-linked">
                        {p.notes.map((n) => (
                            <span key={n.id} className="note-task">
                                <Link className="note-task-open" href={`/notes?note=${n.id}`}>
                                    <Icon name="notes" className="ic-xs" />
                                    <span>{n.title}</span>
                                </Link>
                                <button className="note-task-x" aria-label={`Unlink “${n.title}”`} onClick={() => unlink('note', n.id)}>
                                    <Icon name="x" className="ic-xs" />
                                </button>
                            </span>
                        ))}
                        <button className="note-add" onClick={() => router.post(route('notes.store'), { person_id: p.id })}>
                            <Icon name="plus" className="ic-xs" />
                            New note
                        </button>
                        <Picker
                            label="Link note"
                            find="Find a note"
                            options={props.noteOptions}
                            taken={p.notes.map((n) => n.id)}
                            onPick={(id) => link('note', id)}
                        />
                    </div>
                </section>

                <section className="p-section">
                    <div className="p-section-head">
                        <h3 className="p-section-title">Projects</h3>
                    </div>
                    <div className="p-linked">
                        {p.projects.map((pr) => (
                            <span key={pr.id} className="note-task">
                                <Link className="note-task-open" href={`/projects/${pr.id}`}>
                                    <Icon name="folder" className="ic-xs" />
                                    <span>{pr.name}</span>
                                </Link>
                                <button className="note-task-x" aria-label={`Take out of “${pr.name}”`} onClick={() => unlink('project', pr.id)}>
                                    <Icon name="x" className="ic-xs" />
                                </button>
                            </span>
                        ))}
                        <Picker
                            label="Add to a project"
                            find="Find a project"
                            options={props.projects.map((pr) => ({ id: pr.id, title: pr.name }))}
                            taken={p.projects.map((pr) => pr.id)}
                            onPick={(id) => link('project', id)}
                        />
                    </div>
                </section>

                <section className="p-section">
                    <div className="p-section-head">
                        <h3 className="p-section-title">About</h3>
                    </div>
                    <textarea
                        className="p-notes"
                        aria-label={`About ${p.name}`}
                        placeholder="Things to remember: likes, family, gift ideas…"
                        value={about}
                        maxLength={5000}
                        rows={4}
                        onChange={(e) => setAbout(e.target.value)}
                        onBlur={() => about !== (p.about ?? '') && router.put(route('people.about', p.id), { about: about || null }, keep)}
                    />
                </section>
            </div>
            {catchAt && <CatchUpPop personId={p.id} today={props.today} x={catchAt.x} y={catchAt.y} onClose={() => setCatchAt(null)} />}
        </>
    );
}

function PeopleMenu({ props, autoFocus, onNew }: { props: Props; autoFocus: boolean; onNew: () => void }) {
    const first = useRef<HTMLButtonElement>(null);
    useEffect(() => {
        if (autoFocus) first.current?.focus();
    }, [autoFocus]);
    const m = props.menu;
    const item = (key: string, icon: string, label: string, count?: number) => (
        <Link
            key={key}
            className={`nav-item${props.view === key ? ' is-active' : ''}`}
            href={route('people.index', key === 'all' ? {} : { view: key })}
            aria-current={props.view === key ? 'page' : undefined}
        >
            <Icon name={icon} />
            <span className="label">{label}</span>
            {count != null && count > 0 && <span className="count">{count}</span>}
        </Link>
    );
    return (
        <>
            <div className="side-head">
                <span className="side-title">People</span>
                <button ref={first} className="icon-btn" onClick={onNew} aria-label="New person" title="New person">
                    <Icon name="plus" />
                </button>
            </div>
            <nav className="nav" aria-label="People">
                {item('all', 'people', 'All people', m.all)}
                {item('attention', 'bell', 'Needs attention', m.attention)}
                {item('dates', 'gift', 'Birthdays & dates', m.dates)}
                {m.groups.length > 0 && <div className="nav-label">Groups</div>}
                {m.groups.map((g) => item(`g${g.id}`, 'folder', g.name, g.count))}
                <div className="nav-label">More</div>
                {item('archived', 'archive', 'Archived', m.archived)}
            </nav>
        </>
    );
}
