import AppLayout from '@/layouts/app-layout';
import SettingsLayout, { SettingsCard } from '@/layouts/settings/layout';
import { Head, router, usePage } from '@inertiajs/react';

export default function Mode() {
    const { props } = usePage<{ fypMode: boolean }>();
    const on = props.fypMode;

    return (
        <AppLayout breadcrumbs={[{ title: 'FYP mode', href: '/settings/mode' }]}>
            <Head title="FYP mode" />
            <SettingsLayout>
                <SettingsCard title="FYP mode">
                    <div className="settings-form">
                        <p className="settings-note">Velocity as described in the FYP report: My tasks, History, and Weights & method.</p>
                        <label className="mode-switch">
                            <input
                                type="checkbox"
                                role="switch"
                                aria-label="FYP mode"
                                checked={on}
                                onChange={(e) => router.patch(route('mode.update'), { fyp_mode: e.target.checked }, { preserveScroll: true })}
                            />
                            <span className="switch-track" aria-hidden="true" />
                            <span>{on ? 'On' : 'Off'}</span>
                        </label>
                    </div>
                </SettingsCard>
            </SettingsLayout>
        </AppLayout>
    );
}
