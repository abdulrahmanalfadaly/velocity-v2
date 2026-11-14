import { fmtHours } from '@/lib/velocity';
import type { FocusRunning, VelocityShared } from '@/types/velocity';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Icon } from './icon';
import { notifyState } from './reminders';
import { Select } from './select';

const keep = { preserveScroll: true, preserveState: true };
export const FOCUS_LENGTHS: [number | null, string][] = [
    [25, '25 min'],
    [50, '50 min'],
    [90, '90 min'],
    [null, 'No limit'],
];

/** "24:13", or "1:04:13" past an hour. */
const clockText = (s: number) => {
    const t = Math.max(0, Math.round(s));
    const h = Math.floor(t / 3600);
    const m = Math.floor((t % 3600) / 60);
    const sec = t % 60;
    return `${h ? `${h}:${String(m).padStart(2, '0')}` : m}:${String(sec).padStart(2, '0')}`;
};

/** Focused seconds so far, worked out from the session's timestamps and the server's clock. */
function useElapsed(f: FocusRunning | null, serverNow: string) {
    // How far this computer's clock is from the server's (which can be frozen for testing).
    const offset = useRef(0);
    useEffect(() => {
        offset.current = new Date(serverNow).getTime() - Date.now();
    }, [serverNow]);
    const calc = () => {
        if (!f) return 0;
        const until = f.pausedAt ? new Date(f.pausedAt).getTime() : Date.now() + offset.current;
        return Math.max(0, (until - new Date(f.startedAt).getTime()) / 1000 - f.pausedSeconds);
    };
    const [elapsed, setElapsed] = useState(calc);
    useEffect(() => {
        setElapsed(calc());
        if (!f || f.pausedAt) return;
        const t = setInterval(() => setElapsed(calc()), 1000);
        return () => clearInterval(t);
    }, [f?.id, f?.pausedAt, f?.pausedSeconds]); // eslint-disable-line react-hooks/exhaustive-deps
    return elapsed;
}

/** The running timer: a chip in the top bar (computers) or a floating bar (phones). */
export function FocusTimer({ variant }: { variant: 'chip' | 'float' }) {
    const { props } = usePage<VelocityShared>();
    const f = props.focusSession ?? null;
    const elapsed = useElapsed(f, props.clock.now);
    const planned = f?.plannedMinutes ? f.plannedMinutes * 60 : null;
    const over = planned != null && elapsed >= planned;
    const told = useRef<number | null>(null);

    // When a timed session reaches its end: tell the user once (also in Windows, if allowed).
    useEffect(() => {
        if (!f || !over || told.current === f.id || variant !== 'chip') return;
        told.current = f.id;
        if (notifyState() === 'on')
            new Notification('Focus time is up', { body: f.taskTitle ?? 'Your focus session', tag: `velocity-focus-${f.id}` });
    }, [over, f, variant]);

    if (!f) return null;
    const label = planned != null ? (over ? `+${clockText(elapsed - planned)}` : clockText(planned - elapsed)) : clockText(elapsed);
    const paused = !!f.pausedAt;

    return (
        <div
            className={`${variant === 'chip' ? 'focus-chip' : 'focus-float'}${over ? ' is-over' : ''}${paused ? ' is-paused' : ''}`}
            role="status"
            aria-label={`Focus ${paused ? 'paused' : over ? 'time is up' : 'running'}: ${label}`}
        >
            <span className="focus-dot" />
            <div className="focus-meta">
                <div className="focus-time">{over ? `Time's up ${label}` : label}</div>
                {f.taskTitle &&
                    (f.taskId ? (
                        <Link className="focus-task" href={`/tasks?task=${f.taskId}`} preserveScroll title={f.taskTitle}>
                            {f.taskTitle}
                        </Link>
                    ) : (
                        <div className="focus-task">{f.taskTitle}</div>
                    ))}
            </div>
            <button
                className="icon-btn"
                aria-label={paused ? 'Resume focus' : 'Pause focus'}
                title={paused ? 'Resume' : 'Pause'}
                onClick={() => router.post(route(paused ? 'focus.resume' : 'focus.pause', f.id), {}, keep)}
            >
                <Icon name={paused ? 'play' : 'pause'} />
            </button>
            <button className="icon-btn" aria-label="Stop focus" title="Stop" onClick={() => router.post(route('focus.stop', f.id), {}, keep)}>
                <Icon name="check" />
            </button>
        </div>
    );
}

/** "Start focus" for a task: 50 minutes, or another length from the little menu next to it. */
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
    const onThis = props.focusSession?.taskId === taskId;
    const start = (minutes: number | null) => router.post(route('focus.start'), { task_id: taskId, minutes }, keep);
    if (onThis) {
        return (
            <button
                className={className.replace('btn-primary', 'btn-secondary')}
                onClick={() => router.post(route('focus.stop', props.focusSession!.id), {}, keep)}
            >
                <Icon name="check" />
                Stop focus
            </button>
        );
    }
    return (
        <span className="start-focus">
            <button className={className} onClick={() => start(50)}>
                <Icon name="play" />
                {compact ? 'Focus' : 'Start focus'}
            </button>
            <Select
                className="start-focus-len"
                label="Focus for how long"
                title="Other lengths"
                value=""
                trigger={<Icon name="chevD" className="ic-xs" />}
                options={FOCUS_LENGTHS.map(([m, label]) => ({ value: m == null ? 'open' : String(m), label }))}
                onChange={(v) => start(v === 'open' ? null : Number(v))}
            />
        </span>
    );
}

/** After stopping: "You focused 50 min. Hours left?" – updating it is the user's call, since it moves the rank. */
export function FocusDone() {
    const { props } = usePage<VelocityShared>();
    const done = props.flash?.focusDone ?? null;
    const [shown, setShown] = useState(done);
    const [hours, setHours] = useState('');
    useEffect(() => {
        if (done) {
            setShown(done);
            setHours(String(done.hoursLeft));
        }
    }, [done]);
    if (!shown) return null;
    const minutes = Math.round(shown.seconds / 60);
    const close = () => setShown(null);
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
                        close();
                    }}
                >
                    <b>
                        Focused {minutes >= 60 ? fmtHours(minutes / 60) : `${minutes} min`} on {shown.title}
                    </b>
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
                        <button className="btn btn-secondary btn-sm" type="button" onClick={close}>
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
