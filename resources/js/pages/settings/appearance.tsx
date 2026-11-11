import { Icon } from '@/components/velocity/icon';
import { type Appearance as Theme, useAppearance } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout, { SettingsCard } from '@/layouts/settings/layout';
import { Head } from '@inertiajs/react';

const THEMES: { value: Theme; icon: string; label: string }[] = [
    { value: 'light', icon: 'sun', label: 'Light' },
    { value: 'dark', icon: 'moon', label: 'Dark' },
    { value: 'system', icon: 'monitor', label: 'Same as this device' },
];

export default function Appearance() {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <AppLayout breadcrumbs={[{ title: 'Appearance', href: '/settings/appearance' }]}>
            <Head title="Appearance" />
            <SettingsLayout>
                <SettingsCard title="Theme">
                    <div className="settings-form">
                        <div className="seg settings-seg" role="radiogroup" aria-label="Theme">
                            {THEMES.map((t) => (
                                <button
                                    key={t.value}
                                    type="button"
                                    role="radio"
                                    aria-checked={appearance === t.value}
                                    onClick={() => updateAppearance(t.value)}
                                >
                                    <Icon name={t.icon} />
                                    {t.label}
                                </button>
                            ))}
                        </div>
                    </div>
                </SettingsCard>
            </SettingsLayout>
        </AppLayout>
    );
}
