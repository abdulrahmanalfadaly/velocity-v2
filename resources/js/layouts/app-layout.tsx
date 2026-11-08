import VelocityShell from '@/layouts/velocity-shell';
import { type BreadcrumbItem } from '@/types';
import { type ReactNode } from 'react';

interface AppLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}

/** The starter kit's pages (settings) use the same Velocity frame as everything else. */
export default function AppLayout({ children, breadcrumbs }: AppLayoutProps) {
    return (
        <VelocityShell title={breadcrumbs?.[breadcrumbs.length - 1]?.title ?? 'Settings'} section="settings">
            <div className="main-inner">{children}</div>
        </VelocityShell>
    );
}
