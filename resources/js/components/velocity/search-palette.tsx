import { router } from '@inertiajs/react';
import { Fragment, useEffect, useRef, useState } from 'react';
import { Icon } from './icon';

interface Item {
    kind: 'task' | 'project' | 'goal' | 'note' | 'habit' | 'person';
    id: number;
    title: string;
    sub: string;
    url: string;
}

const KIND_ICON = { task: 'tasks', project: 'folder', goal: 'target', note: 'notes', habit: 'habits', person: 'person' } as const;

/** Ctrl K: find tasks, projects, goals and notes. Arrow keys move, Enter opens. */
export function SearchPalette({ onClose }: { onClose: () => void }) {
    const [q, setQ] = useState('');
    const [groups, setGroups] = useState<{ label: string; items: Item[] }[]>([]);
    const [active, setActive] = useState(0);
    const input = useRef<HTMLInputElement>(null);
    const flat = groups.flatMap((g) => g.items);

    useEffect(() => input.current?.focus(), []);
    useEffect(() => {
        if (q.trim().length < 2) {
            setGroups([]);
            return;
        }
        const ctl = new AbortController();
        const timer = setTimeout(() => {
            fetch(route('search', { q }), { signal: ctl.signal, headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : { groups: [] }))
                .then((d) => {
                    setGroups(d.groups);
                    setActive(0);
                })
                .catch(() => {});
        }, 180);
        return () => {
            clearTimeout(timer);
            ctl.abort();
        };
    }, [q]);

    const open = (item?: Item) => {
        if (!item) return;
        onClose();
        router.visit(item.url);
    };
    const mark = (title: string) => {
        const i = title.toLowerCase().indexOf(q.trim().toLowerCase());
        return i < 0 ? (
            title
        ) : (
            <>
                {title.slice(0, i)}
                <mark>{title.slice(i, i + q.trim().length)}</mark>
                {title.slice(i + q.trim().length)}
            </>
        );
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
                    placeholder="Search tasks, projects, notes, habits and people"
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
                {q.trim().length < 2 && <p className="s-group">Type two or more letters</p>}
                {q.trim().length >= 2 && flat.length === 0 && <p className="s-group">Nothing found for “{q}”</p>}
                {groups.map((g) => (
                    <Fragment key={g.label}>
                        <p className="s-group">{g.label}</p>
                        {g.items.map((item) => {
                            n += 1;
                            const index = n;
                            return (
                                <a
                                    key={`${item.kind}${item.id}`}
                                    href={item.url}
                                    className={`s-item${index === active ? ' is-active' : ''}`}
                                    onMouseEnter={() => setActive(index)}
                                    onClick={(e) => {
                                        e.preventDefault();
                                        open(item);
                                    }}
                                >
                                    <span className="s-ic">
                                        <Icon name={KIND_ICON[item.kind]} />
                                    </span>
                                    <div>
                                        <div className="s-title">{mark(item.title)}</div>
                                        <div className="s-sub">{item.sub}</div>
                                    </div>
                                    {index === active ? <kbd>Enter</kbd> : <span />}
                                </a>
                            );
                        })}
                    </Fragment>
                ))}
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
