import { useId } from 'react';
import {
    Archive, ArrowRight, ArrowUpNarrowWide, ArrowUpRight, BarChart3, Bell, BookOpen, Briefcase, Calendar, CalendarDays, Check, ChevronDown,
    ChevronLeft, ChevronRight, ChevronsUpDown, CircleAlert, Clock, Columns3, CornerDownLeft, File, FileText, Flag, Flame, Folder, Gift,
    GitFork, GraduationCap, Heart, Hourglass, House, Inbox, Info, List, Lock, LucideIcon, Maximize2, Menu, Minus, Monitor, Moon, MoreHorizontal, Paperclip,
    Pause, Pin, Play, Plus, Repeat, Search, Settings, SlidersHorizontal, SquareCheck, Sun, Target, Timer, User, Users, X, Zap,
} from 'lucide-react';

const ICONS = {
    home: House, tasks: SquareCheck, planner: CalendarDays, notes: FileText, habits: Repeat, people: Users, review: BarChart3,
    search: Search, plus: Plus, focus: Timer, settings: Settings, sliders: SlidersHorizontal, chevR: ChevronRight, chevL: ChevronLeft,
    chevD: ChevronDown, inbox: Inbox, rank: ArrowUpNarrowWide, hourglass: Hourglass, folder: Folder, target: Target, more: MoreHorizontal,
    x: X, play: Play, pause: Pause, check: Check, clock: Clock, calendar: Calendar, paperclip: Paperclip, bell: Bell, sun: Sun, moon: Moon,
    lock: Lock, info: Info, arrowR: ArrowRight, flame: Flame, alert: CircleAlert, cap: GraduationCap, briefcase: Briefcase, heart: Heart,
    gift: Gift, expand: Maximize2, zap: Zap, flag: Flag, person: User, file: File, enter: CornerDownLeft, updown: ChevronsUpDown, menu: Menu,
    list: List, minus: Minus, arrowUpRight: ArrowUpRight, branch: GitFork, pin: Pin, book: BookOpen, archive: Archive, columns: Columns3,
    monitor: Monitor,
} satisfies Record<string, LucideIcon>;

export type IconName = keyof typeof ICONS;

/** Icons share one look: 1.8px rounded strokes in the current text colour. */
export function Icon({ name, className = '', label }: { name: IconName | string; className?: string; label?: string }) {
    const Cmp = (ICONS as Record<string, LucideIcon>)[name] ?? Info;
    return <Cmp className={`ic ${className}`} aria-hidden={label ? undefined : true} aria-label={label} focusable={false} />;
}

// Home uses its own drawn house so it matches the mockups exactly.
export function HomeIcon({ className = '' }: { className?: string }) {
    return (
        <svg className={`ic ${className}`} viewBox="0 0 24 24" aria-hidden="true">
            <path d="M3.5 10.5 12 3.5l8.5 7" />
            <path d="M5.5 9v11.5h13V9" />
            <path d="M10 20.5v-6h4v6" />
        </svg>
    );
}

export function Logo({ className = 'brand-mark' }: { className?: string }) {
    // A unique gradient id per copy: a copy inside a hidden bar must not break the visible one.
    const id = useId().replace(/:/g, '');
    return (
        <svg className={className} viewBox="0 0 32 32" aria-hidden="true">
            <defs>
                <linearGradient id={`velocity-logo-${id}`} x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stopColor="#7b6cf0" />
                    <stop offset="1" stopColor="#4a3aa7" />
                </linearGradient>
            </defs>
            <rect width="32" height="32" rx="9" fill={`url(#velocity-logo-${id})`} />
            <path d="M9.5 9.5 16 23l6.5-13.5" fill="none" stroke="#fff" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />
            <path d="M5 13h3M6 17h3.4" fill="none" stroke="#fff" strokeOpacity=".55" strokeWidth="2" strokeLinecap="round" />
        </svg>
    );
}
