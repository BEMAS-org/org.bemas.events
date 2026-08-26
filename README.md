# org.bemas.events

## Scheduled Reminders admin page override

This extension overrides the core CiviCRM Scheduled Reminders admin page (`civicrm/admin/scheduleReminders`) with a custom SearchKit-based display.

The override consists of:
- `ang/afsearchScheduledReminders.aff.php` — afform metadata declaring the `server_route`; the afform scanner picks this up automatically and replaces the core route
- `ang/afsearchScheduledReminders.aff.html` — afform template with help text, exposed filters (Active, Title, Reminder For) and the SearchKit table
- `managed/scheduled_reminders.mgd.php` — defines the `SavedSearch` (`Scheduled_Reminder_Search_ixiam`) and `SearchDisplay` (`Scheduled_Reminder_Search_Table`) managed entities

After deploying, run:
```bash
cv api4 Managed.reconcile '{"modules":["org.bemas.events"]}' && cv flush
```

## Templates

Er zijn 3 templates voor eventementen:

* template A
* template B
* template C

Er is 1 template voor lesgevers:

* template l1

Elke template is een Drupal webform, en elke template bestaat in NL, FR en EN.

## Aanmaak evaluatieformulier

Voor een specifiek evenement kan je een evaluatieformulier aanmaken door op de fiche van het evenement "Evaluatieformulier aanmaken?" op "ja" te zetten.

Sla dan het evenement op. Het evaluatieformulier wordt dan automatisch aangemaakt en de hyperlink naar het formulier vind je in het vak "Formulier voor deelnemers".

Afhankelijk van het type evenement is er ook een evaluatieformulier voor lesgevers.

## Verwerking evalutieformulier

Een custom Drupal module, bemas_survey, vangt een submit van een evaluatieformulier op, en delegeert dan de verwerking aan een deze extensie.

## Dashboards

De dashboards zitten in de extensie org.bemas.evalformdashboard.

## Technisch

### Hook events_civicrm_custom()

De hook events_civicrm_custom() in events.php check of een event wordt aangemaakt of bewerkt.
Het delegeert dan aan ```CRM_Events_BemasEvent::processHookCustom()```.

### CRM_Events_BemasEvent::processHookCustom()

```CRM_Events_BemasEvent::processHookCustom()``` check of aan alle voorwaarden voldaan is om de evaluatieformulieren aan te maken. Het delegeert de aanmaak aan de klasse ```CRM_Events_BemasSurvey()```.

### Klasse CRM_Events_BemasSurvey

De klasse heeft een method ```createForEvent($eventId)```.

Deze klasse zoekt alles op over het evenement (titel, type evaluatieformulier, lesgevers...) en delegeert de aanmaak van de Drupal webform aan de klasse ```CRM_Events_DrupalWebform```

### Klasse CRM_Events_DrupalWebform

De klasse CRM_Events_DrupalWebform cloont de juiste template en past de webform aan het evenement aan: titel invullen, de lesgevers, de event id...

### Verwerking van een evaluatie: Drupal custom module bemas_survey

Wanneer een deelnemer een evaluatieformulier invult, wordt de hook ```webform_submission_insert()``` opgevangen in de custom Drupal module ```bemas_survey```.

Indien aan de voorwaarden voldaan is, zal de module de vragen en antwoorden delegeren aan ```CRM_Events_DrupalWebformSubmission::process```

### Klasse CRM_Events_DrupalWebformSubmission: factory voor verwerking

De method ```CRM_Events_DrupalWebformSubmission::process()``` checkt welk type formulier gesubmit werd, en instantieert dan een object van een van volgende klassen:

  * CRM_Events_DrupalWebformProcessorA
  * CRM_Events_DrupalWebformProcessorB
  * CRM_Events_DrupalWebformProcessorC
  * CRM_Events_DrupalWebformProcessorL1

Die klassen hebben een ```process()``` method.




