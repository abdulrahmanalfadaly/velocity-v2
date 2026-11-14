import { Select } from '@/components/velocity/select';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout, { SettingsCard } from '@/layouts/settings/layout';
import { Head, router, usePage } from '@inertiajs/react';

const CHOICES = [
    { value: '', label: 'Never' },
    { value: '1', label: 'After 1 day' },
    { value: '7', label: 'After 7 days' },
    { value: '30', label: 'After 30 days' },
];

/** Settings → Tasks: when finished tasks go to the archive by themselves. */
export default function TaskSettings() {
    const { props } = usePage<{ autoArchiveDays: number | null }>();

    return (
        <AppLayout breadcrumbs={[{ title: 'Tasks', href: '/settings/tasks' }]}>
            <Head title="Task settings" />
            <SettingsLayout>
                <SettingsCard title="Tasks">
                    <div className="settings-form">
                        <div className="field">
                            <div className="field-label">
                                <label htmlFor="auto-archive">Archive finished tasks</label>
                            </div>
                            <Select
                                id="auto-archive"
                                value={props.autoArchiveDays ? String(props.autoArchiveDays) : ''}
                                options={CHOICES}
                                onChange={(v) =>
                                    router.patch(route('task-settings.update'), { auto_archive_days: v ? Number(v) : null }, { preserveScroll: true })
                                }
                            />
                        </div>
                    </div>
                </SettingsCard>
            </SettingsLayout>
        </AppLayout>
    );
}
