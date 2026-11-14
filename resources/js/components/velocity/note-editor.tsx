import { sendFiles, sendJson } from '@/lib/http';
import type { Clock } from '@/lib/velocity';
import type { TaskFileInfo } from '@/types/velocity';
import { Link, router } from '@inertiajs/react';
import Image from '@tiptap/extension-image';
import { TaskItem, TaskList } from '@tiptap/extension-list';
import { Placeholder } from '@tiptap/extensions';
import { type Editor, EditorContent, type JSONContent, useEditor, useEditorState } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { type DragEvent, type KeyboardEvent, useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Icon } from './icon';
import { Picker } from './pick';
import { Select } from './select';
import { fileSize } from './task-files';

export interface NoteFileInfo extends TaskFileInfo {
    inline: boolean;
}

export interface NoteDetail {
    id: number;
    title: string;
    /** What the note is called when it has no title of its own: the day, or the class and day. */
    label: string;
    doc: JSONContent;
    pinned: boolean;
    projectId: number | null;
    journalDate: string | null;
    event: { id: number; title: string; calendar: 'class' | 'work' | 'personal'; date: string; gone: boolean } | null;
    tasks: { id: number; title: string; status: string; rank: number | null }[];
    files: NoteFileInfo[];
    people: { id: number; name: string }[];
    updatedAt: string | null;
    createdAt: string | null;
}

/** What a save sends back, for the list beside the note. */
export interface Saved {
    id: number;
    title: string;
    preview: string;
    updatedAt: string;
}

type Status = 'idle' | 'typing' | 'saving' | 'saved' | 'error';

const keep = { preserveScroll: true, preserveState: true };
const MAX = 20 * 1024 * 1024;
const PICTURE = /^image\/(png|jpeg|gif|webp)$/;
const CAL_ICON = { class: 'cap', work: 'briefcase', personal: 'heart' } as const;
const at = (d: string) => new Date(`${d}T12:00:00Z`);

