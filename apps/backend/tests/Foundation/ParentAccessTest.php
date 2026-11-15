<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Client\Application\ClientContextResolver;
use App\Core\Privacy\Application\PersonalDataExportContext;
use App\Core\Rbac\Application\PermissionService;
use App\Education\Application\ParentFamily;
use App\Education\Application\ParentReadService;
use App\Education\Application\ParentScope;
use App\Education\Application\Scope;
use App\Education\Infrastructure\PersonalDataContributor;
use App\Education\Infrastructure\WorkspaceErasureHandler;
use DateInterval;
use HttpSoft\Message\ServerRequest;

require_once __DIR__ . '/HomeworkFixture.php';

/** Independent adversarial tests against production services and real PostgreSQL. */
final class ParentAccessTest extends HomeworkFixture
{
    public function testParentSeesConfirmedGamesAndAwardsWithResourcePrivacyAndNoPrivateExercise(): void
    {
        $parent = $this->parent(); $this->activate($parent); $scope = $this->scopeFor($parent);
        $typing = $this->education->write('student_start_attempt', $this->learner['scope'], ['language'=>'en','durationSeconds'=>15], 'platform-typing');
        $this->education->write('student_finish_attempt', $this->learner['scope'], ['typed'=>substr($typing['exercise']['text'],0,25),'durationSeconds'=>15], $typing['attempt']['id']);
        $logic = $this->education->write('student_start_attempt', $this->learner['scope'], ['language'=>'en','difficulty'=>2,'roundCount'=>5], 'platform-logic');
        $snapshot = json_decode($this->db->createCommand('SELECT exercise_payload FROM education.activity_attempts WHERE id=CAST(:id AS uuid)', [':id'=>$logic['attempt']['id']])->queryScalar(),true,flags:JSON_THROW_ON_ERROR);
        $answers = array_map(static fn(array $round):array=>['roundId'=>$round['id'],'optionId'=>$round['correctOptionId']],$snapshot['rounds']);
        $result = $this->education->write('student_finish_attempt',$this->learner['scope'],['answers'=>$answers],$logic['attempt']['id']);
        $this->sameResponse($result,$this->education->write('student_finish_attempt',$this->learner['scope'],['answers'=>$answers],$logic['attempt']['id']));
        $period=['from'=>gmdate('Y-m-d',time()-86400),'to'=>gmdate('Y-m-d',time()+86400)];
        $progress=$this->parents->read('progress',$scope,$period);
        self::assertSame(1,$progress['activityProgress']['typing']['attempts']);
        self::assertSame(1,$progress['activityProgress']['logic']['attempts']);
        self::assertEquals($result['score'],$progress['activityProgress']['logic']['bestScore']);
        self::assertNotEmpty(array_filter($progress['achievements'],static fn(array $award):bool=>$award['code']==='first-logic'&&$award['unlockedAt']!==null));
        $json=json_encode($progress,JSON_THROW_ON_ERROR);
        foreach(['correctOptionId','exercise_payload','clientState','explanation','accountId'] as $secret)self::assertStringNotContainsString($secret,$json);
        $notices=$this->parents->read('notifications',$scope)['items'];
        self::assertContains('parent_activity_finished',array_column($notices,'kind'));
        self::assertContains('parent_achievement_unlocked',array_column($notices,'kind'));
        $keys=array_map(static fn(array $notice):string=>$notice['kind'].':'.$notice['resourceId'],$notices);
        self::assertCount(count(array_unique($keys)),$keys);
        $this->block($parent['personId'],'education.attempt',$logic['attempt']['id']);
        $filtered=$this->parents->read('progress',$scope,$period);
        self::assertSame(0,$filtered['activityProgress']['logic']['attempts']);
        self::assertNull($filtered['activityProgress']['logic']['bestScore']);
        self::assertStringNotContainsString($logic['attempt']['id'],json_encode($filtered,JSON_THROW_ON_ERROR));
        $this->block($this->learner['personId'],'education.attempt',$typing['attempt']['id']);
        self::assertSame(0,$this->parents->read('progress',$scope,$period)['activityProgress']['typing']['attempts']);
    }

