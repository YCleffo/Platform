<?php

declare(strict_types=1);

namespace App\Tests\Storage02;

use App\Storage\Application\StorageError;
use App\Storage\Application\StorageScope;
use App\Storage\Application\StorageStore;
use App\Storage\Infrastructure\S3Objects;
use App\Storage\Infrastructure\StorageTransferService;

require_once __DIR__ . '/TransferDatabaseFixture.php';

final class StorageTransferIntegrationTest extends TransferDatabaseFixture
{
    private StorageStore $store;
    private StorageTransferService $transfers;
    private StorageScope $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->container->get(StorageStore::class);
        $this->transfers = $this->container->get(StorageTransferService::class);
        $this->owner = $this->storageScope();
    }

    public function testSinglePublicationIsIdempotentAndSurvivingPutCannotModifyPublishedVersion(): void
    {
        $bytes = 'Independent S3 publication ' . random_bytes(32);
        $transfer = $this->upload('independent.txt',$bytes);
        self::assertSame('single',$transfer['mode']);
        self::assertSame(200,$this->objectRequest($transfer['request'],$bytes)['status']);
        $done = $this->transfers->complete($this->owner,$transfer['id'],[]);
        self::assertSame('confirmed',$done['status']);
        self::assertSame(hash('sha256',$bytes),$done['result']['sha256']);
        self::assertSame($done['result'],$this->transfers->complete($this->owner,$transfer['id'],[])['result']);
        self::assertSame($done['result'],$this->transfers->abort($this->owner,$transfer['id'],[])['result']);
        self::assertCount(1,$this->store->fileVersions($this->owner,'independent.txt'));
        $row = $this->transferRow($transfer['id']);
        $this->writeObject($this->storageBucket(),$row['staging_key'],'tampered staging bytes');
        self::assertSame(hash('sha256',$bytes),$this->objectHash($this->storageBucket(),S3Objects::objectKey($row['final_storage_key'])));
        self::assertSame(0,(int)$this->db->createCommand('SELECT reserved_bytes+reserved_files FROM storage.transfers WHERE id=CAST(:id AS uuid)', [':id'=>$transfer['id']])->queryScalar());
    }

    public function testChecksumAndSizeFailuresNeverCreateBusinessVersion(): void
    {
        foreach (['checksum'=>'bad payload!!','size'=>'short'] as $case=>$bad) {
            $expected = 'good payload!';
            $transfer = $this->upload($case.'.txt',$expected);
            $row = $this->transferRow($transfer['id']);
            // Deliberately bypass signed checksum validation to exercise independent server verification.
            $this->writeObject($this->storageBucket(),$row['staging_key'],$bad);
            $this->denied(fn()=>$this->transfers->complete($this->owner,$transfer['id'],[]),422,
                $case === 'size' ? 'size_mismatch' : 'checksum_mismatch');
            self::assertNull($this->store->fileByPath($this->owner,$case.'.txt'));
            self::assertNull($this->transfers->read($this->owner,$transfer['id'])['result']);
        }
    }

    public function testReservationsBlockParallelOvercommitAndAbortReleasesCapacity(): void
    {
        $space = $this->store->space($this->owner);
        $this->db->createCommand('UPDATE storage.spaces SET max_bytes=10,max_files=2 WHERE id=CAST(:id AS uuid)', [':id'=>$space['id']])->execute();
        $first = $this->upload('first.txt','123456');
        $this->denied(fn()=>$this->upload('second.txt','123456'),409,'quota_exceeded');
        $this->denied(fn()=>$this->upload('first.txt','12'),409,'transfer_in_progress');
        self::assertSame('aborted',$this->transfers->abort($this->owner,$first['id'],[])['status']);
        self::assertSame('aborted',$this->transfers->abort($this->owner,$first['id'],[])['status']);
        $second = $this->upload('second.txt','123456');
        self::assertSame('pending',$second['status']);
        $this->denied(fn()=>$this->transfers->complete($this->owner,$first['id'],[]),409,'transfer_closed');
    }

    public function testTicketExpiryDiffersFromDurableSessionExpiry(): void
    {
        $ticket = $this->store->createTicket($this->owner,'upload','expired.txt',hash('sha256','one'),3);
        $this->expireTicket($ticket['id']);
        $this->denied(fn()=>$this->transfers->start($this->owner,['ticketId'=>$ticket['id'],'token'=>$ticket['token']]),409,'ticket_expired');
        $transfer = $this->upload('durable.txt','durable');
        $this->expireTicket($transfer['ticketId']);
        self::assertSame($transfer['id'],$this->transfers->refresh($this->owner,$transfer['id'],[])['id']);
        self::assertSame(200,$this->objectRequest($transfer['request'],'durable')['status']);
        self::assertSame('confirmed',$this->transfers->complete($this->owner,$transfer['id'],[])['status']);
        $other = $this->upload('session-expiry.txt','session');
        $this->db->createCommand("UPDATE storage.transfers SET expires_at=now()-interval '1 second' WHERE id=CAST(:id AS uuid)", [':id'=>$other['id']])->execute();
        self::assertSame('expired',$this->transfers->read($this->owner,$other['id'])['status']);
        self::assertNull($this->transfers->refresh($this->owner,$other['id'],[])['request']);
        $this->denied(fn()=>$this->transfers->complete($this->owner,$other['id'],[]),409,'transfer_expired');
    }

    public function testActorAndStudentBindingAndPermissionRevocationAreRechecked(): void
    {
        $transfer = $this->upload('bound.txt','bound');
        $peer = $this->storageScope($this->peer);
        $this->denied(fn()=>$this->transfers->read($peer,$transfer['id']),404,'not_found');
        $imposter = new StorageScope($this->owner->organizationId,$this->peer['accountId'],$this->owner->personId,
            'en-US','UTC',$this->owner->studentId);
        $this->denied(fn()=>$this->transfers->refresh($imposter,$transfer['id'],[]),404,'not_found');
        $this->db->createCommand('UPDATE education.student_accounts SET revoked_at=now() WHERE account_id=CAST(:id AS uuid)', [':id'=>$this->owner->accountId])->execute();
        $this->denied(fn()=>$this->transfers->read($this->owner,$transfer['id']),403,'access_revoked');
        $this->denied(fn()=>$this->transfers->complete($this->owner,$transfer['id'],[]),403,'access_revoked');
    }

    public function testRealMultipartListsDurablePartsAndRejectsConflictingPartIdentity(): void
    {
        $partSize = $this->multipartSize();
        self::assertGreaterThanOrEqual(5*1024*1024,$partSize);
        self::assertLessThanOrEqual(16*1024*1024,$partSize,'Keep the independent isolated fixture bounded.');
        $first = str_repeat('A',$partSize);
        $last = 'last actual S3 part';
        $transfer = $this->upload('multipart.bin',$first.$last);
        self::assertSame('multipart',$transfer['mode']);
        $sign = $this->transfers->parts($this->owner,$transfer['id'],['parts'=>[
            ['partNumber'=>1,'sha256'=>hash('sha256',$first),'sizeBytes'=>strlen($first)]]]);
        self::assertSame(200,$this->objectRequest($sign['items'][0]['request'],$first)['status']);
        $resumed = $this->transfers->read($this->owner,$transfer['id']);
        self::assertSame([1],array_column($resumed['completedParts'],'partNumber'));
        self::assertSame(strlen($first),$resumed['completedParts'][0]['sizeBytes']);
        $this->denied(fn()=>$this->transfers->parts($this->owner,$transfer['id'],['parts'=>[
            ['partNumber'=>1,'sha256'=>hash('sha256','different'),'sizeBytes'=>strlen($first)]]]),409,'part_conflict');
        $this->denied(fn()=>$this->transfers->complete($this->owner,$transfer['id'],[]),409,'upload_incomplete');
        $sign = $this->transfers->parts($this->owner,$transfer['id'],['parts'=>[
            ['partNumber'=>2,'sha256'=>hash('sha256',$last),'sizeBytes'=>strlen($last)]]]);
        self::assertSame(200,$this->objectRequest($sign['items'][0]['request'],$last)['status']);
        $done = $this->transfers->complete($this->owner,$transfer['id'],[]);
        self::assertSame('confirmed',$done['status']);
        self::assertSame(hash('sha256',$first.$last),$done['result']['sha256']);
        self::assertSame($done['result'],$this->transfers->complete($this->owner,$transfer['id'],[])['result']);
    }

    public function testDownloadPinsVersionAndSupportsActualHttpRange(): void
    {
        $old = '0123456789 old version';
        $upload = $this->upload('version.txt',$old);
        self::assertSame(200,$this->objectRequest($upload['request'],$old)['status']);
        $this->transfers->complete($this->owner,$upload['id'],[]);
        $ticket = $this->store->createTicket($this->owner,'download','version.txt',null,null);
        $download = $this->trackStorage($this->transfers->start($this->owner,['ticketId'=>$ticket['id'],'token'=>$ticket['token']]));
        $next = $this->upload('version.txt','new business version');
        self::assertSame(200,$this->objectRequest($next['request'],'new business version')['status']);
        $this->transfers->complete($this->owner,$next['id'],[]);
        $range = $this->objectRequest($download['request'],null,['Range'=>'bytes=3-8']);
        self::assertSame(206,$range['status']);
        self::assertSame(substr($old,3,6),$range['body']);
        self::assertSame('bytes 3-8/'.strlen($old),$range['headers']['content-range']);
        self::assertSame(hash('sha256',$old),$this->transfers->complete($this->owner,$download['id'],[])['result']['sha256']);
    }

    private function upload(string $path,string $bytes): array
    {
        $ticket = $this->store->createTicket($this->owner,'upload',$path,hash('sha256',$bytes),strlen($bytes));
        return $this->trackStorage($this->transfers->start($this->owner,['ticketId'=>$ticket['id'],'token'=>$ticket['token']]));
    }

    private function transferRow(string $id): array
    {
        $row = $this->db->createCommand('SELECT staging_key,final_storage_key::text FROM storage.transfers WHERE id=CAST(:id AS uuid)', [':id'=>$id])->queryOne();
        self::assertIsArray($row);
        return $row;
    }

    private function expireTicket(string $id): void
    {
        $this->db->createCommand("UPDATE storage.tickets SET expires_at=now()-interval '1 second' WHERE id=CAST(:id AS uuid)", [':id'=>$id])->execute();
    }

    private function denied(callable $action,int $status,string $reason): void
    {
        try { $action(); self::fail('Expected transfer rejection: '.$reason); }
        catch (StorageError $error) { self::assertSame($status,$error->status); self::assertSame($reason,$error->reason); }
    }
}
