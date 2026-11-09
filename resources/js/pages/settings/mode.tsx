import HeadingSmall from '@/components/heading-small';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Head, router, usePage } from '@inertiajs/react';

export default function Mode() {
    const { props } = usePage<{ fypMode: boolean }>();
    const on = props.fypMode;

    return (
        <AppLayout breadcrumbs={[{ title: 'FYP mode', href: '/settings/mode' }]}>
            <Head title="FYP mode" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="FYP mode" description="Velocity as described in the FYP report: My tasks, History, and Weights & method." />
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
            </SettingsLayout>
        </AppLayout>
    );
}
