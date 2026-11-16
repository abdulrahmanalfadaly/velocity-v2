import AuthLayout from '@/layouts/auth-layout';
import { Head, Link } from '@inertiajs/react';

/** What each kind of error says, in plain words. */
const WORDS: Record<number, [string, string]> = {
    403: ['That isn’t yours to open', 'It belongs to another account.'],
    404: ['This page isn’t here', 'It may have been deleted, or the link is wrong.'],
    429: ['Too many tries', 'Wait a minute, then try again.'],
    500: ['Something went wrong', 'It’s on Velocity’s side. Try again in a moment.'],
    503: ['Velocity is being updated', 'It will be back in a moment.'],
};

/** A wrong link, something deleted, or a server problem: says so, with a way back into the app. */
export default function ErrorPage({ status }: { status: number }) {
    const [title, text] = WORDS[status] ?? WORDS[500];
    return (
        <AuthLayout title={title} description={text}>
            <Head title={title} />
            <div className="auth-form">
                <Link href="/" className="btn btn-primary btn-lg auth-submit">
                    Go to Home
                </Link>
                <button type="button" className="btn btn-ghost auth-submit" onClick={() => window.history.back()}>
                    Go back
                </button>
            </div>
        </AuthLayout>
    );
}
