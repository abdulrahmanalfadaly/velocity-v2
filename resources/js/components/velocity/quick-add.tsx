import { Clock, dueInfo, fmtHours, repeatText } from '@/lib/velocity';
import type { CriterionKey, RepeatRule, VelocityShared } from '@/types/velocity';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { DatePick } from './date-pick';
import { CALENDARS, type CalendarKey } from './event-dialog';
import { Icon } from './icon';
import { Dots } from './ui';

interface Preview {
    parsed: {
        title: string;
        due: string | null;
        hours: number | null;
        project: { id: number; name: string } | null;
        repeat: RepeatRule | null;
        picked: { kind: string; text: string }[];
    };
    preview: { rank: number; total: number; score: number; contrib: Record<CriterionKey, number>; above: string | null } | null;
}

/** What the server read out of an event line ("Dentist tomorrow 3-4pm #personal"). */
interface EventParse {
    title: string;
    date: string;
    start: string | null;
    end: string | null;
    allDay: boolean;
    calendar: CalendarKey | null;
    weekly: boolean;
}

interface EventFields {
    date: string;
    all_day: boolean;
    start_time: string;
    end_time: string;
    calendar: CalendarKey;
    repeat_weekly: boolean;
}

type Mode = 'task' | 'event' | 'person';
type Errors = Record<string, string>;

const xsrf = () => decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');

/** POST JSON and read JSON back (the live previews). */
function postJson<T>(name: string, body: object, signal?: AbortSignal): Promise<T | null> {
    return fetch(route(name), {
        method: 'POST',
        signal,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: JSON.stringify(body),
    })
        .then((r) => (r.ok ? (r.json() as Promise<T>) : null))
        .catch(() => null);
}

const PLACEHOLDER: Record<Mode, string> = {
    task: 'What do you need to do?',
    event: 'What’s happening, and when?',
    person: 'Their name',
};

