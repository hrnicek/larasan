<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Lucide icon ids; each case needs a static entry in resources/js/lib/projectIcon.ts.
 */
enum ProjectIcon: string
{
    case List = 'list';
    case Kanban = 'kanban';
    case ChartGantt = 'chart-gantt';
    case Calendar = 'calendar';
    case Rocket = 'rocket';
    case Users = 'users';
    case TrendingUp = 'trending-up';
    case Star = 'star';
    case Bug = 'bug';
    case Lightbulb = 'lightbulb';
    case Globe = 'globe';
    case Settings = 'settings';
    case Server = 'server';
    case Monitor = 'monitor';
    case CircleCheck = 'circle-check';
    case Target = 'target';
    case CodeXml = 'code-xml';
    case Megaphone = 'megaphone';
    case MessageCircle = 'message-circle';
    case Briefcase = 'briefcase';
    case Palette = 'palette';
    case FlaskConical = 'flask-conical';
    case BookOpen = 'book-open';
    case Heart = 'heart';
    case Zap = 'zap';
    case ShieldCheck = 'shield-check';
    case Package = 'package';
    case Map = 'map';
}
