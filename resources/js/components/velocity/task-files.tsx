import { Clock } from '@/lib/velocity';
import type { TaskFileInfo } from '@/types/velocity';
import { Link, router } from '@inertiajs/react';
import { type DragEvent, useRef, useState } from 'react';
import { Icon } from './icon';

const MAX = 20 * 1024 * 1024; // 20 MB, the same limit as the server

/** A drag that carries files (not a task card or text). */
export const hasFiles = (e: { dataTransfer: DataTransfer | null }) => !!e.dataTransfer && [...e.dataTransfer.types].includes('Files');

/** Why these files can't be sent ("bigger than 20 MB"), or null when they can. */
export const tooBig = (list: File[]) => {
    const big = list.find((f) => f.size > MAX);
    return big ? `“${big.name}” is bigger than 20 MB.` : null;
};

/** Attaches files to a task (at most 10 at a time), or to one of its log entries. */
export function attachFiles(taskId: number, list: File[], opts: { logId?: number; onError: (message: string) => void; onFinish?: () => void }) {
    const picked = list.slice(0, 10);
    const big = tooBig(picked);
    if (!picked.length || big) {
        if (big) opts.onError(big);
        opts.onFinish?.();
        return;
    }
    router.post(
        route('files.store', taskId),
        { files: picked, ...(opts.logId ? { log_id: opts.logId } : {}) },
        {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onError: (e) => opts.onError(Object.values(e)[0] ?? 'That file could not be attached.'),
            onFinish: opts.onFinish,
        },
    );
}

export const fileSize = (b: number) => (b < 1024 ? `${b} B` : b < 1024 * 1024 ? `${Math.round(b / 1024)} KB` : `${(b / 1024 / 1024).toFixed(1)} MB`);

/** The task panel's Files: attach (button, drag in, or paste; the panel takes drops and pastes anywhere), open, download, remove. */
export function TaskFiles({ taskId, files }: { taskId: number; files: TaskFileInfo[] }) {
    return <FileBox storeUrl={route('files.store', taskId)} removeRoute="files.destroy" files={files} paste hint={false} />;
}

/** A pasted screenshot is called "image.png"; give it a name that says when (in the user's own time). */
export function pastedName(f: File, clock: Clock): File {
    if (f.name && f.name !== 'image.png') return f;
    const stamp = clock.localInput(new Date().toISOString()).replace('T', ' ').replace(':', '.');
    return new File([f], `Pasted ${stamp}.${f.type.split('/')[1] || 'png'}`, { type: f.type });
}

/**
 * Files on a task or a project: attach (button or drag in; on a task, also paste), open, download,
 * remove. On a project's Files tab the files on its tasks show too, each with its task.
 */
export function FileBox({
    storeUrl,
    removeRoute,
    files,
    paste = false,
    title = 'Files',
    hint = true,
}: {
    storeUrl: string;
    removeRoute: string;
    files: TaskFileInfo[];
    paste?: boolean;
    title?: string;
    /** "Drop files here…" while there are none (the task panel goes without: drops work anywhere there). */
    hint?: boolean;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [over, setOver] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const upload = (list: FileList | File[] | null) => {
        if (!list?.length) return;
        const picked = [...list].slice(0, 10);
        const big = picked.find((f) => f.size > MAX);
        if (big) {
            setError(`“${big.name}” is bigger than 20 MB.`);
            return;
        }
        setError(null);
        setBusy(true);
        router.post(
            storeUrl,
            { files: picked },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                onError: (e) => setError(Object.values(e)[0] ?? 'That file could not be attached.'),
                onFinish: () => {
                    setBusy(false);
                    if (input.current) input.current.value = '';
                },
            },
        );
    };
    const drag = (e: DragEvent, on: boolean) => {
        if (![...e.dataTransfer.types].includes('Files')) return; // a task card, not a file
        e.preventDefault();
        setOver(on);
    };

    return (
        <section
            data-drop
            className={`p-section p-files${over ? ' is-over' : ''}`}
            onDragOver={(e) => drag(e, true)}
            onDragEnter={(e) => drag(e, true)}
            onDragLeave={(e) => !e.currentTarget.contains(e.relatedTarget as Node) && setOver(false)}
            onDrop={(e) => {
                if (![...e.dataTransfer.types].includes('Files')) return;
                e.preventDefault();
                setOver(false);
                upload(e.dataTransfer.files);
            }}
        >
            <div className="p-section-head">
                <h3 className="p-section-title">{title}</h3>
                <button className="btn btn-ghost btn-sm" onClick={() => input.current?.click()} disabled={busy}>
                    <Icon name="paperclip" className="ic-sm" />
                    {busy ? 'Attaching…' : 'Attach'}
                </button>
                <input ref={input} type="file" multiple hidden onChange={(e) => upload(e.target.files)} aria-label="Attach files" />
            </div>
            {files.length > 0 ? (
                <ul className="p-file-list">
                    {files.map((f) => (
                        <li key={f.id} className="p-file">
                            <a href={f.url} target="_blank" rel="noreferrer" className="p-file-open" title={f.image ? 'Open' : 'Open or download'}>
                                {f.image ? <img src={f.url} alt="" loading="lazy" /> : <Icon name="file" />}
                                <span className="p-file-name">{f.name}</span>
                                <span className="p-file-size">{fileSize(f.size)}</span>
                            </a>
                            {f.task && (
                                <Link className="p-file-task" href={`?task=${f.task.id}`} preserveScroll preserveState title="Open the task">
                                    {f.task.title}
                                </Link>
                            )}
                            <a className="icon-btn" href={`${f.url}?download=1`} aria-label={`Download “${f.name}”`} title="Download">
                                <Icon name="arrowR" className="ic-sm p-file-dl" />
                            </a>
                            <button
                                className="icon-btn"
                                aria-label={`Remove “${f.name}”`}
                                title="Remove"
                                onClick={() =>
                                    router.delete(route(f.task ? 'files.destroy' : removeRoute, f.id), { preserveScroll: true, preserveState: true })
                                }
                            >
                                <Icon name="x" className="ic-sm" />
                            </button>
                        </li>
                    ))}
                </ul>
            ) : (
                hint && <p className="p-files-empty">Drop files here{paste ? ', paste them' : ''}, or use Attach. Up to 20 MB each.</p>
            )}
            {error && <p className="field-error">{error}</p>}
        </section>
    );
}
