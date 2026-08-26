# Changelog

## 1.1.0

* Added SearchKit (`Scheduled_Reminder_Search_ixiam`) with display (`Scheduled_Reminder_Search_Table`) to replace the core Scheduled Reminders admin page
* Added afform (`ang/afsearchScheduledReminders`) that overrides `civicrm/admin/scheduleReminders` via the afform scanner
* SearchKit display includes: Title (edit link), Reminder For, Entity, When, While, Repeat, Active columns
* Exposed filters for Active status, Title and Reminder For above the table
* Upgraded civix format to 25.10.1

## 1.0

* Initial release