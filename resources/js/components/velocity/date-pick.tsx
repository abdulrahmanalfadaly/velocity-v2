import { Clock, hm12 } from '@/lib/velocity';
import type { VelocityShared } from '@/types/velocity';
import { usePage } from '@inertiajs/react';
import { type KeyboardEvent, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Icon } from './icon';
import { MonthCalendar, shiftDay } from './month-calendar';

type Mode = 'date' | 'datetime' | 'time';

const at = (d: string) => new Date(`${d}T12:00:00Z`);
const days = (a: string, b: string) => Math.round((at(b).getTime() - at(a).getTime()) / 864e5);

/** "Today", "Tomorrow", "Tue 17 Nov", "Mon 4 Jan 2027". */
export function dayText(d: string, today: string): string {
    const n = days(today, d);
    if (n === 0) return 'Today';
    if (n === 1) return 'Tomorrow';
    if (n === -1) return 'Yesterday';
    const year = d.slice(0, 4) !== today.slice(0, 4);
    return new Intl.DateTimeFormat('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        ...(year ? { year: 'numeric' } : {}),
        timeZone: 'UTC',
    })
        .format(at(d))
        .replace(',', '');
}

/** "11:59 pm", "23:59", "9", "9am", "9.30 p" → "23:59" / "09:00" …; anything else → null. */
export function parseTime(s: string): string | null {
    const m = s
        .trim()
        .toLowerCase()
        .replace(/\s+/g, '')
        .match(/^(\d{1,2})(?:[:.]?(\d{2}))?(a|am|p|pm)?$/);
    if (!m) return null;
    let h = Number(m[1]);
    const min = Number(m[2] ?? 0);
    if (m[3]) {
        if (h < 1 || h > 12) return null;
        h = (h % 12) + (m[3].startsWith('p') ? 12 : 0);
    }
    if (h > 23 || min > 59) return null;
    return `${String(h).padStart(2, '0')}:${String(min).padStart(2, '0')}`;
}

const STEPS = Array.from({ length: 36 }, (_, i) => {
    const t = 6 * 60 + i * 30; // 6:00 am to 11:30 pm
    return `${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`;
});
const QUICK_TIMES = ['09:00', '12:00', '17:00', '23:59'];

/**
 * Velocity's date and time box, in place of the browser's own: a button that reads
 * like the rest of the app ("Tue 17 Nov, 11:59 pm", or "None") and a small panel
 * with a month, a few quick choices and a time you can type ("7:45 pm").
 *
 * Values are the same strings the browser's boxes used: "2026-11-17",
 * "2026-11-17T23:59" or "23:59", and "" for none.
 */
