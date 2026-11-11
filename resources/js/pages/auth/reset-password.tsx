import { TextField } from '@/components/velocity/field';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('password.store'), { onFinish: () => reset('password', 'password_confirmation') });
    };

    return (
        <AuthLayout title="Set a new password">
            <Head title="Set a new password" />
            <form className="auth-form" onSubmit={submit}>
                <TextField
                    id="email"
                    label="Email address"
                    type="email"
                    autoComplete="email"
                    readOnly
                    value={data.email}
                    onValue={(v) => setData('email', v)}
                    error={errors.email}
                />
                <TextField
                    id="password"
                    label="New password"
                    type="password"
                    required
                    autoFocus
                    autoComplete="new-password"
                    value={data.password}
                    onValue={(v) => setData('password', v)}
                    error={errors.password}
                />
                <TextField
                    id="password_confirmation"
                    label="New password again"
                    type="password"
                    required
                    autoComplete="new-password"
                    value={data.password_confirmation}
                    onValue={(v) => setData('password_confirmation', v)}
                    error={errors.password_confirmation}
                />
                <button type="submit" className="btn btn-primary btn-lg auth-submit" disabled={processing}>
                    Save new password
                </button>
            </form>
        </AuthLayout>
    );
}
