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

/**
 * The library `App\Domain\Shared\Enums\ProjectIcon` closes over, as components.
 *
 * Written out rather than looked up dynamically for the same reason `accentColor.ts` writes its
 * classes out: a name resolved at runtime cannot be bundled, and `import(`@lucide/vue/${name}`)`
 * would ship the whole icon set to fetch one glyph. The order here is the order the picker draws,
 * so it is grouped by what a project tends to be — a way of working, then a subject.
 */
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

/**
 * The component for a stored name, or null for a project that has no icon — the caller draws the
 * first letter of the name instead. Null rather than a fallback glyph: a project the server says
 * has no icon and one whose icon this build no longer knows are the same thing to a reader, and
 * both are better identified by their initial than by a shared placeholder.
 */
export function projectIconComponent(icon: string | null): Component | null {
    return icon === null ? null : (projectIcons[icon as ProjectIconName] ?? null);
}

/** How each icon is written in the picker's tooltip and its accessible name. */
export function projectIconLabel(icon: string): string {
    return icon.replace(/-/g, ' ').replace(/^./, (character) => character.toUpperCase());
}
