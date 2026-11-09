import { Icon } from '@/components/velocity/icon';
import { Diverging, PriorityBar } from '@/components/velocity/ui';
import { CRIT, fmt1, fmtHours, round1, signed } from '@/lib/velocity';
import type { CriterionKey, RankedTask } from '@/types/velocity';
import { router } from '@inertiajs/react';
import { CRITERION, trim } from './terms';

interface Params {
    weights: Record<CriterionKey, number>;
    horizonDays: number;
    hoursPerDay: number;
}

/** What went into each criterion, as the student entered it. */
function input(t: RankedTask, k: CriterionKey): string {
    const days = t.days;
    if (k === 'I') return `${t.importance} / 5`;
    if (k === 'U') return `${t.urgency} / 5`;
    if (k === 'D') return !t.dueAt ? 'no due date' : days != null && days <= 0 ? 'overdue' : `${(days ?? 0).toFixed(1)} d left`;
    const h = fmtHours(t.estHours);
    if (!t.dueAt) return `${h} over 30 d`;
    return days != null && days <= 0 ? `${h}, overdue` : `${h} in ${Math.max(days ?? 0, 1).toFixed(1)} d`;
}

/**
 * Explanation panel for one ranked task (FR8, Algorithm 3.3): exact points per
 * criterion, the formula to reproduce the score by hand, and a contrastive
 * explanation against the task directly below.
 *
 * While a weight slider moves, the numbers update at once; the sentences come
 * from the server and return as soon as the new weights are saved.
 */
export function ExplainPanel({
    task,
    below,
    total,
    params,
    fresh,
    onClose,
    onEdit,
}: {
    task: RankedTask;
    below: RankedTask | null;
    total: number;
    params: Params;
    fresh: boolean;
    onClose: () => void;
    onEdit: () => void;
}) {
    const deltas = below
        ? (Object.fromEntries(CRIT.map((c) => [c.k, task.contrib[c.k] - below.contrib[c.k]])) as Record<CriterionKey, number>)
        : null;
    const gap = below ? task.score - below.score : null;
    const formula = CRIT.map((c) => `${(params.weights[c.k] / 100).toFixed(2)}×${task.criteria[c.k].toFixed(2)}`).join(' + ');

    return (
        <>
            <div className="panel-head">
                <div className="crumbs">
                    <span>
                        Rank {task.rank} of {total}
                    </span>
                </div>
                <div className="panel-tools">
                    <button
                        className="icon-btn"
                        aria-label="Delete task"
                        title="Delete (you can undo)"
                        onClick={() => router.delete(route('tasks.destroy', task.id), { preserveScroll: true })}
                    >
                        <Icon name="archive" />
                    </button>
                    <button className="icon-btn" onClick={onClose} aria-label="Close the explanation">
                        <Icon name="x" />
                    </button>
                </div>
            </div>
            <div className="panel-body fyp-explain">
                <h2 className="fyp-explain-q">{task.rank === 1 ? 'Why is this first?' : `Why is this #${task.rank}?`}</h2>
                <p className="fyp-explain-task">{task.title}</p>
                {task.notes && <p className="fyp-explain-notes">{task.notes}</p>}

                <div className="fyp-explain-score">
                    <b>{fmt1(task.score)}</b>
                    <span>/ 100 points</span>
                </div>
                <PriorityBar contrib={task.contrib} size="pbar-lg" score={task.score} />

                <table className="fyp-breakdown">
                    <caption className="sr-only">Score breakdown by criterion</caption>
                    <thead>
                        <tr>
                            <th scope="col">Criterion</th>
                            <th scope="col">Input</th>
                            <th scope="col" className="num" title="Normalised value, 0 to 1">
                                c
                            </th>
                            <th scope="col" className="num">
                                Weight
                            </th>
                            <th scope="col" className="num">
                                Points
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {CRIT.map((c) => (
                            <tr key={c.k}>
                                <th scope="row">
                                    <i className={`sw sw-${c.k}`} />
                                    {CRITERION[c.k]}
                                </th>
                                <td className="muted">{input(task, c.k)}</td>
                                <td className="num">{task.criteria[c.k].toFixed(2)}</td>
                                <td className="num">{params.weights[c.k]}%</td>
                                <td className="num strong">{fmt1(task.contrib[c.k])}</td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr>
                            <th scope="row" colSpan={4}>
                                Priority score
                            </th>
                            <td className="num strong">{fmt1(task.score)}</td>
                        </tr>
                    </tfoot>
                </table>

                {fresh && <p className="fyp-explain-summary">{task.summary}</p>}

                <details className="fyp-formula">
                    <summary>
                        <Icon name="info" className="ic-sm" />
                        Reproduce by hand
                    </summary>
                    <code>
                        S = 100 × ({formula}) = {fmt1(task.score)}
                    </code>
                    <p className="muted">
                        c values are rounded here; the score uses the exact values. Horizon h = {trim(params.horizonDays)} d, capacity H ={' '}
                        {trim(params.hoursPerDay)} h/day.
                    </p>
                </details>

                {below && deltas && gap != null ? (
                    <section className="fyp-contrast" aria-label="Compared with the task below">
                        <h3>Compared with #{task.rank + 1} below</h3>
                        <p className="fyp-contrast-below">{below.title}</p>
                        {fresh && task.contrast && <p className="vs-sentence">{task.contrast}</p>}
                        <Diverging deltas={deltas} names={CRITERION} />
                        <div className="fyp-gap">
                            <span>Gap</span>
                            <b>{signed(gap)}</b>
                        </div>
                        {round1(gap) < 1 && (
                            <p className="fyp-tie">
                                <Icon name="info" className="ic-sm" />
                                Less than one point apart: these two are almost tied, so either is a good choice.
                            </p>
                        )}
                    </section>
                ) : total > 1 ? (
                    <p className="fyp-contrast fyp-last">
                        <Icon name="info" className="ic-sm" />
                        This is the lowest-ranked open task, so there is nothing below it to compare with.
                    </p>
                ) : null}
            </div>
            <div className="panel-foot">
                <button
                    className="btn btn-primary btn-lg"
                    onClick={() => router.post(route('tasks.complete', task.id), {}, { preserveScroll: true })}
                >
                    <Icon name="check" />
                    Mark done
                </button>
                <button className="btn btn-secondary btn-lg" onClick={onEdit}>
                    Edit
                </button>
            </div>
        </>
    );
}
