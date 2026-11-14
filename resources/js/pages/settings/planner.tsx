import AppLayout from '@/layouts/app-layout';
import SettingsLayout, { SettingsCard } from '@/layouts/settings/layout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface PlannerSettings {
    gap: number;
    shortest: number;
    longest: number;
}

const FIELDS: [keyof PlannerSettings, string, number, number][] = [
    ['gap', 'Gap around blocks and events', 0, 60],
    ['shortest', 'Shortest block', 15, 120],
    ['longest', 'Longest block', 30, 240],
];

/** Settings → Planner: what "Plan my day / week" keeps to (working hours are set on the Planner itself). */
export default function PlannerSettingsPage() {
    const { props } = usePage<{ settings: PlannerSettings; errors: Record<string, string> }>();
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
                        {FIELDS.map(([key, label, min, max]) => (
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
                                        step={5}
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
                                    min
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
