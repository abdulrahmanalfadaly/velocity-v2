import VelocityShell from '@/layouts/velocity-shell';
import { adjustWeights, CRIT, type WeightSet } from '@/lib/velocity';
import type { VelocityShared } from '@/types/velocity';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

interface Props extends VelocityShared {
    weights: WeightSet;
    isDefault: boolean;
    horizonDays: number;
    hoursPerDay: number;
}

export default function WeightsEdit() {
    const { props } = usePage<Props>();
    const [weights, setWeights] = useState(props.weights);
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
                    { preserveScroll: true, preserveState: true },
                ),
            450,
        );
    };

    return (
        <VelocityShell title="Weights" section="tasks" active="weights">
            <div className="main-inner" style={{ maxWidth: 640 }}>
                <header className="page-head">
                    <div>
                        <p className="eyebrow is-section">Tasks</p>
                        <h1 className="h1">Weights</h1>
                    </div>
                </header>

                <section className="card" style={{ padding: 20 }}>
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
                                    const next = adjustWeights(snapshot.current, c.k, Number(e.target.value));
                                    setWeights(next);
                                    save(next);
                                }}
                            />
                            <output htmlFor={`w-${c.k}`}>{weights[c.k]}%</output>
                        </div>
                    ))}
                    <div className="w-row" style={{ gridTemplateColumns: '120px minmax(0,1fr)', marginTop: 18 }}>
                        <label htmlFor="horizon">Horizon</label>
                        <span>
                            <input
                                id="horizon"
                                className="prop-input"
                                type="number"
                                min={1}
                                max={30}
                                step={0.5}
                                value={h}
                                onChange={(e) => (setH(Number(e.target.value)), save(weights, Number(e.target.value)))}
                                style={{ width: 90 }}
                            />{' '}
                            <span className="muted">days</span>
                        </span>
                    </div>
                    <div className="w-row" style={{ gridTemplateColumns: '120px minmax(0,1fr)' }}>
                        <label htmlFor="cap">Hours a day</label>
                        <span>
                            <input
                                id="cap"
                                className="prop-input"
                                type="number"
                                min={0.5}
                                max={16}
                                step={0.5}
                                value={cap}
                                onChange={(e) => (setCap(Number(e.target.value)), save(weights, h, Number(e.target.value)))}
                                style={{ width: 90 }}
                            />{' '}
                            <span className="muted">hours</span>
                        </span>
                    </div>
                    <div className="pop-foot">
                        <span />
                        <button
                            className="link"
                            disabled={props.isDefault && h === 7 && cap === 4}
                            onClick={() => router.delete(route('weights.reset'), { preserveScroll: true })}
                        >
                            Reset
                        </button>
                    </div>
                </section>
            </div>
        </VelocityShell>
    );
}
