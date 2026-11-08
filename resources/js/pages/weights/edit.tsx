import { Icon } from '@/components/velocity/icon';
import VelocityShell from '@/layouts/velocity-shell';
import { CRIT } from '@/lib/velocity';
import type { CriterionKey, VelocityShared } from '@/types/velocity';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

interface Props extends VelocityShared {
    weights: Record<CriterionKey, number>;
    isDefault: boolean;
    horizonDays: number;
    hoursPerDay: number;
    ahp: { matrix: number[][]; weights: number[]; lambdaMax: number; ci: number; cr: number };
}

function adjust(base: Record<CriterionKey, number>, key: CriterionKey, v: number): Record<CriterionKey, number> {
    const others = CRIT.map((c) => c.k).filter((k) => k !== key);
    const rest = 100 - v;
    const sum = others.reduce((s, k) => s + base[k], 0);
    const raw = Object.fromEntries(others.map((k) => [k, sum > 0 ? (base[k] / sum) * rest : rest / others.length])) as Record<CriterionKey, number>;
    const out = { [key]: v } as Record<CriterionKey, number>;
    let used = v;
    others.forEach((k) => {
        out[k] = Math.floor(raw[k]);
        used += out[k];
    });
    others
        .slice()
        .sort((a, b) => raw[b] - out[b] - (raw[a] - out[a]))
        .forEach((k) => {
            if (used < 100) {
                out[k] += 1;
                used += 1;
            }
        });
    return out;
}

const FORMULAS: [CriterionKey, string, string][] = [
    ['I', 'c = (I − 1) ÷ 4', 'Importance you give the task, 1 to 5.'],
    ['U', 'c = (U − 1) ÷ 4', 'Urgency you give the task, 1 to 5.'],
    ['D', 'c = 1 ÷ (1 + d ÷ h)', 'd = days until due, h = horizon. Overdue = 1, no due date = 0.'],
    ['P', 'c = min(1, T ÷ (H × max(d, 1)))', 'T = hours of work, H = hours you can give a day. Overdue = 1.'],
];

