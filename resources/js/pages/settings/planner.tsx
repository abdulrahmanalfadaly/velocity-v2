import AppLayout from '@/layouts/app-layout';
import SettingsLayout, { SettingsCard } from '@/layouts/settings/layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface PlannerSettings {
    gap: number;
    shortest: number;
    longest: number;
    /** 0 = "Plan" fills the working hours; 1 = at most "Hours a day" (Settings → Tasks). */
    cap: number;
}

const FIELDS: [Exclude<keyof PlannerSettings, 'cap'>, string, number, number, string, number][] = [
    ['gap', 'Gap around blocks and events', 0, 60, 'min', 5],
    ['shortest', 'Shortest block', 15, 120, 'min', 5],
    ['longest', 'Longest block', 30, 240, 'min', 5],
];

/** Settings → Planner: what "Plan my day / week" keeps to (working hours are set on the Planner itself). */
export default function PlannerSettingsPage() {
    const { props } = usePage<{ settings: PlannerSettings; hoursPerDay: number; errors: Record<string, string> }>();
    const [s, setS] = useState(props.settings);
    const save = (next: PlannerSettings) => {
        setS(next);
        router.put(route('planner-settings.update'), { ...next }, { preserveScroll: true, preserveState: true, onError: () => setS(props.settings) });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Planner', href: '/settings/planner' }]}>
            <Head title="Planner settings" />
            <SettingsLayout>
                <SettingsCard title="Planning">
                    <div className="settings-form fs-grid">
                        <div className="field ps-cap">
                            <div className="field-label">
                                <label id="ps-cap">Plan books each day</label>
                            </div>
                            <div className="seg" role="radiogroup" aria-labelledby="ps-cap">
                                <button type="button" role="radio" aria-checked={!s.cap} onClick={() => s.cap && save({ ...s, cap: 0 })}>
                                    Fill working hours
                                </button>
                                <button type="button" role="radio" aria-checked={!!s.cap} onClick={() => !s.cap && save({ ...s, cap: 1 })}>
                                    Up to Hours a day ({props.hoursPerDay} h)
                                </button>
                            </div>
                            <p className="field-hint">
                                Hours a day is set in <Link href="/settings/tasks">Settings › Tasks</Link>.
                            </p>
                        </div>
                        {FIELDS.map(([key, label, min, max, unit, step]) => (
                            <div className="field" key={key}>
                                <div className="field-label">
                                    <label htmlFor={`ps-${key}`}>{label}</label>
                                </div>
                                <span className="fs-num">
                                    <input
                                        id={`ps-${key}`}
                                        className="input"
                                        type="number"
                                        min={min}
                                        max={max}
                                        step={step}
                                        defaultValue={s[key]}
                                        key={`${key}-${s[key]}`}
                                        aria-invalid={props.errors?.[key] ? true : undefined}
                                        onBlur={(e) => {
                                            const v = Math.round(Number(e.target.value));
                                            if (v >= min && v <= max && v !== s[key]) save({ ...s, [key]: v });
                                            else e.target.value = String(s[key]);
                                        }}
                                        onKeyDown={(e) => e.key === 'Enter' && (e.target as HTMLInputElement).blur()}
                                    />
                                    {unit}
                                </span>
                                {props.errors?.[key] && <p className="field-error">{props.errors[key]}</p>}
                            </div>
                        ))}
                    </div>
                </SettingsCard>
            </SettingsLayout>
        </AppLayout>
    );
}
