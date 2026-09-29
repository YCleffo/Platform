<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Privacy\ControlIncidentService;
use ControlPlane\Privacy\ControlPrivacyOperator;
use ControlPlane\Privacy\ControlSubjectRequestService;

final class PrivacyOperationsTest extends DatabaseTestCase
{
    public function testNonUserBillingContactRequestAndCorrection(): void
    {
        $operatorId = $this->user();
        $accountId = $this->account('privacy-subject-' . substr(str_replace('-', '', $this->uuid()), 0, 16));
        $profileId = (string) $this->connection->fetchOne(<<<'SQL'
INSERT INTO commercial.billing_profiles (commercial_account_id, legal_name, country_code, contact_name, contact_email)
VALUES (?, 'Example Legal Entity', 'RU', 'Old Name', 'old@example.test') RETURNING id
SQL, [$accountId]);
        $operators = new ControlPrivacyOperator($this->connection);
        $requests = new ControlSubjectRequestService($this->connection, $operators);
        $input = ['subjectKind' => 'billing_contact', 'subjectReference' => 'urn:subject:external-1',
            'identityEvidenceReference' => 'urn:verified:case-1', 'billingProfileId' => $profileId,
            'requestType' => 'access', 'sourceChannel' => 'paper', 'receivedAt' => '2026-09-24T12:00:00+00:00'];
        try {
            $requests->intake($operatorId, $input);
            self::fail('Expected privacy operator authorization.');
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) {}
        $this->connection->executeStatement('INSERT INTO commercial.privacy_operators (control_user_id, authorization_reference) VALUES (?, ?)', [$operatorId, 'urn:authorization:privacy-1']);
        $accessId = $requests->intake($operatorId, $input);
        $access = $requests->resolve($operatorId, $accessId);
        self::assertSame('Old Name', $access['data']['billingContact']['contact_name']);
        self::assertSame('completed', $access['status']);
        $input['requestType'] = 'correction';
        $correctionId = $requests->intake($operatorId, $input);
        self::assertSame('mutable_contact_corrected', $requests->resolve($operatorId, $correctionId, 'New Name', 'new@example.test')['reasonCode']);
        self::assertSame('new@example.test', $this->connection->fetchOne('SELECT contact_email FROM commercial.billing_profiles WHERE id = ?', [$profileId]));
        $input['requestType'] = 'erasure';
        $eraseId = $requests->intake($operatorId, $input);
        self::assertSame('completed', $requests->resolve($operatorId, $eraseId)['status']);
        self::assertNull($this->connection->fetchOne('SELECT contact_email FROM commercial.billing_profiles WHERE id = ?', [$profileId]));
        $other = $input;
        unset($other['billingProfileId']);
        $other['subjectKind'] = 'other';
        $other['requestType'] = 'processing_information';
        $otherId = $requests->intake($operatorId, $other);
        try {
            $requests->resolve($operatorId, $otherId);
            self::fail('Unlinked subject needs external response evidence.');
        } catch (\DomainException) {}
        self::assertSame('urn:response:other-subject', $requests->resolve($operatorId, $otherId,
            responseEvidenceReference: 'urn:response:other-subject')['data']['responseDocumentReference']);
    }

    public function testIncidentDeadlinesAndEvidenceEvents(): void
    {
        $operatorId = $this->user();
        $this->connection->executeStatement('INSERT INTO commercial.privacy_operators (control_user_id, authorization_reference) VALUES (?, ?)', [$operatorId, 'urn:authorization:privacy-2']);
        $service = new ControlIncidentService($this->connection, new ControlPrivacyOperator($this->connection));
        $incident = $service->detect($operatorId, '2026-09-24T12:00:00+00:00', ['billing_contact'], 12);
        self::assertSame('2026-09-25T12:00:00+00:00', $incident['initialNotificationDueAt']);
        self::assertSame('2026-09-27T12:00:00+00:00', $incident['investigationNotificationDueAt']);
        $service->record($operatorId, $incident['id'], 'containment', 'urn:evidence:containment');
        $service->record($operatorId, $incident['id'], 'initial_notification', 'urn:evidence:rkn-initial');
        self::assertSame('contained', $this->connection->fetchOne('SELECT status FROM commercial.privacy_incidents WHERE id = ?', [$incident['id']]));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.privacy_incident_events WHERE incident_id = ?', [$incident['id']]));
        $service->record($operatorId, $incident['id'], 'investigation', 'urn:evidence:investigation');
        $service->record($operatorId, $incident['id'], 'investigation_notification', 'urn:evidence:rkn-investigation');
        $service->close($operatorId, $incident['id']);
        self::assertSame('closed', $this->connection->fetchOne('SELECT status FROM commercial.privacy_incidents WHERE id = ?', [$incident['id']]));
    }
}
