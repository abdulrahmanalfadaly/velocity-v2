import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';
import { type ReactNode } from 'react';

export default function AuthLayout({ children, title, description }: { children: ReactNode; title: string; description?: string }) {
    return (
        <AuthLayoutTemplate title={title} description={description}>
            {children}
        </AuthLayoutTemplate>
    );
}
