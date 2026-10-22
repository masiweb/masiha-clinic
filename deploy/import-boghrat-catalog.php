<?php
declare(strict_types=1);
/** Add only distinct, directly observed Boghrat reception titles; never alter existing mappings. */
$config=require (getenv('MASIHA_CONFIG')?:'/etc/masiha-clinic/config.php');
$db=new PDO($config['dsn'],$config['user'],$config['password'],[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_EMULATE_PREPARES=>false
]);
$source=json_decode(file_get_contents(__DIR__.'/boghrat-observed-catalog.json'),true,512,JSON_THROW_ON_ERROR);
if(!is_array($source)||count($source['diagnoses']??[])<60||count($source['treatment_programs']??[])<10)
 throw new RuntimeException('Incomplete saved Boghrat reference catalog');
$db->beginTransaction();
try{
 foreach(['diagnoses','treatment_programs'] as $table){
  $stmt=$db->prepare("INSERT IGNORE INTO $table(name,active) VALUES(?,1)");
  foreach($source[$table] as $name){
   if(!is_string($name)||mb_strlen($name)>160||$name!==trim($name))
    throw new RuntimeException('Invalid saved Boghrat item');
   $stmt->execute([$name]);
  }
 }
 $db->commit();
 echo 'Observed Boghrat catalog imported additively: '.count($source['diagnoses']).' diagnoses; '.count($source['treatment_programs'])." program/service titles.\n";
}catch(Throwable $ex){
 if($db->inTransaction())$db->rollBack();
 throw $ex;
}
