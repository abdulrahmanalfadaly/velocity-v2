import AppLayout from '@/layouts/app-layout';
import SettingsLayout, { SettingsCard } from '@/layouts/settings/layout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface FocusSettings {
    mode: 'timer' | 'pomodoro';
    length: number;
    focus: number;
    short: number;
    long: number;
    every: number;
    autoBreak: boolean;
    autoNext: boolean;
    sound: boolean;
    timeUp: 'keep' | 'stop';
}

/** Settings → Focus: how "Start focus" starts, the Pomodoro lengths, what starts by itself, sound. */
export default function FocusSettingsPage() {
    const { props } = usePage<{ settings: FocusSettings; errors: Record<string, string> }>();
    const [s, setS] = useState(props.settings);
    const save = (next: FocusSettings) => {
        setS(next);
        router.put(route('focus-settings.update'), { ...next }, { preserveScroll: true, preserveState: true });
    };
    const minutes = (key: 'length' | 'focus' | 'short' | 'long' | 'every', label: string, max: number, unit = 'min') => (
        <div className="field">
            <div className="field-label">
                <label htmlFor={`fs-${key}`}>{label}</label>
            </div>
            <span className="fs-num">
                <input
                    id={`fs-${key}`}
                    className="input"
                    type="number"
                    min={1}
                    max={max}
                    defaultValue={s[key]}
                    key={`${key}-${s[key]}`}
                    aria-invalid={props.errors?.[key] ? true : undefined}
                    onBlur={(e) => {
                        const v = Math.round(Number(e.target.value));
                        if (v >= 1 && v <= max && v !== s[key]) save({ ...s, [key]: v });
                        else e.target.value = String(s[key]);
                    }}
                    onKeyDown={(e) => e.key === 'Enter' && (e.target as HTMLInputElement).blur()}
                />
                {unit}
            </span>
            {props.errors?.[key] && <p className="field-error">{props.errors[key]}</p>}
        </div>
    );
    const toggle = (key: 'autoBreak' | 'autoNext' | 'sound', label: string) => (
        <label className="mode-switch">
            <input type="checkbox" role="switch" checked={s[key]} onChange={(e) => save({ ...s, [key]: e.target.checked })} />
            <span className="switch-track" aria-hidden="true" />
            <span>{label}</span>
        </label>
    );

    return (
        <AppLayout breadcrumbs={[{ title: 'Focus', href: '/settings/focus' }]}>
            <Head title="Focus settings" />
            <SettingsLayout>
                <SettingsCard title="Start focus">
                    <div className="settings-form">
                        <div className="seg ev-seg" role="radiogroup" aria-label="Start focus as">
                            {(['timer', 'pomodoro'] as const).map((m) => (
                                <button
                                    key={m}
                                    type="button"
                                    role="radio"
                                    aria-checked={s.mode === m}
                                    onClick={() => s.mode !== m && save({ ...s, mode: m })}
                                >
                                    {m === 'timer' ? 'Timer' : 'Pomodoro'}
                                </button>
                            ))}
                        </div>
                        {minutes('length', 'Timer length', 240)}
                        <div className="field">
                            <div className="field-label">
                                <span id="fs-timeup">When a timer's time is up</span>
                            </div>
                            <div className="seg ev-seg" role="radiogroup" aria-labelledby="fs-timeup">
                                {(
                                    [
                                        ['keep', 'Keep counting'],
                                        ['stop', 'Stop'],
                                    ] as const
                                ).map(([v, label]) => (
                                    <button
                                        key={v}
                                        type="button"
                                        role="radio"
                                        aria-checked={s.timeUp === v}
                                        onClick={() => s.timeUp !== v && save({ ...s, timeUp: v })}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                        </div>
                        {toggle('sound', 'Sound when time is up')}
                    </div>
                </SettingsCard>
                <SettingsCard title="Pomodoro">
                    <div className="settings-form">
                        <div className="fs-grid">
                            {minutes('focus', 'Focus', 240)}
                            {minutes('short', 'Short break', 60)}
                            {minutes('long', 'Long break', 120)}
                            {minutes('every', 'Long break after', 12, 'rounds')}
                        </div>
                        {toggle('autoBreak', 'Start breaks by themselves')}
                        {toggle('autoNext', 'Start the next round by itself')}
                    </div>
                </SettingsCard>
            </SettingsLayout>
        </AppLayout>
    );
}
