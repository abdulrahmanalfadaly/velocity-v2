import { TextField } from '@/components/velocity/field';
import AuthLayout from '@/layouts/auth-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        // New accounts start in this device's time zone (it can be changed in Settings).
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('register'), { onFinish: () => reset('password', 'password_confirmation') });
    };

    return (
        <AuthLayout title="Create an account">
            <Head title="Create an account" />
            <form className="auth-form" onSubmit={submit}>
                <TextField
                    id="name"
                    label="Name"
                    required
                    autoFocus
                    autoComplete="name"
                    value={data.name}
                    onValue={(v) => setData('name', v)}
                    error={errors.name}
                    disabled={processing}
                />
                <TextField
                    id="email"
                    label="Email address"
                    type="email"
                    required
                    autoComplete="email"
                    value={data.email}
                    onValue={(v) => setData('email', v)}
                    error={errors.email}
                    disabled={processing}
                />
                <TextField
                    id="password"
                    label="Password"
                    type="password"
                    required
                    autoComplete="new-password"
                    value={data.password}
                    onValue={(v) => setData('password', v)}
                    error={errors.password}
                    disabled={processing}
                />
                <TextField
                    id="password_confirmation"
                    label="Password again"
                    type="password"
                    required
                    autoComplete="new-password"
                    value={data.password_confirmation}
                    onValue={(v) => setData('password_confirmation', v)}
                    error={errors.password_confirmation}
                    disabled={processing}
                />
                <button type="submit" className="btn btn-primary btn-lg auth-submit" disabled={processing}>
                    Create account
                </button>
            </form>

            <p className="auth-foot">
                Already have an account?{' '}
                <Link href={route('login')} className="link">
                    Log in
                </Link>
            </p>
        </AuthLayout>
    );
}
