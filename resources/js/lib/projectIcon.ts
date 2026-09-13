import {
    BookOpen,
    Briefcase,
    Bug,
    Calendar,
    ChartGantt,
    CircleCheck,
    CodeXml,
    FlaskConical,
    Globe,
    Heart,
    Kanban,
    Lightbulb,
    List,
    Map,
    Megaphone,
    MessageCircle,
    Monitor,
    Package,
    Palette,
    Rocket,
    Server,
    Settings,
    ShieldCheck,
    Star,
    Target,
    TrendingUp,
    Users,
    Zap,
} from '@lucide/vue';
import type { Component } from 'vue';

// Imported statically so only these icons are bundled; the order is the picker's display order.
const projectIcons = {
    list: List,
    kanban: Kanban,
    'chart-gantt': ChartGantt,
    calendar: Calendar,
    rocket: Rocket,
    users: Users,
    'trending-up': TrendingUp,
    star: Star,
    bug: Bug,
    lightbulb: Lightbulb,
    globe: Globe,
    settings: Settings,
    server: Server,
    monitor: Monitor,
    'circle-check': CircleCheck,
    target: Target,
    'code-xml': CodeXml,
    megaphone: Megaphone,
    'message-circle': MessageCircle,
    briefcase: Briefcase,
    palette: Palette,
    'flask-conical': FlaskConical,
    'book-open': BookOpen,
    heart: Heart,
    zap: Zap,
    'shield-check': ShieldCheck,
    package: Package,
    map: Map,
} as const;

export type ProjectIconName = keyof typeof projectIcons;

export const projectIconNames = Object.keys(projectIcons) as ProjectIconName[];

/** Null for no icon or an unknown one; the caller draws the name's initial instead. */
export function projectIconComponent(icon: string | null): Component | null {
    return icon === null
        ? null
        : (projectIcons[icon as ProjectIconName] ?? null);
}

export function projectIconLabel(icon: string): string {
    return icon
        .replace(/-/g, ' ')
        .replace(/^./, (character) => character.toUpperCase());
}
