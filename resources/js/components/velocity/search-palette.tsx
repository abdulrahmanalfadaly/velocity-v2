import type { VelocityShared } from '@/types/velocity';
import { router, usePage } from '@inertiajs/react';
import { Fragment, type ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import { Icon } from './icon';

export type FoundKind = 'task' | 'project' | 'goal' | 'note' | 'habit' | 'person' | 'event';

export interface Found {
    kind: FoundKind;
    id: number;
    title: string;
    sub: string;
    url: string;
    snippet?: string | null;
}

/** A line in the palette: something found, a place to go, or something to do. */
interface Line {
    key: string;
    icon: string;
    title: string;
    sub: string;
    url?: string;
    run?: () => void;
    found?: Found;
}

export const KIND_ICON: Record<FoundKind, string> = {
    task: 'tasks',
    project: 'folder',
    goal: 'target',
    note: 'notes',
    habit: 'habits',
    person: 'person',
    event: 'calendar',
};
const RECENT = 'velocity-recent';
const keep = { preserveScroll: true };

const recent = (): Found[] => {
    try {
        return JSON.parse(localStorage.getItem(RECENT) ?? '[]') as Found[];
    } catch {
        return [];
    }
};
const remember = (f: Found) => {
    const list = [f, ...recent().filter((r) => !(r.kind === f.kind && r.id === f.id))].slice(0, 8);
    localStorage.setItem(RECENT, JSON.stringify(list.map((r) => ({ kind: r.kind, id: r.id, title: r.title, sub: r.sub, url: r.url }))));
};

/** Every word typed starts a word of the line ("pl d" finds "Plan my day"). */
const matches = (q: string, text: string) => {
    const words = text.toLowerCase().split(/[^\p{L}\p{N}#]+/u);
    return q
        .toLowerCase()
        .split(/\s+/)
        .filter(Boolean)
        .every((w) => words.some((x) => x.startsWith(w)));
};

export function highlight(text: string, q: string): ReactNode {
    const i = text.toLowerCase().indexOf(q.trim().toLowerCase());
    if (!q.trim() || i < 0) return text;
    return (
        <>
            {text.slice(0, i)}
            <mark>{text.slice(i, i + q.trim().length)}</mark>
            {text.slice(i + q.trim().length)}
        </>
    );
}

/**
 * Ctrl K: find anything, go anywhere, or do something ("new note", "plan my day",
 * "focus"). With nothing typed it shows what was opened last. Arrow keys move, Enter opens.
 */
export function SearchPalette({ onClose, onAdd }: { onClose: () => void; onAdd: () => void }) {
    const { props } = usePage<VelocityShared>();
    const [q, setQ] = useState('');
    const [groups, setGroups] = useState<{ label: string; items: Found[] }[]>([]);
    const [top, setTop] = useState<{ id: number; title: string } | null>(null);
    const [active, setActive] = useState(0);
    const input = useRef<HTMLInputElement>(null);
    const today = new Date(props.clock.now).toLocaleDateString('en-CA', { timeZone: props.clock.tz });

    useEffect(() => input.current?.focus(), []);
    useEffect(() => {
        const ctl = new AbortController();
        const timer = setTimeout(
            () => {
                fetch(route('search', { q: q.trim().length >= 2 ? q : '' }), { signal: ctl.signal, headers: { Accept: 'application/json' } })
                    .then((r) => (r.ok ? r.json() : { groups: [], top: null }))
                    .then((d) => {
                        setGroups(d.groups);
                        setTop(d.top);
                        setActive(0);
                    })
                    .catch(() => {});
            },
            q ? 180 : 0,
        );
        return () => {
            clearTimeout(timer);
            ctl.abort();
        };
    }, [q]);

    // Things to do and places to go; `words` are extra ways to find each.
    const actions = useMemo(() => {
        const post =
            (name: string, data: Record<string, string | number> = {}) =>
            () =>
                router.post(route(name), data, keep);
        type Action = Line & { words: string; usual?: boolean };
        const list: Action[] = [
            { key: 'a-task', icon: 'plus', title: 'New task', sub: 'Ctrl Shift K', words: 'add create task todo', run: onAdd, usual: true },
            { key: 'a-note', icon: 'notes', title: 'New note', sub: 'Notes', words: 'add create write', run: post('notes.store'), usual: true },
            {
                key: 'a-journal',
                icon: 'journal',
                title: 'Today’s journal entry',
                sub: 'Notes',
                words: 'diary write',
                run: post('notes.journal'),
                usual: true,
            },
            { key: 'a-habit', icon: 'habits', title: 'New habit', sub: 'Habits', words: 'add create', url: '/habits?new=1' },
            { key: 'a-person', icon: 'person', title: 'New person', sub: 'People', words: 'add create contact', url: '/people?new=1' },
            {
                key: 'a-day',
                icon: 'wand',
                title: 'Plan my day',
                sub: 'Planner',
                words: 'book schedule today',
                run: post('planner.plan', { date: today, span: 'day' }),
                usual: true,
            },
            {
                key: 'a-week',
                icon: 'wand',
                title: 'Plan my week',
                sub: 'Planner',
                words: 'book schedule',
                run: post('planner.plan', { date: today, span: 'week' }),
            },
            { key: 'a-review', icon: 'check', title: 'Weekly review', sub: 'Review', words: 'look ahead', url: '/review?view=weekly' },
        ];
        if (top)
            list.splice(1, 0, {
                key: 'a-focus',
                icon: 'play',
                title: 'Start focus on #1',
                sub: top.title,
                words: 'focus timer start work',
                run: post('focus.start', { task_id: top.id, minutes: 50 }),
                usual: true,
            });
        const places: [string, string, string, string][] = [
            ['Home', '/home', 'home', 'today overview'],
            ['Up next', '/tasks', 'rank', 'tasks ranking'],
            ['Inbox', '/tasks/inbox', 'inbox', 'tasks'],
            ['Planner', '/planner', 'planner', 'calendar classes'],
            ['Notes', '/notes', 'notes', ''],
            ['Journal', '/notes?in=journal', 'journal', 'diary'],
            ['Habits', '/habits', 'habits', 'routines'],
            ['People', '/people', 'people', 'birthdays contacts'],
            ['Review', '/review', 'review', 'history charts week month'],
            ['Goals map', '/goals', 'target', 'mind map'],
            ['Weights', '/weights', 'sliders', 'formula'],
            ['Settings', '/settings/profile', 'settings', 'account profile'],
        ];
        return [
            ...list,
            ...places.map(
                ([title, url, icon, words]): Action => ({ key: `g-${url}`, icon, title: `Go to ${title}`, sub: '', url, words: `${title} ${words}` }),
            ),
        ];
    }, [top, today, onAdd]);

    const typed = q.trim().length >= 2;
    const sections: { label: string; lines: Line[] }[] = typed
        ? [
              { label: 'Actions', lines: actions.filter((a) => matches(q, `${a.title} ${a.sub} ${a.words}`)).slice(0, 5) },
              ...groups.map((g) => ({
                  label: g.label,
                  lines: g.items.map((f) => ({ key: `${f.kind}${f.id}`, icon: KIND_ICON[f.kind], title: f.title, sub: f.sub, url: f.url, found: f })),
              })),
              {
                  label: '',
                  lines: [
                      { key: 'all', icon: 'search', title: `See all results for “${q.trim()}”`, sub: '', url: route('search.all', { q: q.trim() }) },
                  ],
              },
          ]
        : [
              {
                  label: 'Recent',
                  lines: recent().map((f) => ({
                      key: `r${f.kind}${f.id}`,
                      icon: KIND_ICON[f.kind] ?? 'file',
                      title: f.title,
                      sub: f.sub,
                      url: f.url,
                      found: f,
                  })),
              },
              { label: 'Actions', lines: actions.filter((a) => a.usual) },
          ];
    const flat = sections.flatMap((s) => s.lines);

    const open = (line?: Line) => {
        if (!line) return;
        onClose();
        if (line.found) remember(line.found);
        if (line.run) line.run();
        else if (line.url) router.visit(line.url);
    };
    let n = -1;

    return (
        <div className="dialog" role="dialog" aria-modal="true" aria-label="Search">
            <div className="s-input">
                <Icon name="search" />
                <input
                    ref={input}
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder="Search, or type what to do"
                    aria-label="Search everything"
                    onKeyDown={(e) => {
                        if (e.key === 'ArrowDown') {
                            e.preventDefault();
                            setActive((a) => Math.min(flat.length - 1, a + 1));
                        } else if (e.key === 'ArrowUp') {
                            e.preventDefault();
                            setActive((a) => Math.max(0, a - 1));
                        } else if (e.key === 'Enter') {
                            e.preventDefault();
                            open(flat[active]);
                        }
                    }}
                />
                <kbd>Esc</kbd>
            </div>
            <div className="s-results">
                {sections
                    .filter((s) => s.lines.length > 0)
                    .map((s) => (
                        <Fragment key={s.label || 'more'}>
                            {s.label && <p className="s-group">{s.label}</p>}
                            {s.lines.map((line) => {
                                n += 1;
                                const index = n;
                                return (
                                    <a
                                        key={line.key}
                                        href={line.url ?? '#'}
                                        className={`s-item${index === active ? ' is-active' : ''}`}
                                        onMouseEnter={() => setActive(index)}
                                        onClick={(e) => {
                                            e.preventDefault();
                                            open(line);
                                        }}
                                    >
                                        <span className="s-ic">
                                            <Icon name={line.icon} />
                                        </span>
                                        <div>
                                            <div className="s-title">{line.found ? highlight(line.title, q) : line.title}</div>
                                            {line.sub && <div className="s-sub">{line.sub}</div>}
                                        </div>
                                        {index === active ? <kbd>Enter</kbd> : <span />}
                                    </a>
                                );
                            })}
                        </Fragment>
                    ))}
                {typed && groups.length === 0 && sections[0].lines.length === 0 && <p className="s-group">Nothing found for “{q}”</p>}
            </div>
            <div className="s-foot">
                <span>
                    <Icon name="updown" className="ic-xs" />
                    Move
                </span>
                <span>
                    <Icon name="enter" className="ic-xs" />
                    Open
                </span>
                <span>
                    <kbd>Esc</kbd>Close
                </span>
            </div>
        </div>
    );
}
