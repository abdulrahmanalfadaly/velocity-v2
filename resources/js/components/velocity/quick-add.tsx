import { Clock, dueInfo, fmtHours, LEVEL, repeatText } from '@/lib/velocity';
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
        /** "from mon": when it can start. */
        start?: string | null;
        /** "remind 9am" / "remind 1h before". */
        remind?: { kind: 'at'; at: string } | { kind: 'before'; before: string } | null;
        /** "!" / "!!". */
        importance?: number | null;
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
    until: string | null;
}

interface EventFields {
    date: string;
    all_day: boolean;
    start_time: string;
    end_time: string;
    calendar: CalendarKey;
    repeat_weekly: boolean;
    repeat_until: string | null;
}

type Mode = 'task' | 'event' | 'note' | 'person';
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
    note: 'Note title',
    person: 'Their name',
};

const BEFORE_WORDS: Record<string, string> = {
    '15m': '15 min before',
    '1h': '1 hour before',
    '3h': '3 hours before',
    '1d': '1 day before',
    '2d': '2 days before',
    '7d': '1 week before',
};

/** The words a chip stands for, as the parser compares them. */
const wordsIn = (text: string) =>
    text
        .toLowerCase()
        .split(/\s+/)
        .map((w) => w.replace(/[,.]+$/, ''))
        .filter(Boolean);

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
    const blankEvent = (): EventFields => ({
        date: today,
        all_day: true,
        start_time: '',
        end_time: '',
        calendar: 'personal',
        repeat_weekly: false,
        repeat_until: null,
    });
    const [ev, setEv] = useState<EventFields>(blankEvent);
    // Words to keep as words (a chip taken off), whether importance was picked on the dots, and "Add another".
    const [plain, setPlain] = useState<string[]>([]);
    const [impPicked, setImpPicked] = useState(false);
    const [again, setAgain] = useState(false);
    const [noteText, setNoteText] = useState('');
    const [evParsed, setEvParsed] = useState<{ text: string; parse: EventParse } | null>(null);
    const touched = useRef<Set<'date' | 'time' | 'calendar' | 'weekly'>>(new Set());
    const [who, setWho] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [overlap, setOverlap] = useState('');
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
            () =>
                postJson<Preview>('tasks.preview', { text, urgency, plain, ...(impPicked ? { importance } : {}) }, ctl.signal).then(
                    (d) => d && setData(d),
                ),
            220,
        );
        return () => {
            clearTimeout(timer);
            ctl.abort();
        };
    }, [mode, text, importance, urgency, plain, impPicked]);

    // Event: the typed day, times and calendar fill the fields (unless you set them yourself).
    const applyEvent = (t: string, parse: EventParse) => {
        setEvParsed({ text: t, parse });
        const own = touched.current;
        setEv((f) => ({
            ...f,
            ...(own.has('date') ? {} : { date: parse.date }),
            ...(own.has('time') ? {} : { all_day: parse.allDay, start_time: parse.start ?? '', end_time: parse.end ?? '' }),
            ...(own.has('calendar') || !parse.calendar ? {} : { calendar: parse.calendar }),
            ...(own.has('weekly') ? {} : { repeat_weekly: parse.weekly, repeat_until: parse.until }),
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

    // Event: what the chosen time overlaps on the Planner (a warning only).
    useEffect(() => {
        if (mode !== 'event' || ev.all_day || !ev.start_time || !ev.end_time) {
            setOverlap('');
            return;
        }
        const ctl = new AbortController();
        const timer = setTimeout(
            () =>
                postJson<{ note: string }>('events.overlaps', { date: ev.date, start_time: ev.start_time, end_time: ev.end_time }, ctl.signal).then(
                    (d) => d && setOverlap(d.note),
                ),
            250,
        );
        return () => {
            clearTimeout(timer);
            ctl.abort();
        };
    }, [mode, ev.date, ev.start_time, ev.end_time, ev.all_day]);

    const setEvField = (key: 'date' | 'time' | 'calendar' | 'weekly', patch: Partial<EventFields>) => {
        touched.current.add(key);
        setEv((f) => ({ ...f, ...patch }));
    };

    // "Add another": empty again, ready for the next one.
    const reset = () => {
        setText('');
        setData(null);
        setPlain([]);
        setImpPicked(false);
        setImportance(3);
        setUrgency(3);
        setNoteText('');
        setWho('');
        touched.current.clear();
        setEvParsed(null);
        setEv(blankEvent());
        input.current?.focus();
    };

    const post = (name: string, body: Record<string, unknown>) => {
        let answered = false;
        router.post(route(name), body as never, {
            preserveScroll: true,
            preserveState: again ? true : 'errors',
            onError: (e) => {
                answered = true;
                setErrors(e as Errors);
            },
            onSuccess: () => {
                answered = true;
                if (again) reset();
                else onClose();
            },
            onFinish: () => {
                setSaving(false);
                // No answer (the connection dropped): what you typed stays, so you can try again.
                if (!answered) setErrors({ text: 'Couldn’t add it. Check the connection and try again.' });
            },
        });
    };

    const empty = mode === 'note' ? !text.trim() && !noteText.trim() : !text.trim();
    const submit = async () => {
        if (empty || saving) return;
        setSaving(true);
        setErrors({});
        if (mode === 'task') {
            post('tasks.store', {
                text,
                urgency,
                plain,
                ...(impPicked ? { importance } : {}),
                project_id: data?.parsed.project?.id ?? projectId ?? null,
            });
        } else if (mode === 'note') {
            post('notes.store', { title: text.trim() || null, text: noteText, project_id: projectId ?? null, stay: true });
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
                        ...(own.has('weekly') ? {} : { repeat_weekly: parse.weekly, repeat_until: parse.until }),
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
                repeat_until: fields.repeat_weekly ? fields.repeat_until : null,
                location: null,
                notes: null,
            });
        }
    };

    const p = data?.parsed;
    const due = p?.due ? dueInfo({ dueAt: p.due }, clock) : null;
    // One chip for each thing read out of the words; × keeps those words in the title.
    const wordsOf = (kinds: string[]) =>
        (p?.picked ?? [])
            .filter((k) => kinds.includes(k.kind))
            .map((k) => k.text)
            .join(' ');
    const day = (iso: string) => clock.date(iso, { weekday: 'short', day: 'numeric', month: 'short' });
    const chips: { key: string; icon: string; label: string; words: string }[] = [];
    if (p) {
        if (due)
            chips.push({
                key: 'due',
                icon: 'calendar',
                label: `Due ${due.long.replace('Today · ', 'today, ').replace('Tomorrow · ', 'tomorrow, ')}`,
                words: wordsOf(['due', 'time']),
            });
        if (p.start) chips.push({ key: 'start', icon: 'calClock', label: `Starts ${day(p.start)}`, words: wordsOf(['start']) });
        if (p.remind)
            chips.push({
                key: 'remind',
                icon: 'bell',
                label: p.remind.kind === 'at' ? `Remind ${day(p.remind.at)}, ${clock.time(p.remind.at)}` : `Remind ${BEFORE_WORDS[p.remind.before]}`,
                words: wordsOf(['remind']),
            });
        if (p.repeat) chips.push({ key: 'repeat', icon: 'habits', label: repeatText(p.repeat), words: wordsOf(['repeat']) });
        if (p.hours) chips.push({ key: 'hours', icon: 'clock', label: `${fmtHours(p.hours)} of work`, words: wordsOf(['hours']) });
        if (p.importance && !impPicked)
            chips.push({ key: 'imp', icon: 'flag', label: `${LEVEL[p.importance]} importance`, words: wordsOf(['importance']) });
        if (p.project) chips.push({ key: 'project', icon: 'folder', label: p.project.name, words: wordsOf(['project']) });
    }
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
            aria-label={mode === 'task' ? 'Add a task' : mode === 'event' ? 'Add an event' : mode === 'note' ? 'Add a note' : 'Add a person'}
        >
            <div className="qa-tabs" role="tablist">
                {tab('task', 'tasks', 'Task')}
                {tab('event', 'planner', 'Event')}
                {tab('note', 'notes', 'Note')}
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
                    maxLength={mode === 'person' ? 120 : mode === 'note' ? 200 : 255}
                />
            </form>

            {mode === 'note' && (
                <div className="qa-fields">
                    <textarea
                        className="input qa-note-text"
                        rows={4}
                        value={noteText}
                        onChange={(e) => setNoteText(e.target.value)}
                        placeholder="Write it here (optional)"
                        aria-label="Note text"
                        maxLength={200000}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                                e.preventDefault();
                                submit();
                            }
                        }}
                    />
                </div>
            )}

            {mode === 'task' && chips.length > 0 ? (
                <div className="qa-chips">
                    {chips.map((c) => (
                        <span className="qa-chip" key={c.key}>
                            <Icon name={c.icon} />
                            {c.label}
                            {c.words && (
                                <button
                                    type="button"
                                    aria-label={`Keep “${c.words}” as text`}
                                    title="Keep as text"
                                    onClick={() => setPlain((w) => [...w, ...wordsIn(c.words)])}
                                >
                                    <Icon name="x" className="ic-xs" />
                                </button>
                            )}
                        </span>
                    ))}
                    {!p?.project && (
                        <span className="qa-chip is-plain">
                            <Icon name="inbox" />
                            Inbox
                        </span>
                    )}
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
                    {overlap && (
                        <p className="qa-note">
                            <Icon name="alert" className="ic-sm" />
                            {overlap.charAt(0).toUpperCase() + overlap.slice(1)}
                        </p>
                    )}
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
                            Importance{' '}
                            <Dots
                                n={impPicked ? importance : (p?.importance ?? importance)}
                                c="I"
                                onPick={(v) => {
                                    setImportance(v);
                                    setImpPicked(true);
                                }}
                            />
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
                <label className="auth-check">
                    <input type="checkbox" checked={again} onChange={(e) => setAgain(e.target.checked)} />
                    Add another
                </label>
                <span className="qa-foot-right">
                    {mode === 'note' && (
                        <button
                            type="button"
                            className="btn btn-ghost"
                            onClick={() =>
                                router.post(route('notes.store'), {
                                    project_id: projectId ?? null,
                                    title: text.trim().slice(0, 200) || null,
                                    text: noteText,
                                })
                            }
                        >
                            Open in Notes
                        </button>
                    )}
                    <button className="btn btn-primary" onClick={submit} disabled={empty || saving}>
                        {mode === 'task' ? 'Add task' : mode === 'event' ? 'Add event' : mode === 'note' ? 'Add note' : 'Add person'}
                        <kbd>{mode === 'note' ? 'Ctrl Enter' : 'Enter'}</kbd>
                    </button>
                </span>
            </div>
        </div>
    );
}
