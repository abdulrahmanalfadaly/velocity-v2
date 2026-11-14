import { fmtHours } from '@/lib/velocity';
import type { FocusRunning, VelocityShared } from '@/types/velocity';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Modal } from './forms';
import { Icon } from './icon';
import { notifyState } from './reminders';

const keep = { preserveScroll: true, preserveState: true };

/** "24:13", or "1:04:13" past an hour. */
const clockText = (s: number) => {
    const t = Math.max(0, Math.round(s));
    const h = Math.floor(t / 3600);
    const m = Math.floor((t % 3600) / 60);
    const sec = t % 60;
    return `${h ? `${h}:${String(m).padStart(2, '0')}` : m}:${String(sec).padStart(2, '0')}`;
};

/** A soft two-note chime (no sound file needed). */
function chime() {
    try {
        const ctx = new AudioContext();
        [660, 880].forEach((hz, i) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.frequency.value = hz;
            gain.gain.setValueAtTime(0.0001, ctx.currentTime + i * 0.22);
            gain.gain.exponentialRampToValueAtTime(0.18, ctx.currentTime + i * 0.22 + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + i * 0.22 + 0.5);
            osc.connect(gain).connect(ctx.destination);
            osc.start(ctx.currentTime + i * 0.22);
            osc.stop(ctx.currentTime + i * 0.22 + 0.55);
        });
        setTimeout(() => ctx.close(), 1500);
    } catch {
        // No sound here: the chip still says it.
    }
}

/** The server's "now" in milliseconds, ticking every second while `on` (the server's clock can be frozen for testing). */
function useServerNow(serverNow: string, on: boolean) {
    const offset = useRef(0);
    useEffect(() => {
        offset.current = new Date(serverNow).getTime() - Date.now();
    }, [serverNow]);
    const [now, setNow] = useState(() => Date.now() + offset.current);
    useEffect(() => {
        setNow(Date.now() + offset.current);
        if (!on) return;
        const t = setInterval(() => setNow(Date.now() + offset.current), 1000);
        return () => clearInterval(t);
    }, [on, serverNow]);
    return now;
}

/** Focused seconds so far, from the session's timestamps. */
const elapsedOf = (f: FocusRunning, now: number) =>
    Math.max(0, ((f.pausedAt ? new Date(f.pausedAt).getTime() : now) - new Date(f.startedAt).getTime()) / 1000 - f.pausedSeconds);

/**
 * The timer: a chip in the top bar (computers) or a floating bar (phones). A plain
 * timer keeps counting past its time; a Pomodoro stops at the end of each round and
 * shows its break, then waits for the next round.
 */
