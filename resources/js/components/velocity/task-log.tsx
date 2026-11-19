import { Clock } from '@/lib/velocity';
import type { TaskFileInfo, TaskLogInfo, VelocityShared } from '@/types/velocity';
import { router, usePage } from '@inertiajs/react';
import { type DragEvent, type RefObject, useEffect, useRef, useState } from 'react';
import { Icon } from './icon';
import { attachFiles, fileSize, hasFiles, pastedName, tooBig } from './task-files';

const keep = { preserveScroll: true, preserveState: true };

/** A text box that grows with what is typed in it. */
function useGrow(ref: RefObject<HTMLTextAreaElement | null>, value: string) {
    useEffect(() => {
        const el = ref.current;
        if (el) {
            el.style.height = 'auto';
            el.style.height = `${el.scrollHeight}px`;
        }
    }, [ref, value]);
}

/** Files dragged onto a box: highlight it while over, hand the files over on drop. */
function dropProps(setOver: (on: boolean) => void, take: (files: File[]) => void) {
    return {
        'data-drop': true,
        onDragOver: (e: DragEvent) => {
            if (!hasFiles(e)) return;
            e.preventDefault();
            setOver(true);
        },
        onDragLeave: (e: DragEvent) => !e.currentTarget.contains(e.relatedTarget as Node) && setOver(false),
        onDrop: (e: DragEvent) => {
            if (!hasFiles(e)) return;
            e.preventDefault();
            setOver(false);
            take([...e.dataTransfer.files]);
        },
    };
}

/** The task's log: entries you write about it, each with files if you want. Newest first. */
export function TaskLog({ taskId, logs }: { taskId: number; logs: TaskLogInfo[] }) {
    const clock = Clock.from(usePage<VelocityShared>().props.clock);
    return (
        <div className="log">
            <LogWrite taskId={taskId} clock={clock} />
            {logs.length > 0 && (
                <ol className="log-list">
                    {logs.map((l) => (
                        <LogEntry key={l.id} log={l} taskId={taskId} clock={clock} />
                    ))}
                </ol>
            )}
        </div>
    );
}

/** A new entry: text, files (picked, dropped on it or pasted into it), then Add (or Enter). */
function LogWrite({ taskId, clock }: { taskId: number; clock: Clock }) {
    const [body, setBody] = useState('');
    const [files, setFiles] = useState<File[]>([]);
    const [busy, setBusy] = useState(false);
    const [over, setOver] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const box = useRef<HTMLTextAreaElement>(null);
    const pick = useRef<HTMLInputElement>(null);
    useGrow(box, body);
    const add = (list: File[]) => {
        const big = tooBig(list);
        setError(big);
        if (!big) setFiles((f) => [...f, ...list].slice(0, 10));
    };
    const ready = !!body.trim() || files.length > 0;
    const save = () => {
        if (busy || !ready) return;
        setBusy(true);
        router.post(
            route('logs.store', taskId),
            { body: body.trim(), files },
            {
                ...keep,
                forceFormData: true,
                onSuccess: () => {
                    setBody('');
                    setFiles([]);
                    setError(null);
                },
                onError: (e) => setError(Object.values(e)[0] ?? 'That entry could not be saved.'),
                onFinish: () => setBusy(false),
            },
        );
    };
    return (
        <div className={`log-write${over ? ' is-over' : ''}`} {...dropProps(setOver, add)}>
            <textarea
                ref={box}
                className="log-input"
                rows={1}
                value={body}
                maxLength={5000}
                placeholder="Write an entry…"
                aria-label="New log entry"
                onChange={(e) => setBody(e.target.value)}
                onPaste={(e) => {
                    const list = [...e.clipboardData.files];
                    if (!list.length || e.clipboardData.types.includes('text/plain')) return;
                    e.preventDefault();
                    add(list.map((f) => pastedName(f, clock)));
                }}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        save();
                    }
                }}
            />
            {files.length > 0 && (
                <div className="log-pending">
                    {files.map((f, i) => (
                        <span key={i} className="p-after-chip">
                            <Icon name="paperclip" className="ic-xs" />
                            <span className="log-pending-name">{f.name}</span>
                            <button type="button" aria-label={`Remove “${f.name}”`} onClick={() => setFiles(files.filter((_, j) => j !== i))}>
                                <Icon name="x" className="ic-xs" />
                            </button>
                        </span>
                    ))}
                </div>
            )}
            <div className="log-write-foot">
                <button type="button" className="icon-btn" aria-label="Attach files" title="Attach files" onClick={() => pick.current?.click()}>
                    <Icon name="paperclip" />
                </button>
                <input
                    ref={pick}
                    type="file"
                    multiple
                    hidden
                    aria-label="Attach files to the entry"
                    onChange={(e) => {
                        add([...(e.target.files ?? [])]);
                        e.target.value = '';
                    }}
                />
                <button type="button" className="btn btn-primary btn-sm" disabled={busy || !ready} onClick={save}>
                    {busy ? 'Adding…' : 'Add'}
                </button>
            </div>
            {error && <p className="field-error">{error}</p>}
        </div>
    );
}

