import { TextField } from '@/components/velocity/field';
import AuthLayout from '@/layouts/auth-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

export default function ForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('password.email'));
    };

    return (
        <AuthLayout title="Forgot your password?" description="We’ll email you a link to set a new one.">
            <Head title="Forgot password" />
            {status && (
                <p className="auth-status" role="status">
                    {status}
                </p>
            )}

            <form className="auth-form" onSubmit={submit}>
                <TextField
                    id="email"
                    label="Email address"
                    type="email"
                    required
                    autoFocus
                    autoComplete="off"
                    value={data.email}
                    onValue={(v) => setData('email', v)}
                    error={errors.email}
                />
                <button type="submit" className="btn btn-primary btn-lg auth-submit" disabled={processing}>
                    Email me a link
                </button>
            </form>

            <p className="auth-foot">
                <Link href={route('login')} className="link">
                    Back to log in
                </Link>
            </p>
        </AuthLayout>
    );
}
