<?php

declare(strict_types=1);

namespace App\Education\Application;

use Yiisoft\Db\Connection\ConnectionInterface;

/** Safe projection. Callers authorize the student/assignment before reading it. */
final readonly class ActivityResultView
{
    public function __construct(private ConnectionInterface $db) {}

    public function forAttempt(string $organizationId,string $attemptId):?array
    {
        $row=$this->db->createCommand('SELECT r.metrics FROM education.activity_results r JOIN education.activity_attempts a ON a.id=r.attempt_id
            WHERE a.id=CAST(:id AS uuid) AND a.organization_id=CAST(:org AS uuid) AND r.organization_id=a.organization_id
            AND a.status=\'finished\' AND a.revoked_at IS NULL',[':id'=>$attemptId,':org'=>$organizationId])->queryOne();
        if(is_array($row))return ['attemptId'=>$attemptId,...ActivityHandlerRegistry::json($row['metrics'])];
        $old=$this->db->createCommand('SELECT t.attempt_id::text AS "attemptId",t.duration_seconds AS "durationSeconds",t.language,t.keystrokes,
            t.correct_chars AS "correctChars",t.errors,t.wpm::float,t.accuracy::float FROM education.typing_results t
            JOIN education.activity_attempts a ON a.id=t.attempt_id WHERE a.id=CAST(:id AS uuid) AND a.organization_id=CAST(:org AS uuid)
            AND t.organization_id=a.organization_id AND a.status=\'finished\' AND a.revoked_at IS NULL AND a.contract_version=1',
            [':id'=>$attemptId,':org'=>$organizationId])->queryOne();
        return is_array($old)?[...$old,'typedCharacters'=>(int)$old['keystrokes'],'passed'=>(int)$old['keystrokes']>0]:null;
    }
}
