import { Clock, dueInfo, fmtHours, goalVar } from '@/lib/velocity';
import type { RankedTask, VelocityShared } from '@/types/velocity';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { StartFocus } from './focus';
import { Modal } from './forms';
import { Icon } from './icon';

interface Step {
    out: number[];
    champ: number | null;
    side: 0 | 1;
}

/**
 * This or that: when you can't choose between a few tasks, pick between two at a time.
 * The one you'd rather do stays, the other drops out, until one is left. Only a way to
 * choose for now: the ranked list keeps its order.
 */
export function ThisOrThat({
    tasks,
    clock,
    onOpen,
    onClose,
}: {
    /** The selected tasks, live from the ranked list (in rank order). */
    tasks: RankedTask[];
    clock: Clock;
    onOpen: (id: number) => void;
    onClose: () => void;
}) {
    const { props } = usePage<VelocityShared>();
    const [step, setStep] = useState<Step>({ out: [], champ: null, side: 0 });
    const [history, setHistory] = useState<Step[]>([]);
    // Tasks finished or gone meanwhile drop out by themselves.
    const live = tasks.filter((t) => !step.out.includes(t.id));
    const champ = live.find((t) => t.id === step.champ) ?? live[0];
    const rival = live.find((t) => t.id !== champ?.id);
    const pair = rival ? (step.side === 0 ? [champ, rival] : [rival, champ]) : null;
    const total = tasks.length;
    const winner = !pair && champ ? champ : null;

    const choose = (i: 0 | 1) => {
        if (!pair) return;
        setHistory((h) => [...h, step]);
        setStep({ out: [...step.out, pair[1 - i].id], champ: pair[i].id, side: i });
    };
    const back = () => {
        const last = history[history.length - 1];
        if (!last) return;
        setHistory((h) => h.slice(0, -1));
        setStep(last);
    };

    // ← and → (or 1 and 2) choose; Backspace goes back one.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.target instanceof Element && e.target.closest('input, textarea, [contenteditable="true"]')) return;
            let act: (() => void) | null = null;
            if (pair && (e.key === 'ArrowLeft' || e.key === '1')) act = () => choose(0);
            else if (pair && (e.key === 'ArrowRight' || e.key === '2')) act = () => choose(1);
            else if (e.key === 'Backspace' && history.length) act = back;
            if (!act) return;
            e.preventDefault();
            act();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    });

    // The keyboard stays inside: on the first card, then on Start focus once one is left.
    const box = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const el = box.current;
        if (!el || el.closest('[role="dialog"]')?.contains(document.activeElement)) return;
        el.querySelector<HTMLElement>('button.tot-card, .tot-actions button')?.focus();
    });

    // Focus started on the one you chose: this is done.
    const focusOn = props.focusSession?.subject;
    useEffect(() => {
        if (winner && focusOn?.kind === 'task' && focusOn.id === winner.id) onClose();
    }, [focusOn?.kind, focusOn?.id]); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <Modal title="This or that" onClose={onClose}>
            <div className="tot" ref={box}>
                {pair ? (
                    <>
                        <p className="tot-ask">Which would you rather do first?</p>
                        <div className="tot-pair">
                            {pair.map((t, i) => (
                                <button key={t.id} type="button" className="tot-card" onClick={() => choose(i as 0 | 1)}>
                                    <TaskFace task={t} clock={clock} />
                                    <span className="tot-key" aria-hidden="true">
                                        {i === 0 ? '←' : '→'}
                                    </span>
                                </button>
                            ))}
                        </div>
                        <div className="tot-foot">
                            <span className="tot-left">
                                {live.length} left of {total}
                            </span>
                            {history.length > 0 && (
                                <button type="button" className="btn btn-ghost btn-sm" onClick={back}>
                                    <Icon name="undo" className="ic-sm" />
                                    Back
                                </button>
                            )}
                        </div>
                    </>
                ) : winner ? (
                    <>
                        <p className="tot-ask">You’d do this one first.</p>
                        <div className="tot-card tot-winner">
                            <TaskFace task={winner} clock={clock} />
                        </div>
                        <div className="tot-actions">
                            <StartFocus taskId={winner.id} />
                            {winner.status !== 'doing' && (
                                <button
                                    type="button"
                                    className="btn btn-secondary btn-lg"
                                    onClick={() =>
                                        router.patch(
                                            route('tasks.update', winner.id),
                                            { status: 'doing' },
                                            { preserveScroll: true, preserveState: true, onSuccess: onClose },
                                        )
                                    }
                                >
                                    <Icon name="zap" />
                                    Doing
                                </button>
                            )}
                            <button type="button" className="btn btn-secondary btn-lg" onClick={() => onOpen(winner.id)}>
                                <Icon name="arrowUpRight" />
                                Open
                            </button>
                        </div>
                        <div className="tot-foot">
                            <span className="tot-left">Your list keeps its order.</span>
                            <button
                                type="button"
                                className="btn btn-ghost btn-sm"
                                onClick={() => (setStep({ out: [], champ: null, side: 0 }), setHistory([]))}
                            >
                                <Icon name="refresh" className="ic-sm" />
                                Start over
                            </button>
                        </div>
                    </>
                ) : (
                    <p className="tot-ask">These tasks aren’t in your list any more.</p>
                )}
            </div>
        </Modal>
    );
}

/** A task as a card: its place in the list, title, project, due date and work. */
function TaskFace({ task, clock }: { task: RankedTask; clock: Clock }) {
    const due = task.dueAt ? dueInfo(task, clock) : null;
    return (
        <>
            <span className="rank-badge" title="Its place in your list">
                {task.rank}
            </span>
            <b className="tot-title">{task.title}</b>
            <span className="tot-meta">
                {task.project && (
                    <span className="tot-proj" style={{ ['--goal' as string]: goalVar(task.goal?.color) }}>
                        <i className="na-dot" aria-hidden="true" />
                        {task.project.name}
                    </span>
                )}
                {due && <span className={due.cls}>{due.text}</span>}
                <span>{fmtHours(task.estHours)} of work</span>
                {task.status === 'doing' && <span>Doing</span>}
            </span>
        </>
    );
}
