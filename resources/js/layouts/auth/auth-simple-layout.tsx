import { Logo } from '@/components/velocity/icon';
import { type ReactNode } from 'react';

interface AuthLayoutProps {
    children: ReactNode;
    title: string;
    description?: string;
}

/** Log in, sign up and the password screens: one card in the middle, in the Velocity design. */
export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <main className="auth">
            <div className="auth-box">
                <div className="brand auth-brand">
                    <Logo />
                    <span className="brand-name">Velocity</span>
                </div>
                <section className="card auth-card" aria-labelledby="auth-title">
                    <h1 className="auth-title" id="auth-title">
                        {title}
                    </h1>
                    {description && <p className="auth-sub">{description}</p>}
                    {children}
                </section>
            </div>
        </main>
    );
}
