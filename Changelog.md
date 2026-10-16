# Changelog

## 1.2.0

* Added automated catering/event-fee price-field derivation: when an event's "Catering rate" is set (not "Exception"), the CAT-* catering price field and the net Member/Non-member registration amounts are now created/kept in sync automatically from the all-in price staff already enter, instead of requiring manual calculation
* Registration fees, fee labels and catering rate/days stay editable at any time, including after an event has registrations - staff just need to be careful when changing fees once people have signed up (Implements https://git.ixiam.com/infra/civi-go/sites/crm.bemas.org/-/work_items/31)
* The CAT-* catering line's quantity on the back-office event registration form is now pre-filled with the event's configured Aantal cateringdagen (still freely editable), so staff don't need to look it up or retype it (Implements https://git.ixiam.com/infra/civi-go/sites/crm.bemas.org/-/work_items/31)
* New dependency on org.bemas.invoicestoexact (for the catering rate/days custom field configuration)

## 1.1.0

* Added SearchKit (`Scheduled_Reminder_Search_ixiam`) with display (`Scheduled_Reminder_Search_Table`) to replace the core Scheduled Reminders admin page
* Added afform (`ang/afsearchScheduledReminders`) that overrides `civicrm/admin/scheduleReminders` via the afform scanner
* SearchKit display includes: Title (edit link), Reminder For, Entity, When, While, Repeat, Active columns
* Exposed filters for Active status, Title and Reminder For above the table
* Upgraded civix format to 25.10.1

## 1.0

* Initial release