export function FocusTimer({ variant }: { variant: 'chip' | 'float' }) {
    const { props } = usePage<VelocityShared>();
    const f = props.focusSession ?? null;
    const phase = f?.phase ?? 'focus';
    const ticking = !!f && ((phase === 'focus' && !f.pausedAt) || phase === 'break');
    const now = useServerNow(props.clock.now, ticking);
    const elapsed = f ? elapsedOf(f, now) : 0;
    const planned = f?.plannedMinutes ? f.plannedMinutes * 60 : null;
    const pomodoro = f?.mode === 'pomodoro';
    const over = phase === 'focus' && planned != null && elapsed >= planned;
    const breakLeft = f?.breakEndsAt ? (new Date(f.breakEndsAt).getTime() - now) / 1000 : 0;
    const told = useRef<string | null>(null);

    // When time is up (a timer, a round, a break): a chime and a Windows message, once; a Pomodoro then moves on.
    const moment = !f ? null : phase === 'focus' && over ? `${f.id}-over` : phase === 'break' && breakLeft <= 0 ? `${f.id}-break` : null;
    useEffect(() => {
        if (!f || !moment || told.current === moment || variant !== 'chip') return;
        told.current = moment;
        if (f.sound) chime();
        if (notifyState() === 'on') {
            const what = moment.endsWith('break') ? 'Break’s over' : pomodoro ? `Round ${f.round ?? 1} done` : 'Focus time is up';
            new Notification(what, { body: f.taskTitle ?? 'Your focus session', tag: `velocity-focus-${moment}` });
        }
        if (pomodoro || phase === 'break') router.reload({ only: ['focusSession'] });
    }, [moment, f, variant, pomodoro, phase]);

    if (!f) return null;
    const cls = variant === 'chip' ? 'focus-chip' : 'focus-float';
    const round = pomodoro ? `Round ${f.round ?? 1}` : null;
    const btn = (icon: string, label: string, name: string, extra?: string) => (
        <button className={`icon-btn${extra ?? ''}`} aria-label={label} title={label} onClick={() => router.post(route(name, f.id), {}, keep)}>
            <Icon name={icon} />
        </button>
    );
    const taskLine = f.taskTitle ? (
        f.taskId ? (
            <Link className="focus-task" href={`/tasks?task=${f.taskId}`} preserveScroll title={f.intention ?? f.taskTitle}>
                {f.taskTitle}
            </Link>
        ) : (
            <div className="focus-task">{f.taskTitle}</div>
        )
    ) : f.intention ? (
        <div className="focus-task">{f.intention}</div>
    ) : null;

    if (phase !== 'focus') {
        const text = phase === 'break' ? `Break ${clockText(breakLeft)}` : phase === 'roundDone' ? `${round} done` : 'Break’s over';
        return (
            <div className={`${cls} is-break`} role="timer" aria-label={`Pomodoro: ${text}`}>
                <span className="focus-dot" />
                <div className="focus-meta">
                    <div className="focus-time">{text}</div>
                    {taskLine}
                </div>
                {phase === 'roundDone' && btn('coffee', 'Take a break', 'focus.break')}
                {btn('play', phase === 'breakOver' ? `Start round ${(f.round ?? 1) + 1}` : 'Skip break', 'focus.next')}
                {btn('check', 'Done for now', 'focus.end')}
            </div>
        );
    }

    const label = planned != null ? (over ? `+${clockText(elapsed - planned)}` : clockText(planned - elapsed)) : clockText(elapsed);
    const paused = !!f.pausedAt;
    return (
        <div
            className={`${cls}${over && !pomodoro ? ' is-over' : ''}${paused ? ' is-paused' : ''}`}
            // A timer, not a live message: screen readers don't read out every passing second.
            role="timer"
            aria-label={`Focus ${paused ? 'paused' : over ? 'time is up' : 'running'}: ${label}`}
        >
            <span className="focus-dot" />
            <div className="focus-meta">
                <div className="focus-time">
                    {round && <span className="focus-round">{round} · </span>}
                    {over && !pomodoro ? `Time's up ${label}` : label}
                </div>
                {taskLine}
            </div>
            {btn(paused ? 'play' : 'pause', paused ? 'Resume focus' : 'Pause focus', paused ? 'focus.resume' : 'focus.pause')}
            {f.taskOpen && btn('issueDone', 'Task done', 'focus.done-task')}
            {btn('stop', 'Stop focus', 'focus.stop')}
        </div>
    );
}

/** "Start focus" for a task: the way Settings → Focus says, or another way from the little menu next to it. */
export function StartFocus({
    taskId,
    className = 'btn btn-primary btn-lg',
    compact = false,
}: {
    taskId: number;
    className?: string;
    compact?: boolean;
}) {
    const { props } = usePage<VelocityShared>();
    const [choosing, setChoosing] = useState(false);
    const f = props.focusSession;
    const onThis = f?.taskId === taskId && f.phase === 'focus';
    if (onThis) {
        return (
            <button className={className.replace('btn-primary', 'btn-secondary')} onClick={() => router.post(route('focus.stop', f!.id), {}, keep)}>
                <Icon name="stop" />
                Stop focus
            </button>
        );
    }
    return (
        <span className="start-focus">
            <button className={className} onClick={() => router.post(route('focus.start'), { task_id: taskId }, keep)}>
                <Icon name="play" />
                {compact ? 'Focus' : 'Start focus'}
            </button>
            <button
                type="button"
                className="start-focus-len"
                aria-label="Focus another way"
                title="Other lengths, Pomodoro, what you’ll do"
                onClick={() => setChoosing(true)}
            >
                <Icon name="chevD" className="ic-xs" />
            </button>
            {choosing && <FocusChoice taskId={taskId} onClose={() => setChoosing(false)} />}
        </span>
    );
}

const LENGTHS: [string, string][] = [
    ['pomodoro', 'Pomodoro'],
    ['25', '25 min'],
    ['50', '50 min'],
    ['90', '90 min'],
    ['custom', 'Other'],
    ['open', 'No limit'],
];

