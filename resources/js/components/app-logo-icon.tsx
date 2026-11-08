import { Logo } from '@/components/velocity/icon';

/** The Velocity mark (used by the sign-in and settings screens). */
export default function AppLogoIcon({ className = '' }: { className?: string }) {
    return <Logo className={`${className} !fill-none`} />;
}