export function DatePick({
    id,
    value,
    onChange,
    mode = 'date',
    label,
    className = 'input',
    placeholder = 'None',
    clearable = true,
    min,
    max,
    defaultTime = '23:59',
}: {
    id?: string;
    value: string;
    onChange: (value: string) => void;
    mode?: Mode;
    /** Read out by screen readers when no <label> points at `id`. */
    label?: string;
    className?: string;
    placeholder?: string;
    clearable?: boolean;
    /** Earliest and latest day ("2026-11-15") that can be picked. */
    min?: string;
    max?: string;
    /** The time a datetime gets when only a day is picked. */
    defaultTime?: string;
}) {
    const { props } = usePage<VelocityShared>();
    const clock = Clock.from(props.clock);
    const today = clock.localInput(clock.now.toISOString()).slice(0, 10);
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState(value);
    const [month, setMonth] = useState((value || today).slice(0, 7));
    const [timeText, setTimeText] = useState('');
    const [place, setPlace] = useState<{ left: number; top?: number; bottom?: number } | null>(null);
    const btn = useRef<HTMLButtonElement>(null);
    const pop = useRef<HTMLDivElement>(null);
    const draftRef = useRef(draft);
    draftRef.current = draft;

    const datePart = (v: string) => (mode === 'time' ? '' : v.slice(0, 10));
    const timePart = (v: string) => (mode === 'time' ? v : mode === 'datetime' ? v.slice(11, 16) : '');
    const shown = (v: string) => {
        if (!v) return placeholder;
        if (mode === 'time') return hm12(v);
        const d = dayText(datePart(v), today);
        return mode === 'datetime' && timePart(v) ? `${d}, ${hm12(timePart(v))}` : d;
    };
    const out = (d: string) => (min !== undefined && d < min) || (max !== undefined && d > max);

    const show = () => {
        const r = btn.current?.getBoundingClientRect();
        if (!r) return;
        const width = mode === 'time' ? 200 : 292;
        const below = window.innerHeight - r.bottom - 8;
        const up = below < 380 && r.top > below;
        setPlace({
            left: Math.max(8, Math.min(r.left, window.innerWidth - width - 8)),
            ...(up ? { bottom: window.innerHeight - r.top + 4 } : { top: r.bottom + 4 }),
        });
        setDraft(value);
        setMonth((datePart(value) || today).slice(0, 7));
        setTimeText(timePart(value) ? hm12(timePart(value)) : '');
        setOpen(true);
    };
    /** Closing keeps what was picked (like the browser's own box); Escape throws it away. */
    const close = (keep: boolean, refocus = true) => {
        setOpen(false);
        if (keep && draftRef.current !== value) onChange(draftRef.current);
        if (refocus) btn.current?.focus();
    };
    const commit = (v: string) => {
        setOpen(false);
        if (v !== value) onChange(v);
        btn.current?.focus();
    };

    useEffect(() => {
        if (!open) return;
        const away = (e: Event) => {
            const t = e.target as Node;
            if (!btn.current?.contains(t) && !pop.current?.contains(t)) close(true, false);
        };
        const gone = () => close(true, false);
        document.addEventListener('pointerdown', away, true);
        document.addEventListener('scroll', away, true);
        window.addEventListener('resize', gone);
        return () => {
            document.removeEventListener('pointerdown', away, true);
            document.removeEventListener('scroll', away, true);
            window.removeEventListener('resize', gone);
        };
    }, [open]); // eslint-disable-line react-hooks/exhaustive-deps
    // Once drawn, keep the whole panel on screen (above the box when there's no room below).
    useLayoutEffect(() => {
        const el = pop.current;
        const r = btn.current?.getBoundingClientRect();
        if (!open || !el || !r) return;
        const h = el.offsetHeight;
        const fitsBelow = r.bottom + 4 + h <= window.innerHeight - 8;
        const fitsAbove = r.top - 4 - h >= 8;
        const top = fitsBelow ? r.bottom + 4 : fitsAbove ? r.top - 4 - h : Math.max(8, window.innerHeight - h - 8);
        el.style.top = `${top}px`;
        el.style.bottom = 'auto';
    }, [open, place]);
    useEffect(() => {
        if (!open) return;
        const el = pop.current?.querySelector<HTMLElement>(mode === 'time' ? 'input' : '.mcal-day.is-shown, .mcal-day.is-today');
        el?.focus();
        if (mode === 'time') pop.current?.querySelector('.dp-times .is-on')?.scrollIntoView({ block: 'center' });
    }, [open, mode]);

    const pickDay = (d: string) => {
        if (out(d)) return;
        if (mode === 'date') return commit(d);
        setDraft(`${d}T${timePart(draft) || defaultTime}`);
        if (!timeText) setTimeText(hm12(timePart(draft) || defaultTime));
    };
    const setTime = (t: string) => {
        setTimeText(hm12(t));
        if (mode === 'time') return commit(t);
        setDraft(`${datePart(draft) || today}T${t}`);
    };
    const typedTime = () => {
        const t = parseTime(timeText);
        if (t) setTime(t);
        return t;
    };
    const onKey = (e: KeyboardEvent) => {
        if (e.key === 'Escape') {
            // Only the panel closes, not the dialog or panel it sits in.
            e.preventDefault();
            e.stopPropagation();
            close(false);
        }
    };
    const quick: [string, string][] = [
        ['Today', today],
        ['Tomorrow', shiftDay(today, 1)],
        ['Next week', shiftDay(today, 7)],
    ];

    return (
        <>
            <button
                ref={btn}
                id={id}
                type="button"
                className={`dpick ${className}${value ? '' : ' is-empty'}`}
                aria-haspopup="dialog"
                aria-expanded={open}
                aria-label={label ? `${label}: ${shown(value)}` : undefined}
                onClick={() => (open ? close(true) : show())}
                onKeyDown={(e) => {
                    if (e.key === 'ArrowDown' && !open) {
                        e.preventDefault();
                        show();
                    }
                }}
            >
                <Icon name={mode === 'time' ? 'clock' : 'calendar'} className="ic-sm dpick-ic" />
                <span className="dpick-value">{shown(value)}</span>
            </button>
            {open &&
                place &&
                createPortal(
                    <div
                        ref={pop}
                        className={`dp-pop is-${mode}`}
                        role="dialog"
                        aria-label={label ?? (mode === 'time' ? 'Pick a time' : 'Pick a day')}
                        style={place}
                        onKeyDown={onKey}
                    >
                        {mode !== 'time' && (
                            <>
                                <div className="dp-quick">
                                    {quick
                                        .filter(([, d]) => !out(d))
                                        .map(([text, d]) => (
                                            <button key={text} type="button" className="chip-btn" onClick={() => pickDay(d)}>
                                                {text}
                                            </button>
                                        ))}
                                </div>
                                <MonthCalendar
                                    month={month}
                                    today={today}
                                    marked={new Set()}
                                    chosen={datePart(draft) ? [datePart(draft)] : []}
                                    clock={clock}
                                    onMonth={setMonth}
                                    onPick={pickDay}
                                    disabled={out}
                                />
                            </>
                        )}
                        {mode !== 'date' && (
                            <div className="dp-time">
                                <input
                                    className="input"
                                    value={timeText}
                                    placeholder={mode === 'time' ? 'Type a time, e.g. 7:45 pm' : 'Time, e.g. 5 pm'}
                                    aria-label="Time"
                                    onChange={(e) => setTimeText(e.target.value)}
                                    onBlur={() => timeText && typedTime()}
                                    onKeyDown={(e) => {
                                        if (e.key !== 'Enter') return;
                                        e.preventDefault();
                                        const t = typedTime();
                                        if (t && mode === 'datetime') commit(`${datePart(draftRef.current) || today}T${t}`);
                                    }}
                                />
                                {mode === 'datetime' ? (
                                    <div className="dp-quick">
                                        {QUICK_TIMES.map((t) => (
                                            <button
                                                key={t}
                                                type="button"
                                                className={`chip-btn${timePart(draft) === t ? ' is-on' : ''}`}
                                                onClick={() => setTime(t)}
                                            >
                                                {hm12(t)}
                                            </button>
                                        ))}
                                    </div>
                                ) : (
                                    <ul className="dp-times" role="listbox" aria-label="Times">
                                        {STEPS.map((t) => (
                                            <li key={t} role="option" aria-selected={value === t}>
                                                <button type="button" className={value === t ? 'is-on' : undefined} onClick={() => setTime(t)}>
                                                    {hm12(t)}
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        )}
                        {(mode === 'datetime' || clearable) && (
                            <div className="dp-foot">
                                {clearable && value !== '' ? (
                                    <button type="button" className="btn btn-ghost btn-sm" onClick={() => commit('')}>
                                        Clear
                                    </button>
                                ) : (
                                    <span />
                                )}
                                {mode === 'datetime' && (
                                    <button type="button" className="btn btn-primary btn-sm" onClick={() => close(true)}>
                                        Done
                                    </button>
                                )}
                            </div>
                        )}
                    </div>,
                    document.body,
                )}
        </>
    );
}
