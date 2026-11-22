import { Clock, dueInfo, fmtHours, goalVar } from '@/lib/velocity';
import type { BaseTask, NotReadyTask, RankedTask } from '@/types/velocity';
import { router } from '@inertiajs/react';
import { type DragEvent, useState } from 'react';
import { Icon } from './icon';

/*
 * Another way to look at the same tasks: a board, one column per status. Inside every
 * column the tasks keep the formula's order; dragging a card changes its status, never
 * its rank. (The Timeline is in timeline.tsx.)
 */

type Item = (RankedTask & { ready: true }) | (NotReadyTask & { ready: false }) | (BaseTask & { ready: false; doneToday: true });
type Props = {
    tasks: RankedTask[];
    notReady: NotReadyTask[];
    doneToday: BaseTask[];
    clock: Clock;
    selectedId: number | null;
    onOpen: (id: number) => void;
};

const keep = { preserveScroll: true, preserveState: true };
const KIND_ICON: Record<string, string> = { waiting: 'hourglass', blocked: 'lock', later: 'calendar' };

function items({ tasks, notReady }: Pick<Props, 'tasks' | 'notReady'>): Item[] {
    return [...tasks.map((t) => ({ ...t, ready: true as const })), ...notReady.map((t) => ({ ...t, ready: false as const }))];
}

/** One task as a card: rank (or why it can't start yet), title, project, due date and work. */
function Card({
    t,
    clock,
    selected,
    onOpen,
    onDrag,
}: {
    t: Item;
    clock: Clock;
    selected: boolean;
    onOpen: () => void;
    onDrag: (id: number) => void;
}) {
    const due = dueInfo(t, clock);
    const done = 'doneToday' in t;
    return (
        <div
            className={`vcard${selected ? ' is-selected' : ''}${t.ready ? '' : ' is-quiet'}${done ? ' is-done' : ''}`}
            draggable
            tabIndex={0}
            role="button"
            aria-label={`${t.ready ? `#${t.rank}, ` : ''}${t.title}`}
            style={{ ['--g' as string]: goalVar(t.goal?.color ?? t.project?.goalColor) }}
            onDragStart={(e) => {
                e.dataTransfer.setData('text/plain', String(t.id));
                e.dataTransfer.effectAllowed = 'move';
                onDrag(t.id);
            }}
            onClick={onOpen}
            onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), onOpen())}
        >
            <div className="vcard-top">
                {t.ready ? (
                    <span className="rank-badge">{t.rank}</span>
                ) : done ? (
                    <span className="att-ic">
                        <Icon name="check" />
                    </span>
                ) : (
                    <span className="att-ic" title={'why' in t ? t.why : undefined}>
                        <Icon name={KIND_ICON['kind' in t ? t.kind : ''] ?? 'info'} />
                    </span>
                )}
                <span className="vcard-title">{t.title}</span>
            </div>
            <div className="vcard-meta">
                {t.project && (
                    <span className="vcard-project">
                        <i />
                        {t.project.name}
                    </span>
                )}
                {!done && t.dueAt && <span className={due.late ? 'is-late' : ''}>{due.text}</span>}
                <span>{fmtHours(t.estHours)}</span>
                {t.fileCount ? (
                    <span className="is-files" title={`${t.fileCount} ${t.fileCount === 1 ? 'file' : 'files'}`}>
                        <Icon name="paperclip" className="ic-xs" />
                        {t.fileCount}
                    </span>
                ) : null}
            </div>
        </div>
    );
}

/** A column that cards can be dropped on. */
function Column({
    id,
    title,
    sub,
    over,
    setOver,
    onDrop,
    children,
    empty,
    accepts = true,
    className = '',
}: {
    id: string;
    title: string;
    sub?: string;
    over: string | null;
    setOver: (id: string | null) => void;
    onDrop: () => void;
    children: React.ReactNode;
    empty: string;
    accepts?: boolean;
    className?: string;
}) {
    const allow = (e: DragEvent) => {
        if (!accepts) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (over !== id) setOver(id);
    };
    return (
        <section
            className={`vcol ${className}${over === id ? ' is-over' : ''}`}
            aria-label={title}
            onDragOver={allow}
            onDragEnter={allow}
            onDragLeave={(e) => !e.currentTarget.contains(e.relatedTarget as Node) && setOver(null)}
            onDrop={(e) => {
                e.preventDefault();
                setOver(null);
                if (accepts) onDrop();
            }}
        >
            <header className="vcol-head">
                <b>{title}</b>
                {sub && <span>{sub}</span>}
            </header>
            <div className="vcol-body">{children ?? null}</div>
            {!children || (Array.isArray(children) && children.length === 0) ? <p className="vcol-empty">{empty}</p> : null}
        </section>
    );
}

const hours = (list: { estHours: number }[]) => {
    const h = list.reduce((s, t) => s + t.estHours, 0);
    return h ? fmtHours(h) : '';
};

/** Columns by status: To do, Doing, Waiting, Done today. Drop a card on a column to change its status. */
export function Board(props: Props) {
    const [drag, setDrag] = useState<number | null>(null);
    const [over, setOver] = useState<string | null>(null);
    const all = items(props);
    const doneToday: Item[] = props.doneToday.map((t) => ({ ...t, ready: false as const, doneToday: true as const }));
    const cols: [string, string, Item[]][] = [
        ['todo', 'To do', all.filter((t) => t.status === 'todo')],
        ['doing', 'Doing', all.filter((t) => t.status === 'doing')],
        ['waiting', 'Waiting', all.filter((t) => t.status === 'waiting')],
        ['done', 'Done today', doneToday],
    ];
    const find = (id: number | null) => [...all, ...doneToday].find((t) => t.id === id);

    const dropOn = (col: string) => {
        const t = find(drag);
        setDrag(null);
        if (!t) return;
        const isDone = 'doneToday' in t;
        if (col === 'done') {
            if (!isDone) router.post(route('tasks.complete', t.id), {}, keep);
        } else if (isDone) {
            router.post(route('tasks.reopen', t.id), { status: col }, keep); // open again, in the column it was dropped on
        } else if (t.status !== col) {
            router.patch(route('tasks.update', t.id), { status: col }, keep);
        }
    };

    return (
        <div className="vboard">
            {cols.map(([id, title, list]) => (
                <Column
                    key={id}
                    id={id}
                    title={title}
                    sub={`${list.length}${hours(list) ? ` · ${hours(list)}` : ''}`}
                    over={over}
                    setOver={setOver}
                    onDrop={() => dropOn(id)}
                    empty={id === 'done' ? 'Nothing done yet today.' : 'Nothing here.'}
                    className={`is-${id}`}
                >
                    {list.map((t) => (
                        <Card
                            key={t.id}
                            t={t}
                            clock={props.clock}
                            selected={props.selectedId === t.id}
                            onOpen={() => props.onOpen(t.id)}
                            onDrag={setDrag}
                        />
                    ))}
                </Column>
            ))}
        </div>
    );
}
