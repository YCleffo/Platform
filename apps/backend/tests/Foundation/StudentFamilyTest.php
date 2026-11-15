<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Core\Person\Application\{CreatePerson, CreatePersonCommand, CreatePersonContact, CreatePersonContactCommand};

require_once __DIR__ . '/HomeworkFixture.php';

final class StudentFamilyTest extends HomeworkFixture
{
    public function testParentUsesCoreIdentityAndIsVisibleOnlyOnAnAuthorizedStudentCard(): void
    {
        $parent = $this->container->get(CreatePerson::class)->execute(new CreatePersonCommand('Demo', 'Parent', null, 'Demo Parent', null));
        $this->db->createCommand('INSERT INTO core.organization_memberships(organization_id,person_id) VALUES(CAST(:org AS uuid),CAST(:person AS uuid))',
            [':org' => $this->teacher->organizationId, ':person' => $parent->id])->execute();
        $this->container->get(CreatePersonContact::class)->execute(new CreatePersonContactCommand($parent->id, 'email', 'parent@example.test', null, true));
        $this->db->createCommand('INSERT INTO education.student_guardians(organization_id,student_id,guardian_person_id,relationship)
            VALUES(CAST(:org AS uuid),CAST(:student AS uuid),CAST(:parent AS uuid),\'guardian\')',
            [':org' => $this->teacher->organizationId, ':student' => $this->learner['id'], ':parent' => $parent->id])->execute();
        $card = $this->education->read('student', $this->teacher, [], $this->learner['id']);
        self::assertSame($parent->id, $card['guardians'][0]['personId']);
        self::assertSame('parent@example.test', $card['guardians'][0]['contacts'][0]['value']);
        $this->rejection(404, fn() => $this->education->read('student', $this->outsider, [], $this->learner['id']));
        $this->rejection(404, fn() => $this->education->read('student', $this->colleague, [], $this->learner['id']));
    }
}