/** How to focus this time: a length or a Pomodoro, and what you'll do (optional). */
function FocusChoice({ taskId, onClose }: { taskId: number; onClose: () => void }) {
    const [pick, setPick] = useState('50');
    const [custom, setCustom] = useState('30');
    const [intention, setIntention] = useState('');
    const start = () => {
        const minutes = pick === 'custom' ? Math.round(Number(custom)) : Number(pick);
        if (pick === 'custom' && !(minutes >= 1 && minutes <= 240)) return;
        router.post(
            route('focus.start'),
            {
                task_id: taskId,
                intention: intention.trim() || null,
                ...(pick === 'pomodoro' ? { mode: 'pomodoro' } : { mode: 'timer', minutes: pick === 'open' ? null : minutes }),
            },
            { ...keep, onSuccess: onClose },
        );
    };
    return (
        <Modal title="Focus" onClose={onClose}>
            <form
                className="vform"
                onSubmit={(e) => {
                    e.preventDefault();
                    start();
                }}
                onKeyDown={(e) => {
                    // Escape closes this box only, not the task panel behind it.
                    if (e.key === 'Escape') {
                        e.stopPropagation();
                        onClose();
                    }
                }}
            >
                <div className="field">
                    <div className="field-label">
                        <label htmlFor="focus-intention">What will you do?</label>
                    </div>
                    <input
                        id="focus-intention"
                        className="input"
                        maxLength={240}
                        value={intention}
                        placeholder="Finish the method section"
                        onChange={(e) => setIntention(e.target.value)}
                    />
                </div>
                <div className="seg ev-seg focus-lengths" role="radiogroup" aria-label="How long">
                    {LENGTHS.map(([v, label]) => (
                        <button key={v} type="button" role="radio" aria-checked={pick === v} onClick={() => setPick(v)}>
                            {label}
                        </button>
                    ))}
                </div>
                {pick === 'custom' && (
                    <span className="fs-num">
                        <input
                            className="input"
                            type="number"
                            min={1}
                            max={240}
                            value={custom}
                            aria-label="Minutes"
                            onChange={(e) => setCustom(e.target.value)}
                            autoFocus
                        />
                        min
                    </span>
                )}
                <div className="vform-foot">
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="submit" className="btn btn-primary">
                        <Icon name="play" />
                        Start
                    </button>
                </div>
            </form>
        </Modal>
    );
}

/** After stopping: "You focused 50 min. Hours left? What got done?" – updating the hours is the user's call, since it moves the rank. */
export function FocusDone() {
    const { props } = usePage<VelocityShared>();
    const done = props.flash?.focusDone ?? null;
    const [shown, setShown] = useState(done);
    const [hours, setHours] = useState('');
    const [note, setNote] = useState('');
    useEffect(() => {
        if (done) {
            setShown(done);
            setHours(String(done.hoursLeft));
            setNote('');
        }
    }, [done]);
    if (!shown) return null;
    const minutes = Math.round(shown.seconds / 60);
    const close = () => setShown(null);
    const saveNote = () => note.trim() && shown.sessionId && router.patch(route('focus.update', shown.sessionId), { note: note.trim() }, keep);
    return (
        <div className="reminders" role="region" aria-label="Focus finished">
            <div className="reminder" role="alert">
                <span className="reminder-ic">
                    <Icon name="focus" />
                </span>
                <form
                    className="reminder-main"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const h = Number(hours);
                        if (h > 0 && h !== shown.estHours) router.patch(route('tasks.update', shown.taskId), { est_hours: h }, keep);
                        saveNote();
                        close();
                    }}
                >
                    <b>
                        Focused {minutes >= 60 ? fmtHours(minutes / 60) : `${minutes} min`} on {shown.title}
                    </b>
                    <input
                        className="prop-input focus-note"
                        value={note}
                        maxLength={1000}
                        placeholder="What got done? (optional)"
                        aria-label="What got done"
                        onChange={(e) => setNote(e.target.value)}
                    />
                    <span>Hours of work left (was {fmtHours(shown.estHours)}):</span>
                    <div className="reminder-actions">
                        <input
                            className="prop-input focus-left"
                            type="number"
                            min={0.25}
                            max={500}
                            step={0.25}
                            value={hours}
                            onChange={(e) => setHours(e.target.value)}
                            aria-label="Hours of work left"
                        />
                        <button className="btn btn-primary btn-sm" type="submit">
                            Update
                        </button>
                        <button
                            className="btn btn-secondary btn-sm"
                            type="button"
                            onClick={() => {
                                saveNote();
                                close();
                            }}
                        >
                            Keep {fmtHours(shown.estHours)}
                        </button>
                    </div>
                </form>
                <button className="icon-btn reminder-close" aria-label="Close" onClick={close}>
                    <Icon name="x" />
                </button>
            </div>
        </div>
    );
}
