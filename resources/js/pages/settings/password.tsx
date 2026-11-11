import { Field } from '@/components/velocity/field';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout, { SaveRow, SettingsCard } from '@/layouts/settings/layout';
import { Head, useForm } from '@inertiajs/react';
import { type FormEventHandler, useRef } from 'react';

export default function Password() {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    const { data, setData, errors, put, reset, processing, recentlySuccessful } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const updatePassword: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (errors) => {
                if (errors.password) {
                    reset('password', 'password_confirmation');
                    passwordInput.current?.focus();
                }
                if (errors.current_password) {
                    reset('current_password');
                    currentPasswordInput.current?.focus();
                }
            },
        });
    };

    const input = (id: keyof typeof data, autoComplete: string, ref?: React.RefObject<HTMLInputElement | null>) => (
        <input
            id={id}
            ref={ref}
            type="password"
            className="input"
            value={data[id]}
            onChange={(e) => setData(id, e.target.value)}
            autoComplete={autoComplete}
            aria-describedby={errors[id] ? `${id}-error` : undefined}
        />
    );

    return (
        <AppLayout breadcrumbs={[{ title: 'Password', href: '/settings/password' }]}>
            <Head title="Password" />
            <SettingsLayout>
                <SettingsCard title="Change password">
                    <form onSubmit={updatePassword} className="settings-form">
                        <Field id="current_password" label="Current password" error={errors.current_password}>
                            {input('current_password', 'current-password', currentPasswordInput)}
                        </Field>
                        <Field id="password" label="New password" error={errors.password}>
                            {input('password', 'new-password', passwordInput)}
                        </Field>
                        <Field id="password_confirmation" label="New password again" error={errors.password_confirmation}>
                            {input('password_confirmation', 'new-password')}
                        </Field>
                        <SaveRow label="Save password" processing={processing} saved={recentlySuccessful} />
                    </form>
                </SettingsCard>
            </SettingsLayout>
        </AppLayout>
    );
}
