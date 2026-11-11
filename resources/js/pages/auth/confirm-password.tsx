import { TextField } from '@/components/velocity/field';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm({ password: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('password.confirm'), { onFinish: () => reset('password') });
    };

    return (
        <AuthLayout title="Confirm your password" description="Enter your password to carry on.">
            <Head title="Confirm password" />
            <form className="auth-form" onSubmit={submit}>
                <TextField
                    id="password"
                    label="Password"
                    type="password"
                    required
                    autoFocus
                    autoComplete="current-password"
                    value={data.password}
                    onValue={(v) => setData('password', v)}
                    error={errors.password}
                />
                <button type="submit" className="btn btn-primary btn-lg auth-submit" disabled={processing}>
                    Confirm
                </button>
            </form>
        </AuthLayout>
    );
}