    public function testActivityExportAndErasureIncludeNewEvidenceWithoutAnswerKeys(): void
    {
        $typing=$this->education->write('student_start_attempt',$this->learner['scope'],['language'=>'en','durationSeconds'=>15],'platform-typing');
        $this->education->write('student_finish_attempt',$this->learner['scope'],['typed'=>substr($typing['exercise']['text'],0,25),'durationSeconds'=>15],$typing['attempt']['id']);
        $context=new PersonalDataExportContext($this->learner['personId'],$this->id(),$this->learner['personId'],$this->learner['accountId'],null,$this->clock->now());
        $export=$this->container->get(PersonalDataContributor::class)->collect($context);
        foreach(['activity_attempt','activity_result','achievement_event','achievement_unlock'] as $type)self::assertContains($type,array_column($export->records,'type'));
        self::assertStringNotContainsString('exercise_payload',json_encode($export->toArray(),JSON_THROW_ON_ERROR));
        $this->container->get(WorkspaceErasureHandler::class)->execute($this->learner['personId'],$this->learner['personId'],'delete',$this->id());
        foreach(['activity_results'=>'attempt_id','achievement_unlocks'=>'student_id','achievement_events'=>'student_id'] as $table=>$column)
            self::assertSame(0,$this->rowCount($table,$column,$table==='activity_results'?$typing['attempt']['id']:$this->learner['id']));
    }

