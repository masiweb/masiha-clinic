<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require __DIR__.'/../app/bootstrap.php';

if(!MasihaSms::enabled()){
 echo "SMS_DISABLED\n";
 exit(0);
}
$reminders=MasihaSms::queueDueReminders();
$birthdays=MasihaSms::queueBirthdays();
$result=MasihaSms::process(30);
echo 'SMS_WORKER reminders='.$reminders.' birthdays='.$birthdays.' sent='.(int)$result['sent'].' failed='.(int)$result['failed']."\n";
