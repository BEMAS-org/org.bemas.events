<?php

class CRM_Events_BemasEvent {
  public static function processHookCustom($groupID, $entityID, $params) {
    if (self::isEventEvaluationGroup($groupID) && self::shouldCreateSurvey($entityID)) {
      $survey = new CRM_Events_BemasSurvey();
      $surveyNids = $survey->createForEvent($entityID);
      if ($surveyNids === FALSE) {
        // surveys already created, do nothing
      }
      else {
        self::addSurveyLinks($entityID, $surveyNids);
      }
    }

    if (self::isEventDetailsGroup($groupID)) {
      CRM_Events_BemasCatering::onEventDetailsSave($entityID, $params);
    }
  }

  private static function isEventEvaluationGroup($groupID) {
    if ($groupID == 27) {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  private static function isEventDetailsGroup($groupID) {
    return (int) $groupID === CRM_Events_BemasCatering::ACTIVITEIT_STATUS_CUSTOM_GROUP_ID;
  }

  private static function shouldCreateSurvey($eventId) {
    if (self::hasCreateSurveyFlag($eventId) && self::hasEmptySurveyLinks($eventId)) {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  private static function hasCreateSurveyFlag($eventId) {
    $retval = FALSE;

    $result = civicrm_api3('CustomValue', 'get', [
      'entity_id' => $eventId,
      'return' => 'custom_154',
      'sequential' => 1,
    ]);

    if ($result['count'] > 0) {
      if ($result['values'][0]['latest'] == 1) {
        $retval = TRUE;
      }
    }

    return $retval;
  }

  private static function hasEmptySurveyLinks($eventId) {
    $retval = TRUE;

    $result = civicrm_api3('CustomValue', 'get', [
      'entity_id' => $eventId,
      'return' => 'custom_155',
      'sequential' => 1,
    ]);

    if ($result['count'] > 0) {
      if (strlen($result['values'][0]['latest']) > 0) {
        $retval = FALSE;
      }
    }

    return $retval;
  }

  public static function addSurveyLinks($eventId, $surveyNids) {
    // participant
    if (!empty($surveyNids['participant_survey_nid'])) {
      civicrm_api3('CustomValue', 'create', [
        'entity_id' => $eventId,
        'custom_155' => self::getUrlFromNid($surveyNids['participant_survey_nid']),
      ]);
    }

    // trainer
    if (!empty($surveyNids['trainer_survey_nid'])) {
      civicrm_api3('CustomValue', 'create', [
        'entity_id' => $eventId,
        'custom_156' => self::getUrlFromNid($surveyNids['trainer_survey_nid']),
      ]);
    }
  }

  private static function getUrlFromNid($nid) {
    global $base_url;

    $lang = self::getWebformLang($nid);
    $urlAlias = drupal_get_path_alias('node/' . $nid, $lang);
    $url = "$base_url/$lang/$urlAlias";
    return '<a href="' . $url . '">' . $url . '</a>';
  }

  private static function getWebformLang($nid) {
    $node = node_load($nid);
    if ($node) {
      $titleParts = explode(' - ', $node->title);
      $lang = self::getLangFromCourseCode($titleParts[0]);
    }
    else {
      $lang = 'nl';
    }

    return $lang;
  }

  private static function getLangFromCourseCode($courseCode) {
    $lastLetter = substr($courseCode, -1);

    if ($lastLetter == 'V') {
      return 'nl';
    }

    if ($lastLetter == 'W') {
      return 'fr';
    }

    if ($lastLetter == 'N') {
      return 'en';
    }

    // default
    return 'nl';
  }
}
