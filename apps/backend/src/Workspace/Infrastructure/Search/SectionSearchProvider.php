<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure\Search;

use App\Workspace\Application\SearchProviderInterface;
use App\Workspace\Application\SearchQuery;

final readonly class SectionSearchProvider implements SearchProviderInterface
{
    /** @return list<array{id: string, title: string, keywords: string, subtitle: string, route: string, module: string, requiredModule: ?string, requiredPermission: ?string}> */
    private static function catalog(): array
    {
        return [
            [
                'id' => 'workspace.dashboard',
                'title' => 'Dashboard / Главная панель',
                'keywords' => 'dashboard workspace overview главная панель обзор дашборд',
                'subtitle' => 'Workspace',
                'route' => '/dashboard',
                'module' => 'workspace',
                'requiredModule' => 'workspace',
                'requiredPermission' => 'workspace.use',
            ],
            [
                'id' => 'workspace.trash',
                'title' => 'Trash / Корзина',
                'keywords' => 'trash deleted restore bin корзина удаленные восстановить',
                'subtitle' => 'Workspace Trash',
                'route' => '/workspace/trash',
                'module' => 'workspace',
                'requiredModule' => 'workspace',
                'requiredPermission' => 'workspace.use',
            ],
            [
                'id' => 'workspace.operations',
                'title' => 'Operations & Undo / Операции и отмена',
                'keywords' => 'operations undo history actions операции отмена действия',
                'subtitle' => 'Action Engine',
                'route' => '/workspace/operations',
                'module' => 'workspace',
                'requiredModule' => 'workspace',
                'requiredPermission' => 'workspace.use',
            ],
            [
                'id' => 'notifications.center',
                'title' => 'Notifications / Уведомления',
                'keywords' => 'notifications alerts push unread уведомления оповещения',
                'subtitle' => 'Notifications Center',
                'route' => '/notifications',
                'module' => 'notifications',
                'requiredModule' => 'notifications',
                'requiredPermission' => 'notifications.use',
            ],
            [
                'id' => 'chat.rooms',
                'title' => 'Messages & Chats / Сообщения и чаты',
                'keywords' => 'chat messages rooms dialogs чаты сообщения диалоги',
                'subtitle' => 'Universal Chat',
                'route' => '/chat',
                'module' => 'chat',
                'requiredModule' => 'chat',
                'requiredPermission' => 'chat.use',
            ],
            [
                'id' => 'activity.studio',
                'title' => 'Activities & Tests / Активности и тесты',
                'keywords' => 'activity tests quizzes assessment активности тесты задания тренажер',
                'subtitle' => 'Activity Engine',
                'route' => '/activities',
                'module' => 'activity',
                'requiredModule' => 'activity',
                'requiredPermission' => 'activity.use',
            ],
            [
                'id' => 'storage.files',
                'title' => 'Files & Storage / Файлы и хранилище',
                'keywords' => 'files storage documents media файлы документы хранилище',
                'subtitle' => 'Storage',
                'route' => '/files',
                'module' => 'storage',
                'requiredModule' => 'storage',
                'requiredPermission' => null,
            ],
            [
                'id' => 'education.schedule',
                'title' => 'Education Schedule / Расписание и уроки',
                'keywords' => 'education schedule lessons groups homework расписание уроки группы домашнее задание',
                'subtitle' => 'Education',
                'route' => '/education/schedule',
                'module' => 'education',
                'requiredModule' => 'education',
                'requiredPermission' => 'education.access',
            ],
            [
                'id' => 'device_management.devices',
                'title' => 'Devices & Classrooms / Устройства и классы',
                'keywords' => 'devices computers classrooms workstations устройства компьютеры классы',
                'subtitle' => 'Device Management',
                'route' => '/devices',
                'module' => 'device_management',
                'requiredModule' => 'device_management',
                'requiredPermission' => 'education.devices.manage',
            ],
            [
                'id' => 'core.privacy',
                'title' => 'Privacy & GDPR / Приватность и ПДн',
                'keywords' => 'privacy gdpr export erasure consent приватность персональные данные',
                'subtitle' => 'Core Privacy',
                'route' => '/privacy',
                'module' => 'core',
                'requiredModule' => null,
                'requiredPermission' => 'core.privacy.read',
            ],
        ];
    }

    public function categories(): array
    {
        return ['sections'];
    }

    public function moduleCode(): ?string
    {
        return null;
    }

    public function isAvailable(SearchQuery $query): bool
    {
        return $query->category === null || $query->category === 'sections';
    }

    public function search(SearchQuery $query): array
    {
        $needle = mb_strtolower($query->query);
        $items = [];
        $total = 0;

        foreach (self::catalog() as $entry) {
            if ($entry['requiredModule'] !== null && !$query->isModuleEnabled($entry['requiredModule'])) {
                continue;
            }
            if ($entry['requiredPermission'] !== null && !$query->hasPermission($entry['requiredPermission'])) {
                continue;
            }

            $haystack = mb_strtolower($entry['title'] . ' ' . $entry['keywords'] . ' ' . $entry['subtitle']);
            $score = 0.0;
            if (str_contains($haystack, $needle)) {
                $score = 0.85;
            } else {
                $tokens = preg_split('/\s+/u', $needle, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $matchedTokens = 0;
                foreach ($tokens as $tok) {
                    if ($tok !== '' && str_contains($haystack, $tok)) {
                        ++$matchedTokens;
                    }
                }
                if ($tokens !== [] && $matchedTokens > 0) {
                    $score = 0.4 + (0.3 * ($matchedTokens / count($tokens)));
                }
            }

            if ($score <= 0.0) {
                continue;
            }

            ++$total;
            $items[] = SearchSqlHelper::item(
                entityType: 'workspace.section',
                entityId: $entry['id'],
                category: 'sections',
                title: $entry['title'],
                subtitle: $entry['subtitle'],
                snippet: $entry['subtitle'] . ' -> ' . $entry['route'],
                route: $entry['route'],
                module: $entry['module'],
                organizationId: $query->organizationId,
                updatedAt: null,
                score: $score,
            );
        }

        return ['items' => $items, 'totalsByCategory' => ['sections' => $total]];
    }
}
