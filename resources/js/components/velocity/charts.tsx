import type { CSSProperties } from 'react';

export interface Bar {
    key: string;
    /** Under the bar: "M", "12", "9 Nov" */
    label: string;
    value: number;
    /** Said on hover and to screen readers: "Tue 10 Nov: 3 tasks done" */
    title: string;
    future?: boolean;
    now?: boolean;
}

/**
 * Bars for one measure over time: thin, rounded at the top, on a quiet baseline.
 * The biggest value is written above its bar; the rest show on hover. The same
 * numbers are in a table underneath for anyone who wants them as text.
 */
export function BarChart({ bars, label, height = 120, table }: { bars: Bar[]; label: string; height?: number; table: [string, string] }) {
    const max = Math.max(1, ...bars.map((b) => b.value));
    const top = bars.reduce((a, b) => (b.value > a.value ? b : a), bars[0]);
    const dense = bars.length > 14;
    return (
        <figure className="vc">
            <div className={`vc-bars${dense ? ' is-dense' : ''}`} style={{ height } as CSSProperties} role="img" aria-label={label}>
                {bars.map((b) => (
                    <span key={b.key} className={`vc-col${b.future ? ' is-future' : ''}${b.now ? ' is-now' : ''}`} title={b.title}>
                        <i
                            style={{ height: b.value > 0 ? `${Math.max(3, (b.value / max) * 100)}%` : undefined }}
                            className={b.value > 0 ? '' : 'is-zero'}
                        >
                            {b === top && b.value > 0 && <b className="vc-top">{Math.round(b.value * 10) / 10}</b>}
                        </i>
                    </span>
                ))}
            </div>
            <div className={`vc-labels${dense ? ' is-dense' : ''}`} aria-hidden="true">
                {bars.map((b, i) => (
                    <span key={b.key} className={b.now ? 'is-now' : undefined}>
                        {!dense || i % 7 === 0 || b.now ? b.label : ''}
                    </span>
                ))}
            </div>
            <details className="vc-table">
                <summary>As a table</summary>
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{table[0]}</th>
                            <th scope="col">{table[1]}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {bars
                            .filter((b) => !b.future)
                            .map((b) => (
                                <tr key={b.key}>
                                    <th scope="row">{b.title.split(':')[0]}</th>
                                    <td>{Math.round(b.value * 10) / 10}</td>
                                </tr>
                            ))}
                    </tbody>
                </table>
            </details>
        </figure>
    );
}

/** One bar per thing, longest first, each in its own colour (a goal's colour for a project). */
export function SplitBars({ rows, unit }: { rows: { key: string; name: string; value: number; color: string; text: string }[]; unit: string }) {
    const max = Math.max(1, ...rows.map((r) => r.value));
    return (
        <ul className="vc-split" aria-label={unit}>
            {rows.map((r) => (
                <li key={r.key}>
                    <span className="vc-split-name">{r.name}</span>
                    <span className="vc-split-track">
                        <i style={{ width: `${Math.max(2, (r.value / max) * 100)}%`, background: r.color }} />
                    </span>
                    <span className="vc-split-value">{r.text}</span>
                </li>
            ))}
        </ul>
    );
}
