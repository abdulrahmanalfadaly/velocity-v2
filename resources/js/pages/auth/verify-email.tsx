import AuthLayout from '@/layouts/auth-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

export default function VerifyEmail({ status }: { status?: string }) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('verification.send'));
    };

    return (
        <AuthLayout title="Check your email" description="Open the link we just emailed you to verify your address.">
            <Head title="Verify email" />
            {status === 'verification-link-sent' && (
                <p className="auth-status" role="status">
                    A new link is on its way.
                </p>
            )}

            <form className="auth-form" onSubmit={submit}>
                <button type="submit" className="btn btn-secondary btn-lg auth-submit" disabled={processing}>
                    Send the link again
                </button>
            </form>

            <p className="auth-foot">
                <Link href={route('logout')} method="post" as="button" className="link">
                    Log out
                </Link>
            </p>
        </AuthLayout>
    );
}
