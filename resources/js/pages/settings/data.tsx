import { Icon } from '@/components/velocity/icon';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout, { SettingsCard } from '@/layouts/settings/layout';
import type { VelocityShared } from '@/types/velocity';
import { Head, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';

interface Props extends VelocityShared {
    /** Nothing in the account yet: the only kind an import goes into. */
    empty: boolean;
    maxUpload: string | null;
}

export default function Data() {
    const { props } = usePage<Props>();
    const file = useRef<HTMLInputElement>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const bringIn = (f: File | undefined) => {
        if (!f) return;
        setError(null);
        setBusy(true);
        router.post(
            route('data.import'),
            { file: f },
            {
                forceFormData: true,
                onError: (e) => setError(e.file ?? 'That file couldn’t be brought in.'),
                onFinish: () => {
                    setBusy(false);
                    if (file.current) file.current.value = '';
                },
            },
        );
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Data', href: '/settings/data' }]}>
            <Head title="Data" />
            <SettingsLayout>
                <SettingsCard title="Download everything">
                    <div className="settings-form">
                        <p className="settings-note">One .zip with everything and its files. API keys and the GitHub connection aren’t in it.</p>
                        <div>
                            <a className="btn btn-primary" href={route('data.export')} download>
                                <Icon name="download" className="ic-sm" />
                                Download everything
                            </a>
                        </div>
                    </div>
                </SettingsCard>
                <SettingsCard title="Bring in an export">
                    <div className="settings-form">
                        {props.empty ? (
                            <>
                                <p className="settings-note">
                                    Choose a .zip from “Download everything”{props.maxUpload ? ` (up to ${props.maxUpload})` : ''}.
                                </p>
                                <input
                                    ref={file}
                                    type="file"
                                    accept=".zip,application/zip"
                                    className="sr-only"
                                    tabIndex={-1}
                                    aria-label="Velocity export"
                                    onChange={(e) => bringIn(e.target.files?.[0])}
                                />
                                <div>
                                    <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => file.current?.click()}>
                                        {busy ? 'Bringing it in…' : 'Choose the .zip'}
                                    </button>
                                </div>
                                {error && <p className="field-error">{error}</p>}
                            </>
                        ) : (
                            <p className="settings-note">Only into a new, empty account.</p>
                        )}
                    </div>
                </SettingsCard>
            </SettingsLayout>
        </AppLayout>
    );
}
