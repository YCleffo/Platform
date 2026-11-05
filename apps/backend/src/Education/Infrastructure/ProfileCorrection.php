<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Core\Privacy\Application\CorrectionCommand;
use App\Core\Privacy\Application\CorrectionContext;
use App\Core\Privacy\Application\CorrectionResult;
use App\Core\Privacy\Application\PersonalDataCorrectionContributor;
use App\Education\Application\Input;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class ProfileCorrection implements PersonalDataCorrectionContributor
{
    public function __construct(private ConnectionInterface $db) {}
    public function code(): string { return 'education.profile'; }
    public function supportedResourceTypes(): array { return ['education.profile']; }

    public function apply(CorrectionContext $context, CorrectionCommand $command): CorrectionResult
    {
        if (array_keys($command->changes) !== ['displayName']) throw new DomainException('Unsupported Education profile correction.');
        $name = $command->changes['displayName'] === null ? null : Input::text($command->changes['displayName'], 160);
        $id = Input::uuid($command->resourceId);
        $result = $this->db->createCommand('UPDATE education.profiles SET display_name=:name
            WHERE id=CAST(:id AS uuid) AND person_id=CAST(:person AS uuid) RETURNING id::text',
            [':name' => $name, ':id' => $id, ':person' => $context->subjectPersonId])->queryScalar();
        if (!is_string($result)) throw new DomainException('Education profile is outside correction subject.');
        return new CorrectionResult('education.profile', $result, ['displayName']);
    }
}
