<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure\Search;

use App\Workspace\Application\SearchProviderInterface;
use App\Workspace\Application\SearchQuery;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class ChatSearchProvider implements SearchProviderInterface
{
    public function __construct(private ConnectionInterface $db) {}

    public function categories(): array
    {
        return ['chats', 'messages'];
    }

    public function moduleCode(): ?string
    {
        return 'chat';
    }

    public function isAvailable(SearchQuery $query): bool
    {
        if ($query->category !== null && !in_array($query->category, ['chats', 'messages'], true)) {
            return false;
        }
        return $query->hasPermission('chat.use');
    }

    public function search(SearchQuery $query): array
    {
        $orgsArray = SearchSqlHelper::uuidArrayLiteral($query->permittedOrgIds);
        $items = [];
        $totals = ['chats' => 0, 'messages' => 0];

        if ($query->category === null || $query->category === 'chats') {
            $roomExpr = "r.title || ' ' || COALESCE(r.description, '')";
            $roomMatch = SearchSqlHelper::matchExpr($roomExpr);
            $roomScore = SearchSqlHelper::scoreExpr($roomExpr);

            $baseRoomSql = 'FROM chat.rooms r
                            JOIN chat.members cm
                              ON cm.room_id = r.id
                             AND cm.organization_id = r.organization_id
                             AND cm.person_id = CAST(:person AS uuid)
                             AND cm.left_at IS NULL
                            WHERE r.organization_id = ANY(CAST(:orgs AS uuid[]))
                              AND r.archived_at IS NULL
                              AND ' . $roomMatch;

            $totals['chats'] = (int) ($this->db->createCommand(
                'SELECT count(*) ' . $baseRoomSql,
                [
                    ':orgs' => $orgsArray,
                    ':person' => $query->personId,
                    ':q' => $query->query,
                    ':likeQ' => $query->likePattern,
                ],
            )->queryScalar() ?: 0);

            $roomRows = $this->db->createCommand(
                "SELECT r.id::text, r.organization_id::text AS \"organizationId\",
                        r.title, r.description, r.room_type AS \"roomType\",
                        r.last_activity_at::text AS \"updatedAt\",
                        {$roomScore} AS score
                 {$baseRoomSql}
                 ORDER BY score DESC, r.last_activity_at DESC
                 LIMIT :limit",
                [
                    ':orgs' => $orgsArray,
                    ':person' => $query->personId,
                    ':q' => $query->query,
                    ':likeQ' => $query->likePattern,
                    ':limit' => $query->fetchLimit(),
                ],
            )->queryAll();

            /** @var array<string, mixed> $r */
            foreach ($roomRows as $r) {
                $desc = (string) ($r['description'] ?? '');
                $items[] = SearchSqlHelper::item(
                    entityType: 'chat.room',
                    entityId: (string) $r['id'],
                    category: 'chats',
                    title: (string) $r['title'],
                    subtitle: sprintf('Chat · %s', (string) $r['roomType']),
                    snippet: SearchSqlHelper::snippet($desc !== '' ? $desc : (string) $r['title'], $query->query),
                    route: '/chat/' . (string) $r['id'],
                    module: 'chat',
                    organizationId: (string) $r['organizationId'],
                    updatedAt: isset($r['updatedAt']) ? (string) $r['updatedAt'] : null,
                    score: (float) ($r['score'] ?? 0.0),
                );
            }
        }

        if ($query->category === null || $query->category === 'messages') {
            $msgExpr = "COALESCE(m.body, '')";
            $msgMatch = SearchSqlHelper::matchExpr($msgExpr);
            $msgScore = SearchSqlHelper::scoreExpr($msgExpr);

            $baseMsgSql = "FROM chat.messages m
                           JOIN chat.rooms r ON r.id = m.room_id AND r.organization_id = m.organization_id
                           JOIN chat.members cm
                             ON cm.room_id = m.room_id
                            AND cm.organization_id = m.organization_id
                            AND cm.person_id = CAST(:person AS uuid)
                            AND cm.left_at IS NULL
                           WHERE m.organization_id = ANY(CAST(:orgs AS uuid[]))
                             AND r.archived_at IS NULL
                             AND m.deleted_at IS NULL
                             AND m.deleted_for_all = false
                             AND NOT EXISTS (
                                 SELECT 1 FROM chat.message_deletions md
                                 WHERE md.message_id = m.id AND md.person_id = CAST(:person AS uuid)
                             )
                             AND {$msgMatch}";

            $totals['messages'] = (int) ($this->db->createCommand(
                'SELECT count(*) ' . $baseMsgSql,
                [
                    ':orgs' => $orgsArray,
                    ':person' => $query->personId,
                    ':q' => $query->query,
                    ':likeQ' => $query->likePattern,
                ],
            )->queryScalar() ?: 0);

            $msgRows = $this->db->createCommand(
                "SELECT m.id::text, m.room_id::text AS \"roomId\", m.organization_id::text AS \"organizationId\",
                        r.title AS \"roomTitle\", m.body, m.created_at::text AS \"updatedAt\",
                        {$msgScore} AS score
                 {$baseMsgSql}
                 ORDER BY score DESC, m.created_at DESC
                 LIMIT :limit",
                [
                    ':orgs' => $orgsArray,
                    ':person' => $query->personId,
                    ':q' => $query->query,
                    ':likeQ' => $query->likePattern,
                    ':limit' => $query->fetchLimit(),
                ],
            )->queryAll();

            /** @var array<string, mixed> $m */
            foreach ($msgRows as $m) {
                $body = (string) ($m['body'] ?? '');
                $items[] = SearchSqlHelper::item(
                    entityType: 'chat.message',
                    entityId: (string) $m['id'],
                    category: 'messages',
                    title: (string) $m['roomTitle'],
                    subtitle: 'Message',
                    snippet: SearchSqlHelper::snippet($body, $query->query),
                    route: '/chat/' . (string) $m['roomId'] . '?messageId=' . (string) $m['id'],
                    module: 'chat',
                    organizationId: (string) $m['organizationId'],
                    updatedAt: isset($m['updatedAt']) ? (string) $m['updatedAt'] : null,
                    score: (float) ($m['score'] ?? 0.0),
                );
            }
        }

        return ['items' => $items, 'totalsByCategory' => $totals];
    }
}
