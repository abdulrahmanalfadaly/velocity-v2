import { CRITERION, trim } from '@/components/fyp/terms';
import { Icon } from '@/components/velocity/icon';
import { PriorityBar } from '@/components/velocity/ui';
import FypShell from '@/layouts/fyp-shell';
import { adjustWeights, CRIT, DEFAULT_WEIGHTS, fmt1, rerank, sameWeights, type WeightSet } from '@/lib/velocity';
import type { CriterionKey, RankedTask, VelocityShared } from '@/types/velocity';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

interface Props extends VelocityShared {
    weights: WeightSet;
    isDefault: boolean;
    horizonDays: number;
    hoursPerDay: number;
    tasks: RankedTask[];
    ahp: { matrix: number[][]; weights: number[]; lambdaMax: number; ci: number; cr: number };
}

const HINTS: Record<CriterionKey, string> = {
    I: 'How much the task matters, e.g. its grade weight.',
    U: 'Time sensitivity beyond the due date, e.g. others waiting.',
    D: 'Rises as the due date approaches (0.5 at the horizon).',
    P: 'Hours needed versus hours available before the due date.',
};

const fraction = (v: number) => {
    for (const d of [2, 3, 4, 5, 6, 7, 8, 9]) if (Math.abs(v * d - 1) < 1e-9) return `1/${d}`;
    return String(Math.round(v));
};

