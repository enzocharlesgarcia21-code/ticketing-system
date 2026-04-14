Run the priority escalation worker from the server scheduler, not from web requests.

Windows Task Scheduler:
`php C:\xampp\htdocs\ticketing\cron\escalate_ticket_priorities.php`

Linux cron example:
`*/5 * * * * /usr/bin/php /var/www/html/ticketing/cron/escalate_ticket_priorities.php >> /var/www/html/ticketing/logs/priority_escalation.log 2>&1`

Recommended interval:
- Every 5 minutes for normal use
- Every 1 minute if you need near-immediate escalation processing

The worker writes operational logs to `logs/priority_escalation.log`.
