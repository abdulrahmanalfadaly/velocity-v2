import { fmtHours } from '@/lib/velocity';
import type { VelocityShared } from '@/types/velocity';
import { Link, router, usePage } from '@inertiajs/react';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import { DatePick } from './date-pick';
import { Modal } from './forms';
import { Icon } from './icon';

/** Working hours, Monday first: { start, end } or null for a day off. */
export type Hours = ({ start: string; end: string } | null)[];

/** Time booked on the Planner for a task. */
export interface PlanBlock {
    id: number;
    taskId: number;
    title: string;
    start: string;
    end: string;
    pinned: boolean;
    rank: number | null;
    dueAt: string | null;
}

const keep = { preserveScroll: true, preserveState: true };
const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

/** The part of each day that "Plan" may fill. */
export function HoursDialog({ hours, onClose }: { hours: Hours; onClose: () => void }) {
    const [rows, setRows] = useState(() => hours.map((h) => ({ on: h !== null, start: h?.start ?? '08:00', end: h?.end ?? '22:00' })));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const set = (i: number, patch: Partial<(typeof rows)[number]>) => setRows((r) => r.map((x, j) => (j === i ? { ...x, ...patch } : x)));
    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.put(
            route('planner.hours'),
            { hours: rows.map((r) => (r.on ? { start: r.start, end: r.end } : null)) },
            { ...keep, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose, onError: setErrors },
        );
    };
    return (
        <Modal title="Working hours" onClose={onClose}>
            <form className="vform" onSubmit={submit}>
                <div className="hours-rows">
                    {rows.map((r, i) => {
                        const error = errors[`hours.${i}.end`] ?? errors[`hours.${i}.start`];
                        return (
                            <div key={i} className={`hours-row${r.on ? '' : ' is-off'}`}>
                                <label className="auth-check">
                                    <input type="checkbox" checked={r.on} onChange={(e) => set(i, { on: e.target.checked })} />
                                    {DAYS[i]}
                                </label>
                                {r.on ? (
                                    <span className="hours-times">
                                        <DatePick
                                            mode="time"
                                            value={r.start}
                                            onChange={(v) => set(i, { start: v })}
                                            label={`${DAYS[i]} from`}
                                            clearable={false}
                                        />
                                        <span aria-hidden="true">–</span>
                                        <DatePick
                                            mode="time"
                                            value={r.end}
                                            onChange={(v) => set(i, { end: v })}
                                            label={`${DAYS[i]} to`}
                                            clearable={false}
                                        />
                                    </span>
                                ) : (
                                    <span className="hours-off">Day off</span>
                                )}
                                {error && <p className="field-error hours-error">{error}</p>}
                            </div>
                        );
                    })}
                </div>
                <div className="vform-foot">
                    <span />
                    <span className="vform-foot-right">
                        <button type="button" className="btn btn-secondary" onClick={onClose}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={busy}>
                            Save
                        </button>
                    </span>
                </div>
            </form>
        </Modal>
    );
}

/** What can be done with a block: focus on it now, open its task, or take it off the plan. */
export function BlockMenu({
    block,
    x,
    y,
    minutes,
    when,
    onClose,
}: {
    block: PlanBlock;
    x: number;
    y: number;
    minutes: number;
    when: string;
    onClose: () => void;
}) {
    const { props } = usePage<VelocityShared>();
    const box = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const onDown = (e: PointerEvent) => {
            const t = e.target as Element;
            if (!box.current?.contains(t) && !t.closest?.('.dp-pop, .sel-pop')) onClose();
        };
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        document.addEventListener('pointerdown', onDown, true);
        document.addEventListener('keydown', onKey);
        box.current?.querySelector<HTMLElement>('button, a')?.focus();
        return () => {
            document.removeEventListener('pointerdown', onDown, true);
            document.removeEventListener('keydown', onKey);
        };
    }, [onClose]);
    const focusing = props.focusSession?.taskId === block.taskId;
    return (
        <div
            className="bmenu"
            role="menu"
            aria-label={block.title}
            ref={box}
            style={{ left: Math.max(8, Math.min(x, window.innerWidth - 268)), top: Math.max(8, Math.min(y, window.innerHeight - 210)) }}
        >
            <div className="bmenu-head">
                <b>{block.title}</b>
                <span>{when}</span>
            </div>
            {!focusing && (
                <button
                    role="menuitem"
                    className="bmenu-item"
                    onClick={() => {
                        router.post(route('focus.start'), { task_id: block.taskId, minutes: Math.min(minutes, 600) }, keep);
                        onClose();
                    }}
                >
                    <Icon name="play" />
                    Focus for {fmtHours(minutes / 60)}
                </button>
            )}
            <Link role="menuitem" className="bmenu-item" href={`/tasks?task=${block.taskId}`}>
                <Icon name="arrowUpRight" />
                Open task
            </Link>
            <button
                role="menuitem"
                className="bmenu-item"
                onClick={() => {
                    router.delete(route('blocks.destroy', block.id), keep);
                    onClose();
                }}
            >
                <Icon name="x" />
                Remove from plan
            </button>
        </div>
    );
}
