<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Deployment\Application\RequestDeploymentUpdate;
use ControlPlane\Deployment\Application\ReserveDeployment;

final class RequestDeploymentUpdateTest extends DatabaseTestCase
{
    public function testValidRequestAndIdempotencyReplay(): void
    {
        [$deployment, $a] = $this->active('1.0.0', 'stable');
        $b = $this->release('1.1.0', 'stable', 'published', 'b');
        $requests = new RequestDeploymentUpdate($this->connection, 'test');
        $operation = $requests->request($deployment, $b, 'update-once');
        self::assertSame($operation, $requests->request($deployment, $b, 'update-once'));
        self::assertSame($a, $this->connection->fetchOne('SELECT current_release_id FROM commercial.deployments WHERE id=?', [$deployment]));
        self::assertSame($b, $this->connection->fetchOne('SELECT desired_release_id FROM commercial.deployments WHERE id=?', [$deployment]));
    }

    public function testRejectsSameOlderChannelDraftAndInvalidDigest(): void
    {
        [$deployment, $a] = $this->active('2.0.0', 'stable');
        $requests = new RequestDeploymentUpdate($this->connection, 'test');
        foreach ([$a, $this->release('1.9.0','stable','published','c'),
            $this->release('2.1.0','beta','published','d'), $this->release('2.1.1','stable','draft','e')]
            as $target) {
            try { $requests->request($deployment, $target, 'reject-' . $target); self::fail('Ineligible update accepted.'); }
            catch (\DomainException) { self::assertTrue(true); }
        }
    }

    public function testRejectsConcurrentLifecycleOperation(): void
    {
        [$deployment] = $this->active('3.0.0', 'stable');
        $b=$this->release('3.1.0','stable','published','9');
        $this->connection->executeStatement("INSERT INTO commercial.deployment_operations(deployment_id,operation_type,idempotency_key,status) VALUES (?,'deprovision','decommission-first','pending')",[$deployment]);
        $this->expectException(\DomainException::class);
        (new RequestDeploymentUpdate($this->connection,'test'))->request($deployment,$b,'update-blocked');
    }

    /** @return array{string,string} */
    private function active(string $version,string $channel): array
    {
        $release=$this->release($version,$channel,'published','a');
        $deployment=(new ReserveDeployment($this->connection,'platform.example'))->reserveCloud(
            $this->account('update-' . bin2hex(random_bytes(4))),'dedicated','update-' . bin2hex(random_bytes(4)),'initial-' . bin2hex(random_bytes(4)));
        $this->connection->executeStatement("UPDATE commercial.deployments SET status='active',provisioning_state='ready',current_release_id=?,desired_release_id=? WHERE id=?",[$release,$release,$deployment]);
        $this->connection->executeStatement("UPDATE commercial.deployment_operations SET status='succeeded',finished_at=now() WHERE deployment_id=?",[$deployment]);
        return [$deployment,$release];
    }
    private function release(string $version,string $channel,string $status,string $digestChar): string
    {
        $key='release-' . bin2hex(random_bytes(5));
        $this->connection->executeStatement("INSERT INTO commercial.signing_keys(key_id,algorithm,purpose,public_key,private_key_secret_ref,status,valid_from) VALUES (?,'Ed25519','release',decode(repeat('01',32),'hex'),'vault://keys/test','active',now()-interval '1 day')",[$key]);
        return (string)$this->connection->fetchOne("INSERT INTO commercial.releases(version,channel,status,oci_reference,image_digest,manifest_sha256,manifest_signature,signing_key_id,published_at) VALUES (?,?,?,?,?,decode(repeat('00',32),'hex'),decode(repeat('00',64),'hex'),?,CASE WHEN ?='published' THEN now() END) RETURNING id",[$version,$channel,$status,'platform-runtime:test-'.$digestChar,'sha256:'.str_repeat($digestChar,64),$key,$status]);
    }
}
