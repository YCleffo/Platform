<?php

declare(strict_types=1);

// Explicitly fictional local fixture only; does not confirm any family relationship.
require __DIR__ . '/guard.php';
$db = educationFixtureDatabase();
$fixture = json_decode((string) file_get_contents('/education-data/access.json'), true, flags: JSON_THROW_ON_ERROR);
$teacher = $fixture['accounts']['teacher'];
$statement = $db->prepare("INSERT INTO core.account_roles(account_id,role_id)
    SELECT a.id,r.id FROM core.accounts a CROSS JOIN core.roles r
    WHERE a.id=CAST(:account AS uuid) AND a.person_id=CAST(:person AS uuid)
        AND a.status='active' AND r.code='education.family_verifier'
    ON CONFLICT DO NOTHING");
$statement->execute([':account' => $teacher['id'], ':person' => $teacher['personId']]);
echo "Fictional local teacher may verify representation; existing family records remain unconfirmed.\n";
