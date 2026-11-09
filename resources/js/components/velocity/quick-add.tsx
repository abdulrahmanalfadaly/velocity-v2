import { Clock, dueInfo, fmtHours } from '@/lib/velocity';
import type { CriterionKey, VelocityShared } from '@/types/velocity';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Icon } from './icon';
import { Dots } from './ui';

interface Preview {
    parsed: {
        title: string;
        due: string | null;
        hours: number | null;
        project: { id: number; name: string } | null;
        picked: { kind: string; text: string }[];
    };
    preview: { rank: number; total: number; score: number; contrib: Record<CriterionKey, number>; above: string | null } | null;
}

/** Add a task from anywhere (Ctrl Shift K). Dates, hours and #project are picked out of what you type. */
export function QuickAdd({ onClose, projectId }: { onClose: () => void; projectId?: number }) {
    const { props } = usePage<VelocityShared>();
    const clock = Clock.from(props.clock);
    const [text, setText] = useState('');
    const [importance, setImportance] = useState(3);
    const [urgency, setUrgency] = useState(3);
    const [data, setData] = useState<Preview | null>(null);
    const [saving, setSaving] = useState(false);
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => input.current?.focus(), []);
    useEffect(() => {
        if (text.trim().length < 2) {
            setData(null);
            return;
        }
        const ctl = new AbortController();
        const timer = setTimeout(() => {
            fetch(route('tasks.preview'), {
                method: 'POST',
                signal: ctl.signal,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
                },
                body: JSON.stringify({ text, importance, urgency }),
            })
                .then((r) => (r.ok ? r.json() : null))
                .then((d) => d && setData(d))
                .catch(() => {});
        }, 220);
        return () => {
            clearTimeout(timer);
            ctl.abort();
        };
    }, [text, importance, urgency]);

    const submit = () => {
        if (!text.trim() || saving) return;
        setSaving(true);
        router.post(
            route('tasks.store'),
            { text, importance, urgency, project_id: data?.parsed.project?.id ?? projectId ?? null },
            { preserveScroll: true, onFinish: () => setSaving(false), onSuccess: onClose },
        );
    };

    const p = data?.parsed;
    const due = p?.due ? dueInfo({ dueAt: p.due }, clock) : null;

    return (
        <div className="dialog" role="dialog" aria-modal="true" aria-label="Add a task">
            <div className="qa-tabs" role="tablist">
                <button className="qa-tab" role="tab" aria-selected="true">
                    <Icon name="tasks" />
                    Task
                </button>
                <button className="qa-tab" role="tab" aria-selected="false" disabled>
                    <Icon name="notes" />
                    Note
                </button>
                <button className="qa-tab" role="tab" aria-selected="false" disabled>
                    <Icon name="planner" />
                    Event
                </button>
            </div>
            <form
                className="qa-input"
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
            >
                <input
                    ref={input}
                    value={text}
                    onChange={(e) => setText(e.target.value)}
                    placeholder="What do you need to do?"
                    aria-label="What do you need to do?"
                />
            </form>
            {p && (p.due || p.hours || p.project) ? (
                <div className="qa-chips">
                    {due && (
                        <span className="qa-chip">
                            <Icon name="calendar" />
                            Due {due.long.replace('Today · ', 'today, ').replace('Tomorrow · ', 'tomorrow, ')}
                        </span>
                    )}
                    {p.hours ? (
                        <span className="qa-chip">
                            <Icon name="clock" />
                            {fmtHours(p.hours)} of work
                        </span>
                    ) : null}
                    <span className="qa-chip is-plain">
                        <Icon name={p.project ? 'folder' : 'inbox'} />
                        {p.project ? p.project.name : 'Inbox'}
                    </span>
                </div>
            ) : null}
            <div className="qa-props">
                <span className="qa-prop">
                    Importance <Dots n={importance} c="I" onPick={setImportance} />
                </span>
                <span className="qa-prop">
                    Urgency <Dots n={urgency} c="U" onPick={setUrgency} />
                </span>
            </div>
            {data?.preview && (
                <div className="qa-preview">
                    <span>
                        Lands at{' '}
                        <b>
                            #{data.preview.rank} of {data.preview.total}
                        </b>
                    </span>
                </div>
            )}
            <div className="qa-foot">
                <span />
                <button className="btn btn-primary" onClick={submit} disabled={!text.trim() || saving}>
                    Add task<kbd>Enter</kbd>
                </button>
            </div>
        </div>
    );
}
