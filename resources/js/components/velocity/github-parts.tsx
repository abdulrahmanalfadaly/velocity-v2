import { sendJson } from '@/lib/http';
import { Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Icon } from './icon';
import { Picker } from './pick';

export interface GitHubItem {
    repo: string;
    number: number;
    kind: 'issue' | 'pull';
    title: string;
    state: 'open' | 'closed' | 'merged' | 'draft';
    url: string;
}
export type GitHubLinkInfo = GitHubItem & { id: number; fetchedAt: string | null };
export interface TaskGitHubInfo {
    connected: boolean;
    repo: string | null;
    links: GitHubLinkInfo[];
    stale: boolean;
}

const STATE: Record<GitHubItem['state'], string> = { open: 'Open', closed: 'Closed', merged: 'Merged', draft: 'Draft' };
const ICON = (i: GitHubItem) =>
    i.kind === 'issue'
        ? i.state === 'closed'
            ? 'issueDone'
            : 'issue'
        : i.state === 'merged'
          ? 'merged'
          : i.state === 'closed'
            ? 'prClosed'
            : i.state === 'draft'
              ? 'prDraft'
              : 'pr';
const keep = { preserveScroll: true, preserveState: true };
const getJson = async <T,>(url: string): Promise<T> => {
    const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const body = (await res.json().catch(() => null)) as (T & { message?: string }) | null;
    if (!res.ok || !body) throw new Error(body?.message ?? 'GitHub can’t be reached right now.');
    return body;
};

/** "#42 Fix login redirect · Open", opening on GitHub in a new tab. */
function ItemLine({ item }: { item: GitHubItem }) {
    return (
        <a className="gh-item" href={item.url} target="_blank" rel="noopener noreferrer">
            <Icon name={ICON(item)} className={`ic-sm gh-ic is-${item.state}`} />
            <b>#{item.number}</b>
            <span className="gh-title">{item.title}</span>
            <span className="gh-state">{STATE[item.state]}</span>
        </a>
    );
}