/** Your weights (FR9) and exactly how a score is worked out (NFR5). */
export default function WeightsEdit() {
    const { props } = usePage<Props>();
    const [weights, setWeights] = useState(props.weights);
    const [h, setH] = useState(props.horizonDays);
    const [cap, setCap] = useState(props.hoursPerDay);
    const snapshot = useRef(weights);
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);
    useEffect(() => setWeights(props.weights), [props.weights]);

    const save = (w: Record<CriterionKey, number>, horizon = h, hours = cap) => {
        clearTimeout(timer.current);
        timer.current = setTimeout(() => router.put(route('weights.update'), { weights: w, horizonDays: horizon, hoursPerDay: hours }, { preserveScroll: true, preserveState: true }), 450);
    };

    return (
        <VelocityShell title="Weights & method" section="tasks" active="weights">
            <div className="main-inner" style={{ maxWidth: 880 }}>
                <header className="page-head">
                    <div>
                        <p className="eyebrow is-section">Tasks</p>
                        <h1 className="h1">Weights & method</h1>
                        <p className="page-sub">Every list in Velocity is ranked by one formula. Here is what it values, and how every score can be worked out by hand.</p>
                    </div>
                </header>

                <section className="card" style={{ padding: 20 }}>
                    <div className="card-title" style={{ marginBottom: 4 }}>
                        <Icon name="sliders" />
                        What matters most to you?
                    </div>
                    <p className="pop-sub">Move one slider and the others adjust, so the total stays 100%. Changes apply to every list straight away.</p>
                    {CRIT.map((c) => (
                        <div className="w-row" key={c.k} style={{ gridTemplateColumns: '120px minmax(0,1fr) 48px' }}>
                            <label htmlFor={`w-${c.k}`}>
                                <i className={`sw sw-${c.k}`} />
                                {c.name}
                            </label>
                            <input
                                type="range"
                                id={`w-${c.k}`}
                                min={0}
                                max={100}
                                value={weights[c.k]}
                                onPointerDown={() => (snapshot.current = weights)}
                                onFocus={() => (snapshot.current = weights)}
                                onChange={(e) => {
                                    const next = adjust(snapshot.current, c.k, Number(e.target.value));
                                    setWeights(next);
                                    save(next);
                                }}
                            />
                            <output htmlFor={`w-${c.k}`}>{weights[c.k]}%</output>
                        </div>
                    ))}
                    <div className="w-row" style={{ gridTemplateColumns: '120px minmax(0,1fr)', marginTop: 18 }}>
                        <label htmlFor="horizon">Horizon h</label>
                        <span>
                            <input id="horizon" className="prop-input" type="number" min={1} max={30} step={0.5} value={h} onChange={(e) => (setH(Number(e.target.value)), save(weights, Number(e.target.value)))} style={{ width: 90 }} />{' '}
                            <span className="muted">days: how far ahead a deadline starts to matter</span>
                        </span>
                    </div>
                    <div className="w-row" style={{ gridTemplateColumns: '120px minmax(0,1fr)' }}>
                        <label htmlFor="cap">Capacity H</label>
                        <span>
                            <input id="cap" className="prop-input" type="number" min={0.5} max={16} step={0.5} value={cap} onChange={(e) => (setCap(Number(e.target.value)), save(weights, h, Number(e.target.value)))} style={{ width: 90 }} />{' '}
                            <span className="muted">hours of work you can give a day</span>
                        </span>
                    </div>
                    <div className="pop-foot">
                        <span>{props.isDefault ? 'Using the report’s defaults (AHP)' : 'Custom weights'}</span>
                        <button className="link" onClick={() => router.delete(route('weights.reset'), { preserveScroll: true })}>
                            Reset to defaults
                        </button>
                    </div>
                </section>

                <section className="card" style={{ padding: 20, marginTop: 20 }}>
                    <div className="card-title" style={{ marginBottom: 12 }}>
                        <Icon name="info" />
                        How a score is worked out
                    </div>
                    <p className="pop-sub" style={{ marginBottom: 12 }}>
                        Each part is turned into a value c between 0 and 1 against fixed limits, so adding or finishing a task never reorders the others. The score is
                        S = 100 × Σ w × c, out of 100.
                    </p>
                    <div className="contrib" style={{ gap: 12 }}>
                        {FORMULAS.map(([k, f, why]) => (
                            <div key={k} style={{ display: 'grid', gridTemplateColumns: '12px 110px minmax(0,1fr)', gap: 12, alignItems: 'baseline' }}>
                                <i className={`sw sw-${k}`} />
                                <b>{CRIT.find((c) => c.k === k)!.name}</b>
                                <span>
                                    <code style={{ fontWeight: 650 }}>{f}</code>
                                    <span className="muted" style={{ display: 'block', fontSize: 12.5 }}>
                                        {why}
                                    </span>
                                </span>
                            </div>
                        ))}
                    </div>
                </section>

                <section className="card" style={{ padding: 20, marginTop: 20 }}>
                    <div className="card-title" style={{ marginBottom: 12 }}>
                        <Icon name="target" />
                        Where the default weights come from (AHP)
                    </div>
                    <p className="pop-sub" style={{ marginBottom: 12 }}>
                        Each pair of criteria was compared on Saaty’s 1–9 scale. The geometric mean of each row gives the weights; the consistency ratio checks the
                        judgments agree with each other (it must be 0.10 or less).
                    </p>
                    <div style={{ overflowX: 'auto' }}>
                        <table className="w-full text-sm" style={{ borderCollapse: 'collapse', fontVariantNumeric: 'tabular-nums' }}>
                            <thead>
                                <tr>
                                    <th />
                                    {CRIT.map((c) => (
                                        <th key={c.k} style={{ padding: '6px 10px', textAlign: 'right' }}>
                                            {c.name}
                                        </th>
                                    ))}
                                    <th style={{ padding: '6px 10px', textAlign: 'right' }}>Weight</th>
                                </tr>
                            </thead>
                            <tbody>
                                {props.ahp.matrix.map((row, i) => (
                                    <tr key={i} style={{ borderTop: '1px solid var(--line)' }}>
                                        <th style={{ padding: '8px 10px', textAlign: 'left', whiteSpace: 'nowrap' }}>
                                            <i className={`sw sw-${CRIT[i].k}`} /> {CRIT[i].name}
                                        </th>
                                        {row.map((v, j) => (
                                            <td key={j} style={{ padding: '8px 10px', textAlign: 'right' }}>
                                                {v >= 1 ? Number(v.toFixed(2)) : `1/${Math.round(1 / v)}`}
                                            </td>
                                        ))}
                                        <td style={{ padding: '8px 10px', textAlign: 'right', fontWeight: 700 }}>{(props.ahp.weights[i] * 100).toFixed(1)}%</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="page-sub" style={{ marginTop: 12 }}>
                        λmax = {props.ahp.lambdaMax} · CI = {props.ahp.ci} · <b>CR = {props.ahp.cr}</b> {props.ahp.cr <= 0.1 ? '(consistent)' : '(not consistent)'}
                    </p>
                </section>
            </div>
        </VelocityShell>
    );
}
