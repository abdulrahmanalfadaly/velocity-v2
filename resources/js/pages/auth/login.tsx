import { Field } from '@/components/velocity/field';
import AuthLayout from '@/layouts/auth-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface LoginProps {
    status?: string;
    canResetPassword: boolean;
}

export default function Login({ status, canResetPassword }: LoginProps) {
    const { data, setData, post, processing, errors, reset } = useForm<{ email: string; password: string; remember: boolean }>({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), { onFinish: () => reset('password') });
    };

    return (
        <AuthLayout title="Log in">
            <Head title="Log in" />
            {status && (
                <p className="auth-status" role="status">
                    {status}
                </p>
            )}

            <form className="auth-form" onSubmit={submit}>
                <Field id="email" label="Email address" error={errors.email}>
                    <input
                        id="email"
                        type="email"
                        className="input"
                        required
                        autoFocus
                        autoComplete="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        aria-describedby={errors.email ? 'email-error' : undefined}
                    />
                </Field>

                <Field
                    id="password"
                    label="Password"
                    error={errors.password}
                    extra={
                        canResetPassword && (
                            <Link href={route('password.request')} className="link auth-aside">
                                Forgot it?
                            </Link>
                        )
                    }
                >
                    <input
                        id="password"
                        type="password"
                        className="input"
                        required
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        aria-describedby={errors.password ? 'password-error' : undefined}
                    />
                </Field>

                <label className="auth-check">
                    <input type="checkbox" checked={data.remember} onChange={(e) => setData('remember', e.target.checked)} />
                    Keep me logged in
                </label>

                <button type="submit" className="btn btn-primary btn-lg auth-submit" disabled={processing}>
                    Log in
                </button>
            </form>

            <p className="auth-foot">
                New here?{' '}
                <Link href={route('register')} className="link">
                    Create an account
                </Link>
            </p>
        </AuthLayout>
    );
}