/** The task panel's GitHub part: linked issues and pull requests, link or create one. */
export function TaskGitHub({
    taskId,
    title,
    notes,
    done,
    github,
}: {
    taskId: number;
    title: string;
    notes: string | null;
    done: boolean;
    github: TaskGitHubInfo;
}) {
    const [error, setError] = useState<string | null>(null);
    const [checking, setChecking] = useState(false);
    const [mode, setMode] = useState<'none' | 'link' | 'create'>('none');

    // Links a few minutes old are read again in the background; the panel redraws only if something changed.
    useEffect(() => {
        if (!github.connected || !github.stale) return;
        let gone = false;
        setChecking(true);
        sendJson<{ changed: boolean; error: string | null }>('POST', route('tasks.github.refresh', taskId))
            .then((r) => {
                if (gone || !r) return;
                setError(r.error);
                if (r.changed) router.reload({ only: ['selected'] });
            })
            .catch(() => !gone && setError('GitHub can’t be reached right now.'))
            .finally(() => !gone && setChecking(false));
        return () => {
            gone = true;
        };
    }, [taskId, github.connected, github.stale]);

    const finished = github.links.find((l) => l.state === 'closed' || l.state === 'merged');
    return (
        <section className="p-section">
            <div className="p-section-head">
                <h3 className="p-section-title">GitHub</h3>
                {(checking || error) && <span className="card-sub">{checking ? 'Checking GitHub…' : error}</span>}
            </div>
            {finished && !done && (
                <div className="gh-finished">
                    <span>
                        #{finished.number} {finished.state === 'merged' ? 'merged' : 'closed'} on GitHub
                    </span>
                    <button className="btn btn-primary btn-sm" onClick={() => router.post(route('tasks.complete', taskId), {}, keep)}>
                        <Icon name="check" />
                        Mark task done
                    </button>
                </div>
            )}
            {github.links.length > 0 && (
                <ul className="gh-list">
                    {github.links.map((l) => (
                        <li key={l.id} className="gh-row">
                            <ItemLine item={l} />
                            <button
                                className="note-task-x"
                                aria-label={`Unlink #${l.number}`}
                                onClick={() => router.delete(route('github-links.destroy', l.id), keep)}
                            >
                                <Icon name="x" className="ic-xs" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {github.connected ? (
                <>
                    <div className="p-linked">
                        <button className="note-add" aria-expanded={mode === 'link'} onClick={() => setMode(mode === 'link' ? 'none' : 'link')}>
                            <Icon name="plus" className="ic-xs" />
                            Link issue or PR
                        </button>
                        <button className="note-add" aria-expanded={mode === 'create'} onClick={() => setMode(mode === 'create' ? 'none' : 'create')}>
                            <Icon name="github" className="ic-xs" />
                            Create issue
                        </button>
                    </div>
                    {mode === 'link' && <LinkBox taskId={taskId} repo={github.repo} onDone={() => setMode('none')} />}
                    {mode === 'create' && (
                        <CreateIssue taskId={taskId} repo={github.repo} title={title} notes={notes} onDone={() => setMode('none')} />
                    )}
                </>
            ) : (
                <p className="card-sub gh-note">
                    <Link href="/settings/integrations">Connect GitHub</Link> to link and update these.
                </p>
            )}
        </section>
    );
}

/** Paste an address, or type words to find issues and pull requests in the project's repository. */
function LinkBox({ taskId, repo, onDone }: { taskId: number; repo: string | null; onDone: () => void }) {
    const [q, setQ] = useState('');
    const [found, setFound] = useState<GitHubItem[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const isUrl = /^https?:\/\//i.test(q.trim());
    useEffect(() => input.current?.focus(), []);
    useEffect(() => {
        if (isUrl || !repo || q.trim().length < 2) {
            setFound([]);
            return;
        }
        const t = setTimeout(() => {
            getJson<{ items: GitHubItem[] }>(route('tasks.github.search', { task: taskId, q: q.trim() }))
                .then((r) => {
                    setFound(r.items);
                    setError(r.items.length ? null : 'Nothing found.');
                })
                .catch((e: Error) => setError(e.message));
        }, 450);
        return () => clearTimeout(t);
    }, [q, isUrl, repo, taskId]);
    const link = (url: string) => {
        setBusy(true);
        router.post(
            route('tasks.github.link', taskId),
            { url },
            {
                ...keep,
                onSuccess: onDone,
                onError: (e) => setError(e.url ?? 'That couldn’t be linked.'),
                onFinish: () => setBusy(false),
            },
        );
    };
    return (
        <div className="gh-box">
            <input
                ref={input}
                className="input"
                value={q}
                disabled={busy}
                onChange={(e) => {
                    setQ(e.target.value);
                    setError(null);
                }}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' && isUrl) link(q.trim());
                    if (e.key === 'Escape') onDone();
                }}
                placeholder={repo ? `Paste a link, or search ${repo}` : 'Paste the link of an issue or pull request'}
                aria-label="Issue or pull request"
            />
            {isUrl && (
                <button className="btn btn-primary btn-sm" disabled={busy} onClick={() => link(q.trim())}>
                    Link
                </button>
            )}
            {error && <p className="field-error">{error}</p>}
            {found.length > 0 && (
                <ul className="gh-found">
                    {found.map((i) => (
                        <li key={`${i.repo}#${i.number}`}>
                            <button type="button" disabled={busy} onClick={() => link(i.url)}>
                                <Icon name={ICON(i)} className={`ic-sm gh-ic is-${i.state}`} />
                                <b>#{i.number}</b>
                                <span className="gh-title">{i.title}</span>
                                <span className="gh-state">{STATE[i.state]}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/** A new issue for this task: the title filled in; the task's notes go along only when ticked. */
function CreateIssue({
    taskId,
    repo,
    title,
    notes,
    onDone,
}: {
    taskId: number;
    repo: string | null;
    title: string;
    notes: string | null;
    onDone: () => void;
}) {
    const [data, setData] = useState({ repo: repo ?? '', title, withNotes: false });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const submit = () => {
        if (busy) return; // never two issues from one click
        setBusy(true);
        router.post(
            route('tasks.github.issue', taskId),
            { repo: data.repo.trim(), title: data.title.trim(), body: data.withNotes ? notes : null },
            { ...keep, onSuccess: onDone, onError: (e) => setErrors(e), onFinish: () => setBusy(false) },
        );
    };
    return (
        <div className="gh-box gh-create">
            {!repo && (
                <input
                    className="input"
                    value={data.repo}
                    onChange={(e) => setData({ ...data, repo: e.target.value })}
                    placeholder="Repository (owner/name)"
                    aria-label="Repository"
                />
            )}
            {errors.repo && <p className="field-error">{errors.repo}</p>}
            <input
                className="input"
                value={data.title}
                onChange={(e) => setData({ ...data, title: e.target.value })}
                aria-label="Issue title"
                maxLength={256}
            />
            {errors.title && <p className="field-error">{errors.title}</p>}
            {notes && (
                <label className="gh-check">
                    <input type="checkbox" checked={data.withNotes} onChange={(e) => setData({ ...data, withNotes: e.target.checked })} />
                    Include the task’s notes
                </label>
            )}
            <div className="settings-actions">
                <button className="btn btn-primary btn-sm" disabled={busy || !data.title.trim() || !data.repo.trim()} onClick={submit}>
                    {busy ? 'Creating…' : `Create in ${data.repo.trim() || 'GitHub'}`}
                </button>
                <button className="btn btn-ghost btn-sm" onClick={onDone}>
                    Cancel
                </button>
            </div>
        </div>
    );
}

type Issue = GitHubItem & { taskId: number | null };

/** The project's GitHub card: its repository and open issues, each one "Add as task". */
export function ProjectGitHub({ projectId, repo }: { projectId: number; repo: string | null }) {
    const [issues, setIssues] = useState<Issue[] | null>(null);
    const [repos, setRepos] = useState<{ name: string; private: boolean }[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [adding, setAdding] = useState<number | null>(null);

    const load = useCallback(() => {
        if (!repo) return;
        getJson<{ issues: Issue[] }>(route('projects.github.issues', projectId))
            .then((r) => {
                setIssues(r.issues);
                setError(null);
            })
            .catch((e: Error) => setError(e.message));
    }, [projectId, repo]);
    useEffect(() => {
        setIssues(null);
        load();
    }, [load]);
    useEffect(() => {
        if (repo || repos) return;
        getJson<{ repos: { name: string; private: boolean }[] }>(route('github.repos'))
            .then((r) => setRepos(r.repos))
            .catch((e: Error) => setError(e.message));
    }, [repo, repos]);

    const setRepo = (name: string | null) =>
        router.put(route('projects.github', projectId), { repo: name }, { ...keep, onError: (e) => setError(e.repo ?? null) });
    const add = (number: number) => {
        setAdding(number);
        router.post(route('projects.github.task', projectId), { number }, { ...keep, onSuccess: load, onFinish: () => setAdding(null) });
    };

    return (
        <section className="card" aria-labelledby="pgh-title">
            <div className="card-head">
                <h2 className="card-title" id="pgh-title">
                    <Icon name="github" />
                    GitHub
                </h2>
                {repo ? (
                    <span className="gh-repo">
                        <a className="card-sub" href={`https://github.com/${repo}`} target="_blank" rel="noopener noreferrer">
                            {repo}
                        </a>
                        <button className="btn btn-ghost btn-sm" onClick={() => setRepo(null)}>
                            Change
                        </button>
                    </span>
                ) : (
                    <Picker
                        label="Choose repository"
                        find="Find a repository"
                        options={(repos ?? []).map((r, i) => ({ id: i, title: r.name }))}
                        none={repos ? 'No repository matches.' : 'Loading…'}
                        onPick={(i) => repos && setRepo(repos[i].name)}
                    />
                )}
            </div>
            {error && <p className="card-empty">{error}</p>}
            {repo && !error && issues === null && <p className="card-empty">Loading issues…</p>}
            {repo && issues?.length === 0 && <p className="card-empty">No open issues.</p>}
            {repo && issues && issues.length > 0 && (
                <ul className="gh-issues">
                    {issues.map((i) => (
                        <li key={i.number} className="gh-row">
                            <ItemLine item={i} />
                            {i.taskId ? (
                                <Link className="btn btn-ghost btn-sm" href={`/projects/${projectId}?task=${i.taskId}`} preserveScroll>
                                    <Icon name="check" className="ic-sm" />
                                    Task
                                </Link>
                            ) : (
                                <button className="btn btn-secondary btn-sm" disabled={adding !== null} onClick={() => add(i.number)}>
                                    {adding === i.number ? 'Adding…' : 'Add as task'}
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