/** The open note: title, details row, toolbar, the text (saved as you type) and its files. */
export function NoteEditor({
    note,
    projects,
    taskOptions,
    personOptions,
    clock,
    journalNav,
    onSaved,
    onDiscarded,
    onBack,
}: {
    note: NoteDetail;
    projects: { id: number; name: string }[];
    taskOptions: { id: number; title: string }[];
    personOptions: { id: number; name: string }[];
    clock: Clock;
    journalNav?: { prev?: string; next?: string };
    onSaved: (s: Saved) => void;
    onDiscarded: (id: number) => void;
    onBack: () => void;
}) {
    const [title, setTitle] = useState(note.title);
    const [status, setStatus] = useState<Status>('idle');
    const [error, setError] = useState<string | null>(null);
    const [uploading, setUploading] = useState(false);

    // The latest of everything, for saving from timers, unload and unmount.
    const startsEmpty = !note.doc.content?.some((n) => n.type !== 'paragraph' || (n.content?.length ?? 0) > 0);
    const live = useRef({ title: note.title, doc: note.doc, empty: startsEmpty, dirty: false, files: note.files.length, tasks: note.tasks.length });
    live.current.files = note.files.length;
    live.current.tasks = note.tasks.length;
    const callbacks = useRef({ onSaved, onDiscarded });
    callbacks.current = { onSaved, onDiscarded };
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);
    const inflight = useRef<Promise<void> | null>(null);
    const editorRef = useRef<Editor | null>(null);

    const flush = useCallback(
        async (closing = false) => {
            clearTimeout(timer.current);
            while (inflight.current) await inflight.current; // one save at a time, in order
            if (!live.current.dirty) return;
            live.current.dirty = false;
            setStatus('saving');
            const payload = { title: live.current.title, body: JSON.stringify(live.current.doc) };
            inflight.current = sendJson<Omit<Saved, 'id'>>('PUT', route('notes.save', note.id), payload, closing)
                .then((r) => {
                    if (r) callbacks.current.onSaved({ id: note.id, ...r });
                    if (!live.current.dirty) setStatus('saved');
                })
                .catch(() => {
                    live.current.dirty = true;
                    setStatus('error');
                })
                .finally(() => {
                    inflight.current = null;
                });
            await inflight.current;
        },
        [note.id],
    );
    const changed = useCallback(() => {
        live.current.dirty = true;
        setStatus('typing');
        clearTimeout(timer.current);
        timer.current = setTimeout(() => void flush(), 800);
    }, [flush]);

    // Pictures go into the text where they were pasted or dropped; other files are listed under Files.
    const upload = async (files: File[], pos?: number) => {
        const big = files.find((f) => f.size > MAX);
        if (big) return setError(`“${big.name}” is bigger than 20 MB.`);
        setError(null);
        setUploading(true);
        try {
            const pictures = files.filter((f) => PICTURE.test(f.type));
            const others = files.filter((f) => !PICTURE.test(f.type));
            if (pictures.length && editorRef.current) {
                const r = await sendFiles<{ files: NoteFileInfo[] }>(route('note-files.store', note.id), pictures.slice(0, 10), { inline: '1' });
                const nodes = r.files.map((f) => ({ type: 'image', attrs: { src: f.url, alt: f.name } }));
                const ed = editorRef.current;
                ed.chain()
                    .focus()
                    .insertContentAt(pos ?? ed.state.selection.to, nodes)
                    .run();
            }
            if (others.length) await attach(others);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'That file could not be added.');
        } finally {
            setUploading(false);
        }
    };
    const attach = async (files: File[]) => {
        await sendFiles(route('note-files.store', note.id), files.slice(0, 10), { inline: '0' });
        router.reload({ only: ['note'] });
    };
    const uploadRef = useRef(upload);
    uploadRef.current = upload;

    const editor = useEditor({
        extensions: [
            StarterKit.configure({ heading: { levels: [1, 2, 3] }, link: { openOnClick: false, autolink: true, defaultProtocol: 'https' } }),
            TaskList,
            TaskItem.configure({ nested: true }),
            Placeholder.configure({ placeholder: 'Start writing' }),
            Image.configure({ allowBase64: false }),
        ],
        content: note.doc,
        shouldRerenderOnTransaction: false,
        editorProps: {
            attributes: { class: 'note-body', 'aria-label': 'Note text' },
            handlePaste: (_view, event) => {
                const files = [...(event.clipboardData?.files ?? [])];
                if (!files.length) return false;
                void uploadRef.current(files);
                return true;
            },
            handleDrop: (view, event, _slice, moved) => {
                const files = [...(event.dataTransfer?.files ?? [])];
                if (moved || !files.length) return false;
                event.preventDefault();
                void uploadRef.current(files, view.posAtCoords({ left: event.clientX, top: event.clientY })?.pos);
                return true;
            },
            // Ctrl+click (Cmd on a Mac) opens a link; a plain click just puts the cursor there.
            handleClick: (view, pos, event) => {
                if (!(event.ctrlKey || event.metaKey)) return false;
                const href = view.state.doc
                    .resolve(pos)
                    .marks()
                    .find((m) => m.type.name === 'link')?.attrs.href;
                if (!href) return false;
                window.open(href, '_blank', 'noopener,noreferrer');
                return true;
            },
        },
        onCreate: ({ editor: e }) => {
            live.current.empty = e.isEmpty;
        },
        onUpdate: ({ editor: e }) => {
            live.current.doc = e.getJSON();
            live.current.empty = e.isEmpty;
            changed();
        },
    });
    editorRef.current = editor;

    // Leaving the note (another note, another page): save what is left, and throw it away if nothing was ever put in it.
    useEffect(() => {
        const id = note.id;
        const blank = () => live.current.title.trim() === '' && live.current.empty && live.current.files === 0 && live.current.tasks === 0;
        const onHide = () => {
            void flush(true);
            if (blank()) void sendJson('DELETE', route('notes.discard', id), undefined, true).catch(() => {});
        };
        window.addEventListener('pagehide', onHide);
        return () => {
            window.removeEventListener('pagehide', onHide);
            const wasBlank = blank();
            void flush(true).then(() => {
                if (!wasBlank) return;
                callbacks.current.onDiscarded(id);
                return sendJson('DELETE', route('notes.discard', id)).catch(() => {});
            });
        };
    }, [note.id, flush]);

    // A new, empty note: start in the title. A day's journal entry or a class's notes: start in the text.
    const titleBox = useRef<HTMLTextAreaElement>(null);
    useEffect(() => {
        if (note.title === '' && startsEmpty && !note.journalDate && !note.event) titleBox.current?.focus();
    }, []); // eslint-disable-line react-hooks/exhaustive-deps
    useEffect(() => {
        if (editor && startsEmpty && (note.journalDate || note.event)) editor.commands.focus('end');
    }, [editor]); // eslint-disable-line react-hooks/exhaustive-deps
    useLayoutEffect(() => {
        const t = titleBox.current;
        if (!t) return;
        t.style.height = 'auto';
        t.style.height = `${t.scrollHeight}px`;
    }, [title]);

    const titleKey = (e: KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' || (e.key === 'ArrowDown' && titleBox.current?.selectionStart === title.length)) {
            e.preventDefault();
            if (!editor || editor.isDestroyed) return;
            // Focus right away, so the very next key already lands in the text (the command sets the place a frame later).
            editor.view.focus();
            editor.commands.focus('start');
        }
    };

    const remove = async () => {
        await flush();
        router.delete(route('notes.destroy', note.id), keep);
    };

    const statusText =
        status === 'saving' || status === 'typing'
            ? 'Saving…'
            : status === 'saved'
              ? 'Saved'
              : status === 'error'
                ? null
                : note.updatedAt && note.createdAt !== note.updatedAt
                  ? `Edited ${when(note.updatedAt, clock)}`
                  : '';

    return (
        <article className="note" aria-label={title || note.label}>
            <div className="note-top">
                <button className="btn btn-ghost btn-sm note-back" onClick={onBack}>
                    <Icon name="arrowL" />
                    Notes
                </button>
                <span className="note-status" role="status">
                    {status === 'error' ? (
                        <button className="note-retry" onClick={() => void flush()}>
                            <Icon name="alert" className="ic-xs" />
                            Not saved · Try again
                        </button>
                    ) : (
                        statusText
                    )}
                </span>
                <button
                    className={`icon-btn${note.pinned ? ' is-on' : ''}`}
                    aria-pressed={note.pinned}
                    aria-label={note.pinned ? 'Unpin' : 'Pin'}
                    title={note.pinned ? 'Unpin' : 'Pin'}
                    onClick={() => router.patch(route('notes.update', note.id), { pinned: !note.pinned }, keep)}
                >
                    <Icon name={note.pinned ? 'pinOff' : 'pin'} />
                </button>
                <button className="icon-btn" aria-label="Delete note" title="Delete" onClick={() => void remove()}>
                    <Icon name="trash" />
                </button>
            </div>

            <textarea
                ref={titleBox}
                className="note-title"
                rows={1}
                value={title}
                placeholder={note.label}
                aria-label="Title"
                maxLength={200}
                onChange={(e) => {
                    const v = e.target.value.replace(/\n/g, ' ');
                    setTitle(v);
                    live.current.title = v;
                    changed();
                }}
                onKeyDown={titleKey}
            />

            <div className="note-meta">
                {note.journalDate && (
                    <span className="note-day">
                        <button
                            className="icon-btn"
                            aria-label="Previous entry"
                            disabled={!journalNav?.prev}
                            onClick={() => journalNav?.prev && router.post(route('notes.journal'), { date: journalNav.prev })}
                        >
                            <Icon name="chevL" className="ic-sm" />
                        </button>
                        <span className="chip">
                            <Icon name="journal" />
                            {clock.date(at(note.journalDate), { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' })}
                        </span>
                        <button
                            className="icon-btn"
                            aria-label="Next entry"
                            disabled={!journalNav?.next}
                            onClick={() => journalNav?.next && router.post(route('notes.journal'), { date: journalNav.next })}
                        >
                            <Icon name="chevR" className="ic-sm" />
                        </button>
                    </span>
                )}
                {note.event &&
                    (note.event.gone ? (
                        <span className="chip">
                            <Icon name={CAL_ICON[note.event.calendar]} />
                            {note.event.title} ·{' '}
                            {clock.date(at(note.event.date), { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' })}
                        </span>
                    ) : (
                        <Link className="chip chip-link" href={`/planner?date=${note.event.date}&view=day`}>
                            <Icon name={CAL_ICON[note.event.calendar]} />
                            {note.event.title} ·{' '}
                            {clock.date(at(note.event.date), { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' })}
                        </Link>
                    ))}
                {!note.journalDate && (
                    <span className="note-project">
                        <Icon name="folder" className="ic-sm" />
                        <Select
                            className="note-project-sel"
                            value={String(note.projectId ?? '')}
                            label="Project"
                            onChange={(v) => router.patch(route('notes.update', note.id), { project_id: v ? Number(v) : null }, keep)}
                            options={[{ value: '', label: 'No project' }, ...projects.map((p) => ({ value: String(p.id), label: p.name }))]}
                        />
                    </span>
                )}
                {note.tasks.map((t) => (
                    <span key={t.id} className={`note-task${t.status === 'done' ? ' is-done' : ''}`}>
                        <Link href={`/tasks?task=${t.id}`} className="note-task-open" title="Open task">
                            {t.status === 'done' ? (
                                <Icon name="check" className="ic-xs" />
                            ) : t.rank ? (
                                <b>#{t.rank}</b>
                            ) : (
                                <Icon name="tasks" className="ic-xs" />
                            )}
                            <span>{t.title}</span>
                        </Link>
                        <button
                            className="note-task-x"
                            aria-label={`Unlink “${t.title}”`}
                            onClick={() => router.delete(route('notes.unlink', [note.id, t.id]), keep)}
                        >
                            <Icon name="x" className="ic-xs" />
                        </button>
                    </span>
                ))}
                <Picker
                    label="Link task"
                    find="Find a task"
                    none="No open tasks match."
                    options={taskOptions}
                    taken={note.tasks.map((t) => t.id)}
                    onPick={(id) => router.post(route('notes.link', note.id), { task_id: id }, keep)}
                />
                {note.people.map((p) => (
                    <span key={`p${p.id}`} className="note-task">
                        <Link href={`/people?person=${p.id}`} className="note-task-open" title="Open">
                            <Icon name="person" className="ic-xs" />
                            <span>{p.name}</span>
                        </Link>
                        <button
                            className="note-task-x"
                            aria-label={`Unlink “${p.name}”`}
                            onClick={() => router.post(route('people.unlink', p.id), { type: 'note', id: note.id }, keep)}
                        >
                            <Icon name="x" className="ic-xs" />
                        </button>
                    </span>
                ))}
                <Picker
                    label="Link person"
                    find="Find a person"
                    options={personOptions.map((p) => ({ id: p.id, title: p.name }))}
                    taken={note.people.map((p) => p.id)}
                    onPick={(id) => router.post(route('people.link', id), { type: 'note', id: note.id }, keep)}
                />
            </div>

            {editor && <Toolbar editor={editor} onPicture={(f) => void upload(f)} busy={uploading} />}
            <div className="note-sheet">
                <EditorContent editor={editor} />
                {editor && <MakeTask editor={editor} noteId={note.id} />}
            </div>
            {error && <p className="field-error">{error}</p>}

            <NoteFiles files={note.files} busy={uploading} attach={attach} setError={setError} setBusy={setUploading} />
        </article>
    );
}

function when(iso: string, clock: Clock) {
    const d = clock.dayDiff(iso);
    return d === 0 ? clock.time(iso) : d === -1 ? 'yesterday' : clock.date(iso);
}

/** Text styles and lists. The usual shortcuts work too: Ctrl+B, "# " for a heading, "- " for a list, "[ ] " for a checkbox. */
function Toolbar({ editor, onPicture, busy }: { editor: Editor; onPicture: (files: File[]) => void; busy: boolean }) {
    const s = useEditorState({
        editor,
        selector: ({ editor: e }) => ({
            h2: e.isActive('heading', { level: 2 }),
            h3: e.isActive('heading', { level: 3 }),
            bold: e.isActive('bold'),
            italic: e.isActive('italic'),
            strike: e.isActive('strike'),
            bullet: e.isActive('bulletList'),
            ordered: e.isActive('orderedList'),
            task: e.isActive('taskList'),
            quote: e.isActive('blockquote'),
            code: e.isActive('codeBlock'),
            link: e.isActive('link'),
            href: (e.getAttributes('link').href as string | undefined) ?? '',
        }),
    });
    const [linking, setLinking] = useState(false);
    const picture = useRef<HTMLInputElement>(null);
    const c = () => editor.chain().focus();
    const b = (on: boolean, icon: string, label: string, run: () => void) => (
        <button
            type="button"
            className={`ntb-btn${on ? ' is-on' : ''}`}
            aria-pressed={on}
            aria-label={label}
            title={label}
            onMouseDown={(e) => e.preventDefault()}
            onClick={run}
        >
            <Icon name={icon} />
        </button>
    );
    return (
        <div className="ntb" role="toolbar" aria-label="Text style">
            {b(s.h2, 'h2', 'Heading', () => c().toggleHeading({ level: 2 }).run())}
            {b(s.h3, 'h3', 'Small heading', () => c().toggleHeading({ level: 3 }).run())}
            <i className="ntb-gap" />
            {b(s.bold, 'bold', 'Bold (Ctrl+B)', () => c().toggleBold().run())}
            {b(s.italic, 'italic', 'Italic (Ctrl+I)', () => c().toggleItalic().run())}
            {b(s.strike, 'strike', 'Strikethrough', () => c().toggleStrike().run())}
            <i className="ntb-gap" />
            {b(s.bullet, 'list', 'Bulleted list', () => c().toggleBulletList().run())}
            {b(s.ordered, 'listOl', 'Numbered list', () => c().toggleOrderedList().run())}
            {b(s.task, 'checklist', 'Checklist', () => c().toggleTaskList().run())}
            <i className="ntb-gap" />
            {b(s.quote, 'quote', 'Quote', () => c().toggleBlockquote().run())}
            {b(s.code, 'code', 'Code', () => c().toggleCodeBlock().run())}
            {b(s.link || linking, 'link', 'Link', () => setLinking((v) => !v))}
            <button
                type="button"
                className="ntb-btn"
                aria-label="Add a picture"
                title="Add a picture"
                disabled={busy}
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => picture.current?.click()}
            >
                <Icon name="image" />
            </button>
            <input
                ref={picture}
                type="file"
                accept="image/png,image/jpeg,image/gif,image/webp"
                multiple
                hidden
                aria-label="Add a picture"
                onChange={(e) => {
                    if (e.target.files?.length) onPicture([...e.target.files]);
                    e.target.value = '';
                }}
            />
            {linking && <LinkBox editor={editor} href={s.href} onClose={() => setLinking(false)} />}
        </div>
    );
}

function LinkBox({ editor, href, onClose }: { editor: Editor; href: string; onClose: () => void }) {
    const [url, setUrl] = useState(href);
    const input = useRef<HTMLInputElement>(null);
    useEffect(() => input.current?.focus(), []);
    const apply = () => {
        const v = url.trim();
        if (!v) {
            editor.chain().focus().extendMarkRange('link').unsetLink().run();
            return onClose();
        }
        const full = /^([a-z][a-z0-9+.-]*:|\/|#)/i.test(v) ? v : `https://${v}`;
        const chain = editor.chain().focus().extendMarkRange('link');
        if (editor.state.selection.empty && !editor.isActive('link'))
            chain.insertContent({ type: 'text', text: v, marks: [{ type: 'link', attrs: { href: full } }] }).run();
        else chain.setLink({ href: full }).run();
        onClose();
    };
    return (
        <form
            className="ntb-link"
            onSubmit={(e) => {
                e.preventDefault();
                apply();
            }}
        >
            <input
                ref={input}
                className="input"
                value={url}
                onChange={(e) => setUrl(e.target.value)}
                placeholder="Paste or type a link"
                aria-label="Link address"
                onKeyDown={(e) => e.key === 'Escape' && (e.stopPropagation(), onClose())}
            />
            <button type="submit" className="btn btn-primary btn-sm">
                {href ? 'Change' : 'Add'}
            </button>
            {href && (
                <>
                    <a className="btn btn-ghost btn-sm" href={href} target="_blank" rel="noopener noreferrer">
                        Open
                    </a>
                    <button
                        type="button"
                        className="btn btn-ghost btn-sm"
                        onClick={() => {
                            editor.chain().focus().extendMarkRange('link').unsetLink().run();
                            onClose();
                        }}
                    >
                        <Icon name="unlink" />
                        Remove
                    </button>
                </>
            )}
        </form>
    );
}

/** Select some words: a "Make task" button appears beside them. */
function MakeTask({ editor, noteId }: { editor: Editor; noteId: number }) {
    const sel = useEditorState({
        editor,
        selector: ({ editor: e }) => {
            const { from, to, empty } = e.state.selection;
            const text = empty ? '' : e.state.doc.textBetween(from, to, ' ').trim();
            return { from, to, text, focused: e.isFocused };
        },
    });
    const [busy, setBusy] = useState(false);
    if (!sel.text || !sel.focused) return null;
    const sheet = editor.view.dom.closest('.note-sheet');
    if (!sheet) return null;
    const box = sheet.getBoundingClientRect();
    const end = editor.view.coordsAtPos(sel.to);
    const start = editor.view.coordsAtPos(sel.from);
    const left = Math.min(Math.max(start.left - box.left, 0), box.width - 140);
    return (
        <div className="note-sel" style={{ left, top: end.bottom - box.top + 8 }}>
            <button
                className="btn btn-secondary btn-sm"
                disabled={busy}
                onMouseDown={(e) => e.preventDefault()}
                onClick={() =>
                    router.post(
                        route('notes.make-task', noteId),
                        { title: sel.text.slice(0, 2000) },
                        {
                            ...keep,
                            onStart: () => setBusy(true),
                            onFinish: () => setBusy(false),
                            onSuccess: () => editor.commands.setTextSelection(sel.to),
                        },
                    )
                }
            >
                <Icon name="plus" />
                Make task
            </button>
        </div>
    );
}

/** Attachments: open, download, remove (with Undo). Drop files here or use Attach. */
function NoteFiles({
    files,
    busy,
    attach,
    setError,
    setBusy,
}: {
    files: NoteFileInfo[];
    busy: boolean;
    attach: (files: File[]) => Promise<void>;
    setError: (e: string | null) => void;
    setBusy: (b: boolean) => void;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [over, setOver] = useState(false);
    const add = async (list: FileList | null) => {
        if (!list?.length) return;
        const picked = [...list];
        const big = picked.find((f) => f.size > MAX);
        if (big) return setError(`“${big.name}” is bigger than 20 MB.`);
        setError(null);
        setBusy(true);
        try {
            await attach(picked);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'That file could not be added.');
        } finally {
            setBusy(false);
            if (input.current) input.current.value = '';
        }
    };
    const drag = (e: DragEvent, on: boolean) => {
        if (![...e.dataTransfer.types].includes('Files')) return;
        e.preventDefault();
        setOver(on);
    };
    return (
        <section
            className={`note-files${over ? ' is-over' : ''}`}
            onDragOver={(e) => drag(e, true)}
            onDragEnter={(e) => drag(e, true)}
            onDragLeave={(e) => !e.currentTarget.contains(e.relatedTarget as Node) && setOver(false)}
            onDrop={(e) => {
                if (![...e.dataTransfer.types].includes('Files')) return;
                e.preventDefault();
                setOver(false);
                void add(e.dataTransfer.files);
            }}
            aria-label="Files"
        >
            <div className="note-files-head">
                <h3>Files</h3>
                <button className="btn btn-ghost btn-sm" onClick={() => input.current?.click()} disabled={busy}>
                    <Icon name="paperclip" className="ic-sm" />
                    {busy ? 'Adding…' : 'Attach'}
                </button>
                <input ref={input} type="file" multiple hidden onChange={(e) => void add(e.target.files)} aria-label="Attach files" />
            </div>
            {files.length > 0 && (
                <ul className="p-file-list">
                    {files.map((f) => (
                        <li key={f.id} className="p-file">
                            <a href={f.url} target="_blank" rel="noreferrer" className="p-file-open" title={f.image ? 'Open' : 'Open or download'}>
                                {f.image ? <img src={f.url} alt="" loading="lazy" /> : <Icon name="file" />}
                                <span className="p-file-name">{f.name}</span>
                                <span className="p-file-size">{fileSize(f.size)}</span>
                            </a>
                            <a className="icon-btn" href={`${f.url}?download=1`} aria-label={`Download “${f.name}”`} title="Download">
                                <Icon name="arrowR" className="ic-sm p-file-dl" />
                            </a>
                            <button
                                className="icon-btn"
                                aria-label={`Remove “${f.name}”`}
                                title="Remove"
                                onClick={() => router.delete(route('note-files.destroy', f.id), keep)}
                            >
                                <Icon name="x" className="ic-sm" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
