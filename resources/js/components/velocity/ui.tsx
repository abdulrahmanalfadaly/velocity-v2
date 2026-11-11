import { CRIT, fmt1, signed } from '@/lib/velocity';
import type { CriterionKey, HabitToday } from '@/types/velocity';
import { router } from '@inertiajs/react';
import { Icon } from './icon';

/** The score as a bar: its length is the priority out of 100, each colour one part of the formula. */
export function PriorityBar({
    contrib,
    size = '',
    score,
}: {
    contrib: Record<CriterionKey, number>;
    size?: '' | 'pbar-sm' | 'pbar-lg';
    score?: number;
}) {
    const total = score ?? CRIT.reduce((s, c) => s + contrib[c.k], 0);
    const label = `Priority ${fmt1(total)} out of 100: ${CRIT.map((c) => `${c.name.toLowerCase()} ${fmt1(contrib[c.k])}`).join(', ')}`;
    return (
        <span className={`pbar ${size}`} role="img" aria-label={label}>
            {CRIT.filter((c) => contrib[c.k] > 0.05).map((c) => (
                <i key={c.k} className={`s-${c.k}`} style={{ width: `${contrib[c.k]}%` }} />
            ))}
            <i className="s-rest" />
        </span>
    );
}

export function ScoreCell({ score, contrib }: { score: number; contrib: Record<CriterionKey, number> }) {
    return (
        <div className="score-cell">
            <span className="score-num">{fmt1(score)}</span>
            <PriorityBar contrib={contrib} size="pbar-sm" score={score} />
        </div>
    );
}

export function Check({ done, onClick, label, size = '' }: { done: boolean; onClick?: () => void; label: string; size?: '' | 'check-lg' }) {
    return (
        <button
            type="button"
            className={`check ${size} ${done ? 'is-done' : ''}`}
            aria-label={label}
            aria-pressed={done}
            onClick={(e) => {
                e.stopPropagation();
                onClick?.();
            }}
        >
            <Icon name="check" />
        </button>
    );
}

export function Dots({ n, c, onPick }: { n: number; c: 'I' | 'U'; onPick?: (v: number) => void }) {
    return (
        <span className="dots" data-c={c} role={onPick ? 'radiogroup' : undefined}>
            {[1, 2, 3, 4, 5].map((i) =>
                onPick ? (
                    <i
                        key={i}
                        className={i <= n ? 'on' : ''}
                        role="radio"
                        aria-checked={i === n}
                        aria-label={`${i} of 5`}
                        tabIndex={0}
                        style={{ cursor: 'pointer' }}
                        onClick={() => onPick(i)}
                        onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && onPick(i)}
                    />
                ) : (
                    <i key={i} className={i <= n ? 'on' : ''} />
                ),
            )}
        </span>
    );
}

export function Ring({ pct, size = 44, stroke = 5 }: { pct: number; size?: number; stroke?: number }) {
    const r = (size - stroke) / 2;
    const c = 2 * Math.PI * r;
    return (
        <svg className="progress-ring" width={size} height={size} viewBox={`0 0 ${size} ${size}`} aria-hidden="true">
            <circle className="progress-ring-track" cx={size / 2} cy={size / 2} r={r} strokeWidth={stroke} />
            <circle
                className="progress-ring-fill"
                cx={size / 2}
                cy={size / 2}
                r={r}
                strokeWidth={stroke}
                strokeDasharray={c.toFixed(2)}
                strokeDashoffset={(c * (1 - pct / 100)).toFixed(2)}
                transform={`rotate(-90 ${size / 2} ${size / 2})`}
            />
        </svg>
    );
}

/** Points ahead (right) or behind (left) the task below, per part of the formula. */
export function Diverging({ deltas, names }: { deltas: Record<CriterionKey, number>; names?: Record<CriterionKey, string> }) {
    const max = Math.max(0.1, ...CRIT.map((c) => Math.abs(deltas[c.k])));
    const name = (k: CriterionKey) => names?.[k] ?? CRIT.find((c) => c.k === k)!.name;
    return (
        <div className="dv" role="img" aria-label={`Differences in points: ${CRIT.map((c) => `${name(c.k)} ${signed(deltas[c.k])}`).join(', ')}`}>
            {CRIT.map((c) => {
                const v = deltas[c.k];
                return (
                    <div className="dv-row" key={c.k}>
                        <span className="n">{name(c.k)}</span>
                        <span className="dv-neg">{v < -0.05 && <i style={{ width: `${(-v / max) * 100}%`, background: `var(--c-${c.k})` }} />}</span>
                        <span className="dv-pos">{v > 0.05 && <i style={{ width: `${(v / max) * 100}%`, background: `var(--c-${c.k})` }} />}</span>
                        <span className="v">{signed(v)}</span>
                    </div>
                );
            })}
        </div>
    );
}

export function Legend({ contrib }: { contrib: Record<CriterionKey, number> }) {
    return (
        <div className="legend">
            {CRIT.map((c) => (
                <span key={c.k}>
                    <i className={`sw sw-${c.k}`} />
                    {c.name} <b>{fmt1(contrib[c.k])}</b>
                </span>
            ))}
        </div>
    );
}

/** Tap to check in a habit: once-a-day habits tick on and off; counted ones (8 glasses) go up by one, then back to 0. */
export function HabitButton({ habit }: { habit: HabitToday }) {
    const partial = habit.target > 1 && !habit.doneToday;
    const label =
        habit.target > 1 ? `${habit.name}: ${habit.today} of ${habit.target} today` : `${habit.name}${habit.doneToday ? ', done today' : ''}`;
    return (
        <button
            type="button"
            className={`habit-btn${habit.doneToday ? ' is-done' : ''}${partial ? ' is-partial' : ''}`}
            style={partial ? { ['--p' as string]: (habit.today / habit.target) * 100 } : undefined}
            aria-label={label}
            aria-pressed={habit.doneToday}
            title={habit.target > 1 ? 'Add one' : habit.doneToday ? 'Mark not done' : 'Mark done today'}
            onClick={() => router.post(route('habits.check', habit.id), {}, { preserveScroll: true, preserveState: true })}
        >
            {partial ? <span>{habit.today}</span> : <Icon name="check" />}
        </button>
    );
}
