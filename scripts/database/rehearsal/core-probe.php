<?php
declare(strict_types=1);
require '/app/vendor/autoload.php';
\App\Environment::prepare();
$runner=new \Yiisoft\Yii\Runner\Http\HttpApplicationRunner(rootPath:'/app',environment:'test');
$container=$runner->getContainer();
$db=$container->get(\Yiisoft\Db\Connection\ConnectionInterface::class);
if ($db->createCommand("SELECT current_database() || ':' || current_setting('cluster_name')")->queryScalar() !== 'platform_core_upgrade:platform-upgrade-rehearsal-core') {
    throw new RuntimeException('Not an isolated Core rehearsal');
}
$transaction=$db->beginTransaction();
try {
    $create=$container->get(\App\Core\Person\Application\CreatePerson::class);
    $person=$create->execute(new \App\Core\Person\Application\CreatePersonCommand('Rehearsal','Rollback',null,null,null));
    $repository=$container->get(\App\Core\Person\Application\PersonRepository::class);
    if ($repository->findById($person->id) === null) throw new RuntimeException('Core repository read failed');
    $db->createCommand("INSERT INTO core.accounts(person_id,status,label) VALUES (CAST(:person AS uuid),'active','Rollback-only rehearsal')",[':person'=>$person->id])->execute();
    $roles=$db->createCommand("SELECT rolsuper,rolcreatedb,rolcreaterole,rolbypassrls FROM pg_roles WHERE rolname=current_user")->queryOne();
    foreach ($roles as $flag) if (in_array($flag, [true, 't', '1', 1], true)) throw new RuntimeException('Elevated Core runtime role');
    echo json_encode(['status'=>'PASS','role'=>'core_runtime','checks'=>['Yii DI bootstrap','Person application create/read','human Account trigger/write','runtime attributes'],'transaction'=>'rolled_back']),"\n";
} finally {
    $transaction->rollBack();
}
