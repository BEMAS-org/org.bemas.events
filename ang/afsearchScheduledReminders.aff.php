<?php
use CRM_Events_ExtensionUtil as E;

return [
  'type' => 'search',
  'title' => E::ts('Schedule Reminders'),
  'description' => E::ts('Scheduled Reminders'),
  'icon' => 'fa-list-alt',
  'server_route' => 'civicrm/admin/scheduleReminders',
  'permission' => ['administer CiviCRM data'],
];