/** Weights & method (FR9, NFR5): tune the weights, watch the ranking respond, and see exactly how a score is worked out. */
export default function FypWeights() {
    const { props } = usePage<Props>();
    const [weights, setWeights] = useState<WeightSet>(props.weights);
    const [h, setH] = useState(props.horizonDays);
    const [cap, setCap] = useState(props.hoursPerDay);
    const snapshot = useRef(weights);
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);
    useEffect(() => setWeights(props.weights), [props.weights]);
    useEffect(() => setH(props.horizonDays), [props.horizonDays]);
    useEffect(() => setCap(props.hoursPerDay), [props.hoursPerDay]);

    const save = (w: WeightSet, horizon = h, hours = cap) => {
        clearTimeout(timer.current);
        timer.current = setTimeout(
            () =>
                router.put(
                    route('weights.update'),
                    { weights: w, horizonDays: horizon, hoursPerDay: hours },
                    { preserveScroll: true, preserveState: true, only: ['weights', 'isDefault', 'horizonDays', 'hoursPerDay', 'tasks'] },
                ),
            450,
        );
    };
    // h and H change the criteria themselves, so the preview waits for the server; the weights re-rank at once.
    const preview = useMemo(
        () => (sameWeights(weights, props.weights) ? props.tasks : rerank(props.tasks, weights)),
        [weights, props.weights, props.tasks],
    );
    const isDefault = sameWeights(weights, DEFAULT_WEIGHTS) && h === 7 && cap === 4;

    return (
        <FypShell title="Weights & method" page="weights">
            <div className="main-inner fyp-weights">
                <header className="page-head">
                    <div>
                        <p className="eyebrow">Transparent by design</p>
                        <h1 className="h1">Weights &amp; method</h1>
                        <p className="page-sub">
                            Your score is a published formula, not a black box. Tune how much each criterion counts and watch the ranking respond.
                        </p>
                    </div>
                </header>

                <div className="fyp-weights-grid">
                    <div className="fyp-weights-controls">
                        <section className="card fyp-card">
                            <div className="fyp-card-head">
                                <h2>
                                    <Icon name="sliders" />
                                    Weights
                                </h2>
                                <button
                                    className="btn btn-ghost btn-sm"
                                    disabled={sameWeights(weights, DEFAULT_WEIGHTS)}
                                    onClick={() => router.put(route('weights.update'), { weights: DEFAULT_WEIGHTS }, { preserveScroll: true })}
                                >
                                    Reset
                                </button>
                            </div>
                            {CRIT.map((c) => (
                                <div className="fyp-slider" key={c.k}>
                                    <div className="fyp-slider-head">
                                        <label htmlFor={`w-${c.k}`}>
                                            <i className={`sw sw-${c.k}`} />
                                            {CRITERION[c.k]}
                                        </label>
                                        <output htmlFor={`w-${c.k}`}>{weights[c.k]}%</output>
                                    </div>
                                    <p className="field-hint">{HINTS[c.k]}</p>
                                    <input
                                        type="range"
                                        id={`w-${c.k}`}
                                        min={0}
                                        max={100}
                                        value={weights[c.k]}
                                        onPointerDown={() => (snapshot.current = weights)}
                                        onFocus={() => (snapshot.current = weights)}
                                        onChange={(e) => {
                                            const next = adjustWeights(snapshot.current, c.k, Number(e.target.value));
                                            setWeights(next);
                                            save(next);
                                        }}
                                    />
                                </div>
                            ))}
                            <p className="fyp-status">
                                {sameWeights(weights, DEFAULT_WEIGHTS) ? 'AHP defaults (CR 0.004)' : 'Custom weights · always sum to 100%'}
                            </p>
                        </section>

                        <section className="card fyp-card">
                            <div className="fyp-card-head">
                                <h2>
                                    <Icon name="calendar" />
                                    Planning parameters
                                </h2>
                                <button
                                    className="btn btn-ghost btn-sm"
                                    disabled={isDefault}
                                    onClick={() => router.delete(route('weights.reset'), { preserveScroll: true })}
                                >
                                    Restore all defaults
                                </button>
                            </div>
                            <div className="fyp-slider">
                                <div className="fyp-slider-head">
                                    <label htmlFor="horizon">
                                        Deadline horizon <em>h</em>
                                    </label>
                                    <output htmlFor="horizon">{trim(h)} days</output>
                                </div>
                                <p className="field-hint">
                                    A task due in <em>h</em> days gets half of the full deadline-proximity value.
                                </p>
                                <input
                                    id="horizon"
                                    type="range"
                                    min={1}
                                    max={30}
                                    step={1}
                                    value={h}
                                    onChange={(e) => (setH(Number(e.target.value)), save(weights, Number(e.target.value)))}
                                />
                            </div>
                            <div className="fyp-slider">
                                <div className="fyp-slider-head">
                                    <label htmlFor="cap">
                                        Daily capacity <em>H</em>
                                    </label>
                                    <output htmlFor="cap">{trim(cap)} h/day</output>
                                </div>
                                <p className="field-hint">Hours per day you plan to spend on tasks; used for workload pressure.</p>
                                <input
                                    id="cap"
                                    type="range"
                                    min={0.5}
                                    max={12}
                                    step={0.5}
                                    value={cap}
                                    onChange={(e) => (setCap(Number(e.target.value)), save(weights, h, Number(e.target.value)))}
                                />
                            </div>
                        </section>
                    </div>

                    <section className="card fyp-card" aria-labelledby="preview-title">
                        <div className="fyp-card-head">
                            <h2 id="preview-title">
                                <Icon name="list" />
                                Your ranking
                            </h2>
                            <span className="muted">updates as you adjust</span>
                        </div>
                        {preview.length === 0 ? (
                            <p className="muted">
                                No open tasks yet.{' '}
                                <a className="link" href="/tasks">
                                    Add one
                                </a>{' '}
                                to see how the weights change the order.
                            </p>
                        ) : (
                            <ol className="fyp-mini-rank">
                                {preview.slice(0, 12).map((t) => (
                                    <li key={t.id}>
                                        <span className="rank-badge">{t.rank}</span>
                                        <span className="fyp-mini-body">
                                            <span className="fyp-mini-title">{t.title}</span>
                                            <PriorityBar contrib={t.contrib} size="pbar-sm" score={t.score} />
                                        </span>
                                        <b>{fmt1(t.score)}</b>
                                    </li>
                                ))}
                            </ol>
                        )}
                        {preview.length > 12 && (
                            <p className="muted fyp-more">
                                + {preview.length - 12} more on{' '}
                                <a className="link" href="/tasks">
                                    My tasks
                                </a>
                            </p>
                        )}
                    </section>
                </div>

                <section className="card fyp-card fyp-method" aria-labelledby="method-title">
                    <div className="fyp-card-head">
                        <h2 id="method-title">How a score is computed</h2>
                    </div>
                    <p className="fyp-formula-line">
                        <span className="fyp-s">S</span> = 100 × (
                        {CRIT.map((c, i) => (
                            <span key={c.k}>
                                {i > 0 && ' + '}
                                <span className="fyp-term">
                                    <i className={`sw sw-${c.k}`} />
                                    {(weights[c.k] / 100).toFixed(2)}·c<sub>{c.k}</sub>
                                </span>
                            </span>
                        ))}{' '}
                        )
                    </p>
                    <p className="muted">
                        Each criterion is normalised to 0–1 against fixed bounds, so a task's score depends only on that task and the clock. Adding or
                        completing a task never reorders the others; changing the weights does, by design.
                    </p>
                    <div className="fyp-table-scroll">
                        <table className="fyp-table">
                            <thead>
                                <tr>
                                    <th scope="col">Criterion</th>
                                    <th scope="col">Input</th>
                                    <th scope="col">Normalised value c (0–1)</th>
                                    <th scope="col">Behaviour</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <th scope="row">
                                        <i className="sw sw-I" />
                                        Importance
                                    </th>
                                    <td>Your rating 1–5</td>
                                    <td>
                                        <code>
                                            c<sub>I</sub> = (I − 1) / 4
                                        </code>
                                    </td>
                                    <td>Constant until edited</td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <i className="sw sw-U" />
                                        Urgency
                                    </th>
                                    <td>Your rating 1–5</td>
                                    <td>
                                        <code>
                                            c<sub>U</sub> = (U − 1) / 4
                                        </code>
                                    </td>
                                    <td>Constant until edited</td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <i className="sw sw-D" />
                                        Deadline proximity
                                    </th>
                                    <td>
                                        Days left <em>d</em>
                                    </td>
                                    <td>
                                        <code>
                                            c<sub>D</sub> = 1 / (1 + d / {trim(h)})
                                        </code>
                                        <span className="fyp-note">1 if overdue · 0 with no due date</span>
                                    </td>
                                    <td>Rises smoothly as the due date nears</td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <i className="sw sw-P" />
                                        Workload pressure
                                    </th>
                                    <td>
                                        Hours <em>T</em> and days left <em>d</em>
                                    </td>
                                    <td>
                                        <code>
                                            c<sub>P</sub> = min(1, T / ({trim(cap)} × max(d, 1)))
                                        </code>
                                        <span className="fyp-note">1 if overdue · T / (30 × {trim(cap)}) with no due date</span>
                                    </td>
                                    <td>High when the hours needed approach the hours available</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p className="muted fyp-small">
                        Ties are broken by the earlier due date (tasks without one last), then the shorter duration, then the earlier creation time.
                    </p>
                </section>

                <section className="card fyp-card fyp-method" aria-labelledby="ahp-title">
                    <div className="fyp-card-head">
                        <h2 id="ahp-title">Where the default weights come from</h2>
                    </div>
                    <p className="muted">
                        The defaults are derived with the Analytic Hierarchy Process from six pairwise judgments on Saaty's scale. Importance slightly
                        outweighs urgency and deadline proximity (2), because people already over-weight tasks that merely look urgent; it moderately
                        outweighs workload pressure (3).
                    </p>
                    <div className="fyp-ahp">
                        <div className="fyp-table-scroll">
                            <table className="fyp-table fyp-matrix">
                                <thead>
                                    <tr>
                                        <th scope="col">
                                            <span className="sr-only">Row criterion</span>
                                        </th>
                                        {CRIT.map((c) => (
                                            <th key={c.k} scope="col" className="num">
                                                {CRITERION[c.k]}
                                            </th>
                                        ))}
                                        <th scope="col" className="num">
                                            Weight
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {props.ahp.matrix.map((row, i) => (
                                        <tr key={i}>
                                            <th scope="row">
                                                <i className={`sw sw-${CRIT[i].k}`} />
                                                {CRITERION[CRIT[i].k]}
                                            </th>
                                            {row.map((v, j) => (
                                                <td key={j} className="num">
                                                    {fraction(v)}
                                                </td>
                                            ))}
                                            <td className="num strong">{props.ahp.weights[i].toFixed(2)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <dl className="fyp-ahp-stats">
                            <div>
                                <dt>
                                    λ<sub>max</sub>
                                </dt>
                                <dd>{props.ahp.lambdaMax.toFixed(3)}</dd>
                            </div>
                            <div>
                                <dt>Consistency index</dt>
                                <dd>{props.ahp.ci.toFixed(4)}</dd>
                            </div>
                            <div>
                                <dt>Consistency ratio</dt>
                                <dd>{props.ahp.cr.toFixed(3)}</dd>
                            </div>
                            <div>
                                <dt>Verdict</dt>
                                <dd className="fyp-good">{props.ahp.cr < 0.1 ? 'Consistent (CR < 0.10)' : 'Inconsistent'}</dd>
                            </div>
                        </dl>
                    </div>
                </section>
            </div>
        </FypShell>
    );
}
