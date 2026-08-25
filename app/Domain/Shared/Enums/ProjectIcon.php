<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * The glyph a project may wear beside its name, as a fixed library rather than free text.
 *
 * The column stores the *name*, and the name is a Lucide icon id — the icon set the
 * application already ships, so a project's icon costs no new dependency and no upload.
 * A closed set is what lets the picker be a grid and the server reject anything else: a
 * free string reaches the client as a component lookup that silently renders nothing.
 * The name → component mapping is a static record in `resources/js/lib/projectIcon.ts`,
 * because a dynamic import per name would defeat the bundler.
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