/** One entry: when, what you wrote (click to edit), its files; attach more, edit, delete. Files dropped on it go on it. */
function LogEntry({ log, taskId, clock }: { log: TaskLogInfo; taskId: number; clock: Clock }) {
    const [editing, setEditing] = useState(false);
    const [text, setText] = useState(log.body ?? '');
    const [over, setOver] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const box = useRef<HTMLTextAreaElement>(null);
    const pick = useRef<HTMLInputElement>(null);
    useGrow(box, editing ? text : '');
    const edit = () => {
        setText(log.body ?? '');
        setError(null);
        setEditing(true);
        setTimeout(() => {
            const el = box.current;
            el?.focus();
            el?.setSelectionRange(el.value.length, el.value.length);
        });
    };
    const save = () => {
        const v = text.trim();
        if (v === (log.body ?? '')) return setEditing(false);
        router.patch(
            route('logs.update', log.id),
            { body: v },
            { ...keep, onSuccess: () => setEditing(false), onError: (e) => setError(Object.values(e)[0] ?? 'That could not be saved.') },
        );
    };
    const attach = (list: File[]) => {
        setError(null);
        setBusy(true);
        attachFiles(taskId, list, { logId: log.id, onError: setError, onFinish: () => setBusy(false) });
    };
    const when = log.at ? `${clock.date(log.at, { weekday: 'short', day: 'numeric', month: 'short' })}, ${clock.time(log.at)}` : '';
    return (
        <li className={`log-entry${over ? ' is-over' : ''}`} {...dropProps(setOver, attach)}>
            <div className="log-meta">
                <span className="log-date">
                    {when}
                    {log.edited ? ' · edited' : ''}
                </span>
                <span className="log-tools">
                    <button
                        type="button"
                        className="icon-btn"
                        aria-label="Attach files to this entry"
                        title="Attach files"
                        disabled={busy}
                        onClick={() => pick.current?.click()}
                    >
                        <Icon name="paperclip" className="ic-sm" />
                    </button>
                    <input
                        ref={pick}
                        type="file"
                        multiple
                        hidden
                        aria-label="Attach files to this entry"
                        onChange={(e) => {
                            attach([...(e.target.files ?? [])]);
                            e.target.value = '';
                        }}
                    />
                    <button type="button" className="icon-btn" aria-label="Edit entry" title="Edit" onClick={edit}>
                        <Icon name="pencil" className="ic-sm" />
                    </button>
                    <button
                        type="button"
                        className="icon-btn"
                        aria-label="Delete entry"
                        title="Delete (you can undo)"
                        onClick={() => router.delete(route('logs.destroy', log.id), keep)}
                    >
                        <Icon name="trash" className="ic-sm" />
                    </button>
                </span>
            </div>
            {editing ? (
                <div className="log-edit">
                    <textarea
                        ref={box}
                        className="log-input"
                        rows={1}
                        value={text}
                        maxLength={5000}
                        aria-label="Edit entry"
                        onChange={(e) => setText(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && !e.shiftKey) {
                                e.preventDefault();
                                save();
                            } else if (e.key === 'Escape') {
                                e.stopPropagation(); // closes the edit, not the panel
                                setEditing(false);
                            }
                        }}
                    />
                    <div className="log-write-foot">
                        <button type="button" className="btn btn-ghost btn-sm" onClick={() => setEditing(false)}>
                            Cancel
                        </button>
                        <button type="button" className="btn btn-primary btn-sm" onClick={save}>
                            Save
                        </button>
                    </div>
                </div>
            ) : (
                log.body && (
                    <p className="log-body" onClick={edit}>
                        {log.body}
                    </p>
                )
            )}
            {log.files.length > 0 && (
                <ul className="log-files">
                    {log.files.map((f) => (
                        <LogFile key={f.id} file={f} />
                    ))}
                </ul>
            )}
            {busy && <p className="log-busy">Attaching…</p>}
            {error && <p className="field-error">{error}</p>}
        </li>
    );
}

/** A file on an entry: open it (a picture shows small), or take it off (with Undo). */
function LogFile({ file }: { file: TaskFileInfo }) {
    return (
        <li className="log-file">
            <a href={file.url} target="_blank" rel="noreferrer" className="log-file-open" title={file.image ? 'Open' : 'Open or download'}>
                {file.image ? <img src={file.url} alt="" loading="lazy" /> : <Icon name="file" className="ic-sm" />}
                <span className="log-file-name">{file.name}</span>
                <span className="log-file-size">{fileSize(file.size)}</span>
            </a>
            <button
                type="button"
                aria-label={`Remove “${file.name}”`}
                title="Remove"
                onClick={() => router.delete(route('files.destroy', file.id), keep)}
            >
                <Icon name="x" className="ic-xs" />
            </button>
        </li>
    );
}