    private ParentFamily $family;
    private ParentReadService $parents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->family = $this->withClock(ParentFamily::class);
        $this->parents = $this->container->get(ParentReadService::class);
    }

    public function testAnExistingCorePersonAndPendingRelationDoNotGrantParentAccess(): void
    {
        $parent = $this->parent();
        $this->link($parent);
        $items = $this->family->list($this->teacher, $this->learner['id'])['items'];
        self::assertSame('pending', $items[0]['status']);
        $this->rejection([403, 404], fn() => $this->scopeFor($parent));
        $this->rejection([403, 409], fn() => $this->invite($parent));
        $this->rejection([403, 404], fn() => $this->parents->context($parent['identity']));
    }

    public function testTeacherConfirmationNeedsAnExplicitAdditionalPermission(): void
    {
        $parent = $this->parent();
        $this->link($parent);
        $permissions = $this->container->get(PermissionService::class);
        self::assertFalse($permissions->hasPermission($this->teacher->accountId, 'education.family.confirm'));
        $this->rejection(403, fn() => $this->family->write('confirm', $this->teacher, $this->learner['id'], $parent['personId']));
        $this->grantConfirmation($this->teacher);
        $this->family->write('confirm', $this->teacher, $this->learner['id'], $parent['personId']);
        self::assertSame('confirmed', $this->family->list($this->teacher, $this->learner['id'])['items'][0]['status']);
        $this->rejection([403, 404], fn() => $this->scopeFor($parent));
    }

    public function testInvitationIsBoundToTheExactCorePersonAndConsumedOnlyOnce(): void
    {
        $parent = $this->parent();
        $this->confirmed($parent);
        $invitation = $this->invite($parent);
        self::assertIsString($invitation['code']);
        self::assertGreaterThanOrEqual(24, strlen($invitation['code']));
        $stranger = $this->parent();
        $this->rejection([403, 404, 409], fn() => $this->family->activate($stranger['identity'], ['code' => $invitation['code']]));
        $activated = $this->family->activate($parent['identity'], ['code' => $invitation['code']]);
        self::assertTrue($activated['activated']);
        self::assertSame($this->learner['id'], $activated['studentId']);
        self::assertSame($this->teacher->organizationId, $activated['organizationId']);
        self::assertSame([$this->learner['id']], array_column($this->parents->context($parent['identity'])['children'], 'id'));
        $this->rejection([404, 409], fn() => $this->family->activate($parent['identity'], ['code' => $invitation['code']]));
        $stored = $this->db->createCommand('SELECT row_to_json(i)::text FROM education.parent_invites i WHERE code_hash=:hash',
            [':hash' => hash('sha256', $invitation['code'])])->queryScalar();
        self::assertIsString($stored);
        self::assertStringNotContainsString($invitation['code'], $stored, 'The bearer invitation must never be stored in plaintext.');
    }

    public function testExpiredInvitationCannotBeAccepted(): void
    {
        $parent = $this->parent();
        $this->confirmed($parent);
        $invitation = $this->invite($parent);
        $this->clock->instant = $this->clock->instant->add(new DateInterval('P31D'));
        $this->rejection([404, 409], fn() => $this->family->activate($parent['identity'], ['code' => $invitation['code']]));
        $this->rejection([403, 404], fn() => $this->scopeFor($parent));
    }

    public function testRevokedAndReissuedInvitationsCannotRestoreAccess(): void
    {
        $parent = $this->parent();
        $this->confirmed($parent);
        $first = $this->invite($parent);
        $second = $this->invite($parent);
        self::assertNotSame($first['code'], $second['code']);
        $this->rejection([404, 409], fn() => $this->family->activate($parent['identity'], ['code' => $first['code']]));
        $this->family->write('revoke_invite', $this->teacher, $this->learner['id'], $parent['personId']);
        $this->rejection([404, 409], fn() => $this->family->activate($parent['identity'], ['code' => $second['code']]));
    }

    public function testParentWithTwoChildrenHasSeparateScopesAndCannotReadAnUnrelatedChild(): void
    {
        $parent = $this->parent();
        $this->activate($parent);
        $this->confirmed($parent, $this->peer['id']);
        $this->family->activate($parent['identity'], ['code' => $this->invite($parent, $this->peer['id'])['code']]);
        $ids = array_column($this->parents->context($parent['identity'])['children'], 'id');
        self::assertEqualsCanonicalizing([$this->learner['id'], $this->peer['id']], $ids);
        self::assertSame($this->learner['id'], $this->scopeFor($parent)->studentId);
        self::assertSame($this->peer['id'], $this->scopeFor($parent, $this->peer['id'])->studentId);
        $unrelated = $this->student('Unrelated child', $this->teacher, $this->group['id']);
        $this->rejection([403, 404], fn() => $this->scopeFor($parent, $unrelated['id']));
        $this->rejection([403, 404], fn() => $this->scopeFor($parent, $this->id()));
    }

    public function testChildrenInDifferentOrganizationsRequireActualMembershipAndMatchingHeader(): void
    {
        $parent = $this->parent();
        $this->activate($parent);
        $otherTeacher = $this->outsider;
        $this->addMembership($parent['personId'], $otherTeacher->organizationId);
        $otherGroup = $this->education->write('create_group', $otherTeacher, ['name' => 'Other school']);
        $otherChild = $this->student('Child at second school', $otherTeacher, $otherGroup['id']);
        $this->confirmed($parent, $otherChild['id'], $otherTeacher);
        $invite = $this->invite($parent, $otherChild['id'], $otherTeacher);
        $this->family->activate($parent['identity'], ['code' => $invite['code']]);
        self::assertCount(2, $this->parents->context($parent['identity'])['children']);
        $this->rejection([403, 404], fn() => $this->scopeFor($parent, $otherChild['id']));
        $scope = $this->scopeFor($parent, $otherChild['id'], $otherTeacher->organizationId);
        self::assertSame($otherChild['id'], $scope->studentId);
        $this->db->createCommand("UPDATE core.organization_memberships SET status='left',left_at=now() WHERE person_id=CAST(:person AS uuid) AND organization_id=CAST(:org AS uuid)",
            [':person' => $parent['personId'], ':org' => $otherTeacher->organizationId])->execute();
        $this->rejection([403, 404], fn() => $this->parents->read('home', $scope));
        self::assertSame([$this->learner['id']], array_column($this->parents->context($parent['identity'])['children'], 'id'));
    }

    public function testRevocationInvalidatesAnAlreadyResolvedParentScopeWithoutRemovingHomeworkHistory(): void
    {
        $assignment = $this->publish();
        $this->answer($assignment['id']);
        $this->submit($assignment['id']);
        $parent = $this->parent();
        $this->activate($parent);
        $scope = $this->scopeFor($parent);
        self::assertNotEmpty($this->parents->read('homework', $scope)['items']);
        $before = $this->db->createCommand('SELECT count(*) FROM education.homework_submission_versions')->queryScalar();
        $this->family->write('revoke', $this->teacher, $this->learner['id'], $parent['personId']);
        $this->rejection([403, 404], fn() => $this->parents->read('homework', $scope));
        $this->rejection([403, 404], fn() => $this->scopeFor($parent));
        self::assertSame($before, $this->db->createCommand('SELECT count(*) FROM education.homework_submission_versions')->queryScalar());
        self::assertSame('SUBMITTED', $this->detail($assignment['id'])['submission']['state']);
    }

    public function testLiveRoleChangesInvalidateCachedParentScopesAndParentCannotImpersonateAStudent(): void
    {
        $parent = $this->parent();
        $this->activate($parent);
        $scope = $this->scopeFor($parent);
        $permissions = $this->container->get(PermissionService::class);
        foreach (['education.student.use', 'education.people.manage', 'education.schedule.manage', 'education.homework.manage', 'education.family.confirm'] as $permission) {
            self::assertFalse($permissions->hasPermission($parent['accountId'], $permission), $permission);
        }
        $this->rejection([403, 404], fn() => $this->access->studentScope($parent['identity'], $this->teacher->organizationId, 'education.student.use'));
        $this->db->createCommand('DELETE FROM core.account_roles WHERE account_id=CAST(:id AS uuid)', [':id' => $parent['accountId']])->execute();
        $this->rejection([403, 404], fn() => $this->parents->read('home', $scope));
        $this->rejection([403, 404], fn() => $this->scopeFor($parent));
    }

    public function testHomeworkProjectionIncludesOnlyPublishedAssignmentStateAndItsOwnReviewedComment(): void
    {
        $privateDraft = $this->draft(['title' => 'TEACHER_DRAFT_NOT_PUBLISHED']);
        $assignment = $this->publish();
        $this->answer($assignment['id'], 'PRIVATE_UNSUBMITTED_LEARNER_DRAFT');
        $this->answer($assignment['id'], 'PRIVATE_OTHER_CHILD_DRAFT', $this->peer['scope']);
        $parent = $this->parent();
        $this->activate($parent);
        $scope = $this->scopeFor($parent);
        $beforeSubmit = json_encode($this->parents->read('homework', $scope), JSON_THROW_ON_ERROR);
        self::assertStringContainsString($assignment['title'], $beforeSubmit);
        foreach ([$privateDraft['title'], 'PRIVATE_UNSUBMITTED_LEARNER_DRAFT', 'PRIVATE_OTHER_CHILD_DRAFT', 'draft_text', 'draftText', 'storageKey'] as $secret) {
            self::assertStringNotContainsString($secret, $beforeSubmit);
        }
        $this->submit($assignment['id']);
        $mine = $this->detail($assignment['id'])['submission'];
        $this->homework->write('review', $this->teacher, ['version' => $mine['version'], 'idempotencyKey' => $this->id(),
            'decision' => 'accepted', 'resultStatus' => 'mastered', 'comment' => 'Visible family feedback'], $mine['id']);
        $this->submit($assignment['id'], $this->peer['scope']);
        $peer = $this->detail($assignment['id'], $this->peer['scope'])['submission'];
        $this->homework->write('review', $this->teacher, ['version' => $peer['version'], 'idempotencyKey' => $this->id(),
            'decision' => 'accepted', 'resultStatus' => 'needs_support', 'comment' => 'PRIVATE_OTHER_CHILD_REVIEW'], $peer['id']);
        $afterReview = json_encode($this->parents->read('homework', $scope), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Visible family feedback', $afterReview);
        self::assertStringContainsString('mastered', $afterReview);
        self::assertStringNotContainsString('PRIVATE_OTHER_CHILD_REVIEW', $afterReview);
        self::assertStringNotContainsString('PRIVATE_OTHER_CHILD_DRAFT', $afterReview);
        self::assertStringNotContainsString('PRIVATE_UNSUBMITTED_LEARNER_DRAFT', $afterReview);
    }

    public function testDiaryExcludesTeacherOnlyReportAndOtherStudentsResults(): void
    {
        $lesson = $this->education->write('create_lesson', $this->teacher, ['groupId' => $this->group['id'], 'title' => 'Family diary topic',
            'startsAt' => '2026-11-12T10:00:00Z', 'endsAt' => '2026-11-12T11:00:00Z']);
        $this->education->write('complete_lesson', $this->teacher, [], $lesson['id']);
        $this->education->write('save_lesson_report', $this->teacher, ['topic' => 'Loops', 'summary' => 'Loops lesson', 'teacherComment' => 'PRIVATE_TEACHER_REPORT'], $lesson['id']);
        $this->education->write('mark_attendance', $this->teacher, ['status' => 'present'], $lesson['id'], $this->learner['id']);
        $this->education->write('save_lesson_result', $this->teacher, ['status' => 'mastered', 'comment' => 'Family lesson feedback'], $lesson['id'], $this->learner['id']);
        $this->education->write('save_lesson_result', $this->teacher, ['status' => 'needs_support', 'comment' => 'PRIVATE_PEER_LESSON_COMMENT'], $lesson['id'], $this->peer['id']);
        $parent = $this->parent();
        $this->activate($parent);
        $diary = json_encode($this->parents->read('diary', $this->scopeFor($parent)), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Family lesson feedback', $diary);
        self::assertStringContainsString('present', $diary);
        self::assertStringNotContainsString('PRIVATE_TEACHER_REPORT', $diary);
        self::assertStringNotContainsString('PRIVATE_PEER_LESSON_COMMENT', $diary);
        self::assertStringNotContainsString($this->peer['id'], $diary);
        self::assertStringNotContainsString('teacherComment', $diary);
    }

    public function testFamilyOperationsAreRestrictedToTheTeachersAuthorizedStudent(): void
    {
        $parent = $this->parent();
        $this->link($parent);
        foreach ([$this->colleague, $this->outsider] as $unauthorized) {
            $this->rejection([403, 404], fn() => $this->family->list($unauthorized, $this->learner['id']));
            $this->rejection([403, 404], fn() => $this->family->history($unauthorized, $this->learner['id']));
            foreach (['update', 'confirm', 'revoke', 'invite', 'revoke_invite'] as $operation) {
                $this->rejection([403, 404], fn() => $this->family->write($operation, $unauthorized, $this->learner['id'], $parent['personId'], ['relationship' => 'mother']));
            }
        }
        $this->rejection([409, 422], fn() => $this->link($parent));
        self::assertSame(1, $this->rowCount('student_guardians', 'student_id', $this->learner['id']));
    }

    public function testFamilyHistoryAndExportNeverExposeBearerInvitationCodes(): void
    {
        $parent = $this->parent();
        $this->activate($parent);
        $invitation = $this->invite($parent);
        $this->family->write('update', $this->teacher, $this->learner['id'], $parent['personId'], ['relationship' => 'mother']);
        $history = $this->family->history($this->teacher, $this->learner['id']);
        self::assertNotEmpty($history['items']);
        $historyJson = json_encode($history, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($invitation['code'], $historyJson);
        $context = new PersonalDataExportContext($parent['personId'], $this->id(), $parent['personId'], $parent['accountId'], null, $this->clock->now());
        $export = $this->container->get(PersonalDataContributor::class)->collect($context);
        foreach (['student_guardian', 'parent_invitation', 'family_history'] as $type) {
            self::assertContains($type, array_column($export->records, 'type'));
        }
        $exportJson = json_encode($export->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringContainsString($this->learner['id'], $exportJson);
        self::assertStringNotContainsString($invitation['code'], $exportJson);
        self::assertStringNotContainsString(hash('sha256', $invitation['code']), $exportJson);
    }

    public function testErasingTheParentsWorkspacePreservesTheChildsLearningEvidence(): void
    {
        $assignment = $this->publish();
        $this->answer($assignment['id']);
        $this->submit($assignment['id']);
        $parent = $this->parent();
        $this->activate($parent);
        $this->container->get(WorkspaceErasureHandler::class)->execute($parent['personId'], $parent['personId'], 'delete', $this->id());
        self::assertSame(1, $this->rowCount('students', 'id', $this->learner['id']));
        self::assertSame('SUBMITTED', $this->detail($assignment['id'])['submission']['state']);
        self::assertSame(1, $this->rowCount('homework_submission_versions', 'submission_id', $this->detail($assignment['id'])['submission']['id']));
        $this->rejection([403, 404], fn() => $this->scopeFor($parent));
    }

    public function testNewParentUsesCoreCredentialsForTheInvitedPersonAndCannotRegisterTwice(): void
    {
        $parent = $this->parent(false);
        $this->confirmed($parent);
        $invite = $this->invite($parent);
        $client = $this->container->get(ClientContextResolver::class)->resolve(new ServerRequest());
        $password = 'Education05-Parent-QA-' . bin2hex(random_bytes(8));
        $input = ['code' => $invite['code'], 'email' => 'parent-' . bin2hex(random_bytes(8)) . '@example.test', 'password' => $password];
        self::assertTrue($this->family->register($input, $client)['activated']);
        $account = $this->db->createCommand('SELECT a.id::text,a.status,c.password_hash FROM core.accounts a
            JOIN core.auth_password_credentials c ON c.account_id=a.id WHERE a.person_id=CAST(:person AS uuid)',
            [':person' => $parent['personId']])->queryOne();
        self::assertIsArray($account);
        self::assertSame('active', $account['status']);
        self::assertTrue(password_verify($password, $account['password_hash']));
        $identity = new AuthenticatedIdentity($account['id'], $parent['personId'], $this->id(), [], [], 'en-US', 'UTC');
        self::assertSame([$this->learner['id']], array_column($this->parents->context($identity)['children'], 'id'));
        $this->rejection([404, 409], fn() => $this->family->register($input, $client));
        self::assertSame(1, (int) $this->db->createCommand('SELECT count(*) FROM core.accounts WHERE person_id=CAST(:person AS uuid)',
            [':person' => $parent['personId']])->queryScalar());
    }

    public function testAnInvitationCannotReplaceAnExistingCoreAccountsCredentials(): void
    {
        $parent = $this->parent();
        $this->confirmed($parent);
        $invite = $this->invite($parent);
        $client = $this->container->get(ClientContextResolver::class)->resolve(new ServerRequest());
        $this->rejection(409, fn() => $this->family->register(['code' => $invite['code'], 'email' => 'parent-takeover@example.test',
            'password' => 'Changed-through-invitation-123'], $client));
        self::assertSame(0, (int) $this->db->createCommand('SELECT count(*) FROM core.auth_password_credentials WHERE account_id=CAST(:account AS uuid)',
            [':account' => $parent['accountId']])->queryScalar());
        self::assertTrue($this->family->activate($parent['identity'], ['code' => $invite['code']])['activated']);
    }

    public function testParentNotificationsUseAnExplicitAudienceAndExcludeStudentPrivateEvents(): void
    {
        $parent = $this->parent();
        $this->activate($parent);
        $scope = $this->scopeFor($parent);
        $pending = $this->parent();
        $this->link($pending);
        $assignment = $this->publish(['groupId' => null, 'studentIds' => [$this->learner['id']]]);
        $this->answer($assignment['id'], 'PRIVATE_ANSWER_NOT_A_NOTIFICATION');
        $this->submit($assignment['id']);
        $submitted = $this->detail($assignment['id'])['submission'];
        $this->homework->write('review', $this->teacher, ['version' => $submitted['version'], 'idempotencyKey' => $this->id(),
            'decision' => 'accepted', 'resultStatus' => 'mastered', 'comment' => 'PRIVATE_REVIEW_BODY_NOT_A_NOTIFICATION'], $submitted['id']);
        $lesson = $this->education->write('create_lesson', $this->teacher, ['groupId' => $this->group['id'], 'title' => 'Family schedule',
            'startsAt' => '2026-11-16T10:00:00Z', 'endsAt' => '2026-11-16T11:00:00Z']);
        $this->education->write('update_lesson', $this->teacher, ['groupId' => $this->group['id'], 'title' => 'Updated family schedule',
            'startsAt' => '2026-11-16T10:00:00Z', 'endsAt' => '2026-11-16T11:00:00Z', 'location' => 'QA room'], $lesson['id']);
        $notifications = $this->parents->read('notifications', $scope);
        $kinds = array_column($notifications['items'], 'kind');
        self::assertContains('parent_homework_published', $kinds);
        self::assertContains('parent_homework_reviewed', $kinds);
        self::assertContains('parent_schedule_changed', $kinds);
        self::assertNotContains('homework_submitted', $kinds);
        self::assertNotContains('message', $kinds);
        $json = json_encode($notifications, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('PRIVATE_ANSWER_NOT_A_NOTIFICATION', $json);
        self::assertStringNotContainsString('PRIVATE_REVIEW_BODY_NOT_A_NOTIFICATION', $json);
        self::assertSame(0, (int) $this->db->createCommand("SELECT count(*) FROM education.notifications WHERE person_id=CAST(:person AS uuid)
            AND kind IN ('parent_homework_published','parent_homework_reviewed','parent_schedule_changed')", [':person' => $pending['personId']])->queryScalar());
        $this->family->write('revoke', $this->teacher, $this->learner['id'], $parent['personId']);
        $this->rejection([403, 404], fn() => $this->parents->read('notifications', $scope));
    }

    public function testGuardianProcessingRestrictionImmediatelyBlocksAPreviouslyResolvedScope(): void
    {
        $parent = $this->parent();
        $this->activate($parent);
        $scope = $this->scopeFor($parent);
        $this->block($parent['personId']);
        $this->rejection(403, fn() => $this->parents->read('home', $scope));
        $this->rejection(403, fn() => $this->parents->context($parent['identity']));
    }

    public function testChildProcessingRestrictionHidesOnlyTheRestrictedChild(): void
    {
        $parent = $this->parent();
        $this->activate($parent);
        $this->confirmed($parent, $this->peer['id']);
        $scope = $this->scopeFor($parent);
        $this->block($this->learner['personId']);
        $this->rejection([403, 404], fn() => $this->parents->read('home', $scope));
        self::assertSame([$this->peer['id']], array_column($this->parents->context($parent['identity'])['children'], 'id'));
        self::assertSame($this->peer['id'], $this->scopeFor($parent, $this->peer['id'])->studentId);
    }

    public function testAResourceProcessingBlockRemovesHomeworkWithoutRemovingTheChild(): void
    {
        $parent = $this->parent();
        $this->activate($parent);
        $assignment = $this->publish();
        $scope = $this->scopeFor($parent);
        self::assertSame([$assignment['id']], array_column($this->parents->read('homework', $scope)['items'], 'id'));
        $this->block($parent['personId'], 'education.homework', $assignment['id']);
        self::assertSame([], $this->parents->read('homework', $scope)['items']);
        self::assertSame([$this->learner['id']], array_column($this->parents->context($parent['identity'])['children'], 'id'));
    }

    public function testDisabledAccountAndRevokedTeacherRoleCannotReuseOldScopes(): void
    {
        $parent = $this->parent();
        $this->activate($parent);
        $scope = $this->scopeFor($parent);
        $this->db->createCommand('UPDATE core.accounts SET disabled_at=now() WHERE id=CAST(:id AS uuid)', [':id' => $parent['accountId']])->execute();
        $this->rejection([403, 404], fn() => $this->parents->read('home', $scope));
        $this->db->createCommand('DELETE FROM core.account_roles WHERE account_id=CAST(:id AS uuid)', [':id' => $this->teacher->accountId])->execute();
        $this->rejection([403, 404], fn() => $this->family->history($this->teacher, $this->learner['id']));
        $this->rejection([403, 404], fn() => $this->family->write('revoke', $this->teacher, $this->learner['id'], $parent['personId']));
    }

    public function testPrivateHomeworkRowsDoNotHideTheNextPageOrDuplicateVisibleItems(): void
    {
        $parent = $this->parent();
        $this->activate($parent);
        for ($index = 0; $index < 53; $index++) {
            $this->publish(['groupId' => null, 'studentIds' => [$this->learner['id']], 'title' => 'Page ' . $index]);
        }
        $scope = $this->scopeFor($parent);
        $unrestricted = $this->parents->read('homework', $scope);
        $this->block($parent['personId'], 'education.homework', $unrestricted['items'][0]['id']);
        $this->block($parent['personId'], 'education.homework', $unrestricted['items'][1]['id']);
        $first = $this->parents->read('homework', $scope);
        self::assertCount(48, $first['items']);
        self::assertSame(50, $first['nextOffset']);
        $second = $this->parents->read('homework', $scope, ['offset' => (string) $first['nextOffset']]);
        self::assertCount(3, $second['items']);
        self::assertNull($second['nextOffset']);
        self::assertSame([], array_values(array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id'))));
    }

    public function testPrivateDiaryRowsPreserveTheRawPageBoundary(): void
    {
        $parent = $this->parent();
        $this->activate($parent);
        $start = new \DateTimeImmutable('2026-11-01T10:00:00Z');
        for ($index = 0; $index < 53; $index++) {
            $instant = $start->modify('+' . $index . ' hours');
            $this->education->write('create_lesson', $this->teacher, [
                'groupId' => $this->group['id'], 'title' => 'Diary page ' . $index,
                'startsAt' => $instant->format(DATE_ATOM), 'endsAt' => $instant->modify('+30 minutes')->format(DATE_ATOM),
            ]);
        }
        $scope = $this->scopeFor($parent);
        $unrestricted = $this->parents->read('diary', $scope);
        $this->block($parent['personId'], 'education.lesson', $unrestricted['items'][0]['id']);
        $this->block($parent['personId'], 'education.lesson', $unrestricted['items'][1]['id']);
        $first = $this->parents->read('diary', $scope);
        self::assertCount(48, $first['items']);
        self::assertSame(50, $first['nextOffset']);
        $second = $this->parents->read('diary', $scope, ['offset' => (string) $first['nextOffset']]);
        self::assertCount(3, $second['items']);
        self::assertNull($second['nextOffset']);
        self::assertSame([], array_values(array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id'))));
    }

    private function block(string $person, ?string $resourceType = null, ?string $resourceId = null): void
    {
        $this->db->createCommand("INSERT INTO core.privacy_processing_blocks
            (subject_person_id,scope_type,resource_type,resource_id,reason_code,created_by_account_id,started_at)
            VALUES(CAST(:person AS uuid),:scope,:type,CAST(:resource AS uuid),'subject_request',CAST(:actor AS uuid),now()-interval '1 minute')",
            [':person' => $person, ':scope' => $resourceType === null ? 'subject' : 'resource', ':type' => $resourceType,
                ':resource' => $resourceId, ':actor' => $this->teacher->accountId])->execute();
    }

    private function parent(bool $withAccount = true): array
    {
        $person = (string) $this->db->createCommand("INSERT INTO core.persons(given_name,family_name) VALUES('Fictional parent','QA') RETURNING id::text")->queryScalar();
        $this->addMembership($person, $this->teacher->organizationId);
        if (!$withAccount) return ['personId' => $person, 'accountId' => null, 'identity' => null];
        $account = (string) $this->db->createCommand("INSERT INTO core.accounts(person_id,account_type,status,label) VALUES(CAST(:person AS uuid),'human','active','Parent QA') RETURNING id::text", [':person' => $person])->queryScalar();
        return ['personId' => $person, 'accountId' => $account, 'identity' => new AuthenticatedIdentity($account, $person, $this->id(), [], [], 'en-US', 'UTC')];
    }

    private function addMembership(string $person, string $organization): void
    {
        $this->db->createCommand('INSERT INTO core.organization_memberships(organization_id,person_id) VALUES(CAST(:org AS uuid),CAST(:person AS uuid)) ON CONFLICT DO NOTHING',
            [':org' => $organization, ':person' => $person])->execute();
    }

    private function link(array $parent, ?string $student = null, ?Scope $teacher = null): array
    {
        return $this->family->write('add', $teacher ?? $this->teacher, $student ?? $this->learner['id'], null,
            ['personId' => $parent['personId'], 'relationship' => 'guardian']);
    }

    private function grantConfirmation(Scope $teacher): void
    {
        $role = (string) $this->db->createCommand("INSERT INTO core.roles(code,name) VALUES(:code,'QA family verifier') RETURNING id::text",
            [':code' => 'education.qa.family.' . bin2hex(random_bytes(8))])->queryScalar();
        self::assertSame(1, $this->db->createCommand('INSERT INTO core.role_permissions(role_id,permission_id) SELECT CAST(:role AS uuid),id FROM core.permissions WHERE code=:permission',
            [':role' => $role, ':permission' => 'education.family.confirm'])->execute());
        $this->db->createCommand('INSERT INTO core.account_roles(account_id,role_id) VALUES(CAST(:account AS uuid),CAST(:role AS uuid))',
            [':account' => $teacher->accountId, ':role' => $role])->execute();
    }

    private function confirmed(array $parent, ?string $student = null, ?Scope $teacher = null): void
    {
        $teacher ??= $this->teacher;
        $student ??= $this->learner['id'];
        $this->link($parent, $student, $teacher);
        $this->grantConfirmation($teacher);
        $this->family->write('confirm', $teacher, $student, $parent['personId']);
    }

    private function invite(array $parent, ?string $student = null, ?Scope $teacher = null): array
    {
        return $this->family->write('invite', $teacher ?? $this->teacher, $student ?? $this->learner['id'], $parent['personId']);
    }

    private function activate(array $parent): void
    {
        $this->confirmed($parent);
        $this->family->activate($parent['identity'], ['code' => $this->invite($parent)['code']]);
    }

    private function scopeFor(array $parent, ?string $student = null, ?string $organization = null): ParentScope
    {
        return $this->access->parentScope($parent['identity'], $organization ?? $this->teacher->organizationId, $student ?? $this->learner['id']);
    }
}
