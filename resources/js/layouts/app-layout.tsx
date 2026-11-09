import FypShell from '@/layouts/fyp-shell';
import VelocityShell from '@/layouts/velocity-shell';
import { type BreadcrumbItem } from '@/types';
import type { VelocityShared } from '@/types/velocity';
import { usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

interface AppLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}

/** The starter kit's pages (settings) use the same frame as the rest of the app, in either mode. */
export default function AppLayout({ children, breadcrumbs }: AppLayoutProps) {
    const { props } = usePage<VelocityShared>();
    const title = breadcrumbs?.[breadcrumbs.length - 1]?.title ?? 'Settings';
    const content = <div className="main-inner">{children}</div>;

    return props.auth.user?.fypMode ? (
        <FypShell title={title} page="settings">
            {content}
        </FypShell>
    ) : (
        <VelocityShell title={title} section="settings">
            {content}
        </VelocityShell>
    );
}