/** Add a task, an event or a person from anywhere (Ctrl Shift K). Dates, times, hours and #project are picked out of what you type. */
export function QuickAdd({ onClose, projectId }: { onClose: () => void; projectId?: number }) {
    const { props } = usePage<VelocityShared>();
    const clock = Clock.from(props.clock);
    const today = clock.localInput(clock.now.toISOString()).slice(0, 10);
    const [mode, setMode] = useState<Mode>('task');
    const [text, setText] = useState('');
    const [importance, setImportance] = useState(3);
    const [urgency, setUrgency] = useState(3);
    const [data, setData] = useState<Preview | null>(null);
    const [ev, setEv] = useState<EventFields>({
        date: today,
        all_day: true,
        start_time: '',
        end_time: '',
        calendar: 'personal',
        repeat_weekly: false,
    });
    const [evParsed, setEvParsed] = useState<{ text: string; parse: EventParse } | null>(null);
    const touched = useRef<Set<'date' | 'time' | 'calendar' | 'weekly'>>(new Set());
    const [who, setWho] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [saving, setSaving] = useState(false);
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => input.current?.focus(), [mode]);

    // Task: where it lands, live.
    useEffect(() => {
        if (mode !== 'task' || text.trim().length < 2) {
            setData(null);
            return;
        }
        const ctl = new AbortController();
        const timer = setTimeout(
            () => postJson<Preview>('tasks.preview', { text, importance, urgency }, ctl.signal).then((d) => d && setData(d)),
            220,
        );
        return () => {
            clearTimeout(timer);
            ctl.abort();
        };
    }, [mode, text, importance, urgency]);

    // Event: the typed day, times and calendar fill the fields (unless you set them yourself).
    const applyEvent = (t: string, parse: EventParse) => {
        setEvParsed({ text: t, parse });
        const own = touched.current;
        setEv((f) => ({
            ...f,
            ...(own.has('date') ? {} : { date: parse.date }),
            ...(own.has('time') ? {} : { all_day: parse.allDay, start_time: parse.start ?? '', end_time: parse.end ?? '' }),
            ...(own.has('calendar') || !parse.calendar ? {} : { calendar: parse.calendar }),
            ...(own.has('weekly') ? {} : { repeat_weekly: parse.weekly }),
        }));
    };
    useEffect(() => {
        if (mode !== 'event' || text.trim().length < 2) return;
        const ctl = new AbortController();
        const timer = setTimeout(() => postJson<EventParse>('events.preview', { text }, ctl.signal).then((d) => d && applyEvent(text, d)), 220);
        return () => {
            clearTimeout(timer);
            ctl.abort();
        };
    }, [mode, text]);

    const setEvField = (key: 'date' | 'time' | 'calendar' | 'weekly', patch: Partial<EventFields>) => {
        touched.current.add(key);
        setEv((f) => ({ ...f, ...patch }));
    };

    const post = (name: string, body: Record<string, unknown>) =>
        router.post(route(name), body as never, {
            preserveScroll: true,
            preserveState: 'errors',
            onError: (e) => setErrors(e as Errors),
            onSuccess: onClose,
            onFinish: () => setSaving(false),
        });

    const submit = async () => {
        if (!text.trim() || saving) return;
        setSaving(true);
        setErrors({});
        if (mode === 'task') {
            post('tasks.store', { text, importance, urgency, project_id: data?.parsed.project?.id ?? projectId ?? null });
        } else if (mode === 'person') {
            post('people.store', { name: text.trim(), relationship: who.trim() || null, contacts: [], group_ids: [], stay: true });
        } else {
            // Read the line once more if it changed since the last preview, so Enter never adds a half-read event.
            let parse = evParsed?.text === text ? evParsed.parse : null;
            let fields = ev;
            if (!parse) {
                parse = await postJson<EventParse>('events.preview', { text });
                if (parse) {
                    applyEvent(text, parse);
                    const own = touched.current;
                    fields = {
                        ...ev,
                        ...(own.has('date') ? {} : { date: parse.date }),
                        ...(own.has('time') ? {} : { all_day: parse.allDay, start_time: parse.start ?? '', end_time: parse.end ?? '' }),
                        ...(own.has('calendar') || !parse.calendar ? {} : { calendar: parse.calendar }),
                        ...(own.has('weekly') ? {} : { repeat_weekly: parse.weekly }),
                    };
                }
            }
            post('events.store', {
                title: (parse?.title || text).trim(),
                calendar: fields.calendar,
                date: fields.date,
                all_day: fields.all_day,
                start_time: fields.all_day ? null : fields.start_time || null,
                end_time: fields.all_day ? null : fields.end_time || null,
                repeat_weekly: fields.repeat_weekly,
                repeat_until: null,
                location: null,
                notes: null,
            });
        }
    };

    const p = data?.parsed;
    const due = p?.due ? dueInfo({ dueAt: p.due }, clock) : null;
    const tab = (m: Mode, icon: string, label: string) => (
        <button
            type="button"
            className="qa-tab"
            role="tab"
            aria-selected={mode === m}
            onClick={() => {
                setMode(m);
                setErrors({});
            }}
        >
            <Icon name={icon} />
            {label}
        </button>
    );
    const firstError = Object.values(errors)[0];

    return (
        <div
            className="dialog"
            role="dialog"
            aria-modal="true"
            aria-label={mode === 'task' ? 'Add a task' : mode === 'event' ? 'Add an event' : 'Add a person'}
        >
            <div className="qa-tabs" role="tablist">
                {tab('task', 'tasks', 'Task')}
                {tab('event', 'planner', 'Event')}
                <button
                    type="button"
                    className="qa-tab"
                    role="tab"
                    aria-selected="false"
                    onClick={() => router.post(route('notes.store'), { project_id: projectId ?? null, title: text.trim().slice(0, 200) || null })}
                >
                    <Icon name="notes" />
                    Note
                </button>
                {tab('person', 'people', 'Person')}
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
                    placeholder={PLACEHOLDER[mode]}
                    aria-label={PLACEHOLDER[mode]}
                    aria-invalid={firstError ? true : undefined}
                    maxLength={mode === 'person' ? 120 : 255}
                />
            </form>

            {mode === 'task' && p && (p.due || p.hours || p.project || p.repeat) ? (
                <div className="qa-chips">
                    {due && (
                        <span className="qa-chip">
                            <Icon name="calendar" />
                            Due {due.long.replace('Today · ', 'today, ').replace('Tomorrow · ', 'tomorrow, ')}
                        </span>
                    )}
                    {p.repeat && (
                        <span className="qa-chip">
                            <Icon name="habits" />
                            {repeatText(p.repeat)}
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

            {mode === 'event' && (
                <div className="qa-fields">
                    <div className="qa-row">
                        <DatePick
                            label="Day"
                            value={ev.date}
                            onChange={(v) => v && setEvField('date', { date: v })}
                            clearable={false}
                            className="input qa-date"
                        />
                        {!ev.all_day && (
                            <span className="qa-times">
                                <DatePick
                                    label="From"
                                    mode="time"
                                    value={ev.start_time}
                                    onChange={(v) => setEvField('time', { start_time: v })}
                                    clearable={false}
                                    placeholder="Start"
                                />
                                <span aria-hidden="true">–</span>
                                <DatePick
                                    label="To"
                                    mode="time"
                                    value={ev.end_time}
                                    onChange={(v) => setEvField('time', { end_time: v })}
                                    clearable={false}
                                    placeholder="End"
                                />
                            </span>
                        )}
                        <label className="auth-check">
                            <input
                                type="checkbox"
                                checked={ev.all_day}
                                onChange={(e) => {
                                    const hour = clock.localInput(clock.now.toISOString()).slice(11, 13);
                                    const next = String((Number(hour) + 1) % 24).padStart(2, '0');
                                    setEvField('time', {
                                        all_day: e.target.checked,
                                        start_time: ev.start_time || `${next}:00`,
                                        end_time: ev.end_time || `${String((Number(next) + 1) % 24).padStart(2, '0')}:00`,
                                    });
                                }}
                            />
                            All day
                        </label>
                    </div>
                    <div className="qa-row">
                        <div className="seg ev-seg" role="radiogroup" aria-label="Calendar">
                            {CALENDARS.map(([k, label, icon]) => (
                                <button
                                    key={k}
                                    type="button"
                                    role="radio"
                                    aria-checked={ev.calendar === k}
                                    onClick={() => setEvField('calendar', { calendar: k })}
                                >
                                    <Icon name={icon} className="ic-sm" />
                                    {label}
                                </button>
                            ))}
                        </div>
                        <label className="auth-check">
                            <input
                                type="checkbox"
                                checked={ev.repeat_weekly}
                                onChange={(e) => setEvField('weekly', { repeat_weekly: e.target.checked })}
                            />
                            Every week
                        </label>
                    </div>
                </div>
            )}

            {mode === 'person' && (
                <form
                    className="qa-fields"
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                >
                    <input
                        className="input"
                        value={who}
                        onChange={(e) => setWho(e.target.value)}
                        placeholder="Who they are (optional)"
                        aria-label="Who they are"
                        maxLength={80}
                    />
                </form>
            )}

            {firstError && (
                <p className="field-error qa-error" role="alert">
                    {firstError}
                </p>
            )}

            {mode === 'task' && (
                <>
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
                </>
            )}
            <div className="qa-foot">
                <span />
                <button className="btn btn-primary" onClick={submit} disabled={!text.trim() || saving}>
                    {mode === 'task' ? 'Add task' : mode === 'event' ? 'Add event' : 'Add person'}
                    <kbd>Enter</kbd>
                </button>
            </div>
        </div>
    );
}
