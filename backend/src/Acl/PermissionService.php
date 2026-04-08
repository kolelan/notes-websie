<?php

declare(strict_types=1);

namespace App\Acl;

use PDO;

final class PermissionService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function canReadNote(?string $userId, string $noteId): bool
    {
        if ($userId !== null && $this->isNoteOwner($userId, $noteId)) {
            return true;
        }

        return $this->hasPermission('note', $noteId, $userId, 'can_read');
    }

    public function isNoteOwner(string $userId, string $noteId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM note WHERE id = :id AND owner_id = :owner_id LIMIT 1');
        $stmt->execute(['id' => $noteId, 'owner_id' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    public function isGroupOwner(string $userId, string $groupId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM "group" WHERE id = :id AND owner_id = :owner_id LIMIT 1');
        $stmt->execute(['id' => $groupId, 'owner_id' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    public function canReadGroup(?string $userId, string $groupId): bool
    {
        if ($userId !== null && $this->isGroupOwner($userId, $groupId)) {
            return true;
        }

        return $this->hasPermission('group', $groupId, $userId, 'can_read');
    }

    private function hasPermission(string $targetType, string $targetId, ?string $userId, string $flagColumn): bool
    {
        // public read
        $sql = <<<SQL
SELECT 1
FROM permission
WHERE target_type = :target_type
  AND target_id = :target_id
  AND (
    (grantee_type = 'public' AND grantee_id IS NULL)
    OR
    (grantee_type = 'user' AND grantee_id = :user_id)
  )
  AND {$flagColumn} = TRUE
LIMIT 1
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'user_id' => $userId,
        ]);

        return $stmt->fetchColumn() !== false;
    }
}

