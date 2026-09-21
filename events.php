<?php

require_once 'events.civix.php';
use CRM_Events_ExtensionUtil as E;
use CRM_Events_BemasHelper as Helper;

function events_civicrm_custom($op, $groupID, $entityID, &$params) {
  if ($op == 'create' || $op == 'edit') {
    try {
      CRM_Events_BemasEvent::processHookCustom($groupID, $entityID, $params);
    }
    catch (Exception $e) {
      CRM_Core_Session::setStatus($e->getMessage(), ts('Error'), 'error');
    }
  }
}

/**
 * Implements hook_civicrm_customPre().
 *
 * Fires before a custom field value is written (unlike hook_civicrm_custom
 * above, which only fires after) - used to hard-block edits to the Catering
 * rate/days fields once an event is locked, regardless of which screen the
 * edit came from. See CRM_Events_BemasCatering::onEventDetailsPre().
 */
function events_civicrm_customPre($op, $groupID, $entityID, &$params) {
  if (in_array($op, ['create', 'edit'])) {
    CRM_Events_BemasCatering::onEventDetailsPre($op, $groupID, $entityID, $params);
  }
}

/**
 * Implements hook_civicrm_pre().
 *
 * Intercepts saves of a registration-fee Price Field Value to automatically
 * derive its net (all-in minus catering) amount, for automation-managed
 * events/tiers. See CRM_Events_BemasCatering for the full logic.
 */
function events_civicrm_pre($op, $objectName, $id, &$params) {
  if ($objectName == 'PriceFieldValue' && in_array($op, ['create', 'edit'])) {
    try {
      CRM_Events_BemasCatering::onPriceFieldValuePre($op, $id, $params);
    }
    catch (Exception $e) {
      CRM_Core_Session::setStatus($e->getMessage(), ts('Error'), 'error');
      throw $e;
    }
  }
}

/**
 * Implements hook_civicrm_post().
 *
 * Persists the shadow bookkeeping record for a brand new Price Field Value,
 * once its id is known (see CRM_Events_BemasCatering::onPriceFieldValuePre()).
 */
function events_civicrm_post($op, $objectName, $objectId, $objectRef) {
  if ($objectName == 'PriceFieldValue' && $op == 'create') {
    CRM_Events_BemasCatering::onPriceFieldValuePost($objectId);
  }
}

function events_civicrm_buildForm($formName, &$form) {
  global $language;

  if ($formName == 'CRM_Event_Form_Registration_Register') {
    CRM_Core_Resources::singleton()->addScriptFile('org.bemas.events', 'js/bemaseventregistration.js');

    if ($form->getAction() == CRM_Core_Action::ADD) {
      $defaults = [];

      /*
       * set the preferred language default value to the CMS language
       */
      if ($language->language == 'en') {
        $defaults['preferred_language'] = 'en_US';
      }
      elseif ($language->language == 'nl') {
        $defaults['preferred_language'] = 'nl_NL';
      }
      elseif ($language->language == 'fr') {
        $defaults['preferred_language'] = 'fr_FR';
      }

      // see if we have the contact id and checksum in the URL
      $cid = CRM_Utils_Request::retrieve('cid', 'String');
      $cs = CRM_Utils_Request::retrieve('cs', 'String');
      if ($cid && $cs) {
        $isValidUser = CRM_Contact_BAO_Contact_Utils::validChecksum($cid, $cs);
        if ($isValidUser) {
          // get the current employer and billing details
          $employerID = CRM_Core_DAO::singleValueQuery("select employer_id from civicrm_contact where id = $cid and is_deleted = 0");
          if ($employerID) {
            // fill in the billing or main address
            $addr = CRM_Events_BemasParticipant::getAddress($employerID, 'billing');
            if ($addr) {
              $defaults['custom_95'] = $addr;
            }
            else {
              $addr = CRM_Events_BemasParticipant::getAddress($employerID, 'main');
              if ($addr) {
                $defaults['custom_95'] = $addr;
              }
            }

            // fill in VAT
            $defaults['custom_94'] = CRM_Events_BemasParticipant::getVat($employerID);
          }
        }
      }

      // set the defaults
      if (count($defaults) > 0) {
        $form->setDefaults($defaults);
      }
    }
  }

  if ($formName == 'CRM_Event_Form_ManageEvent_EventInfo' && !empty($form->_id)) {
    if (Helper::eventHasLiveParticipants((int) $form->_id)) {
      foreach (['getEventCateringRateCustomField', 'getEventCateringDaysCustomField'] as $getter) {
        $fieldId = CRM_Invoicestoexact_Config::singleton()->$getter('id');
        if (!empty($fieldId) && $form->elementExists('custom_' . $fieldId)) {
          $form->getElement('custom_' . $fieldId)->freeze();
        }
      }
    }
  }

  if ($formName == 'CRM_Event_Form_ManageEvent_Fee' && !empty($form->_id)) {
    $priceSetId = CRM_Price_BAO_PriceSet::getFor('civicrm_event', (int) $form->_id);
    if (!empty($priceSetId)) {
      $url = CRM_Utils_System::url('civicrm/admin/price/field', 'reset=1&sid=' . $priceSetId);
      CRM_Core_Session::setStatus(
        ts('This event\'s price fields (including any automated catering field) can be viewed/edited directly at: %1', [1 => '<a href="' . $url . '" target="_blank">' . $url . '</a>']),
        ts('Price set'),
        'info'
      );
    }
  }

  if ($formName == 'CRM_Price_Form_Option') {
    $priceSetId = CRM_Utils_Request::retrieve('sid', 'Positive');
    $eventId = !empty($priceSetId) ? Helper::getEventIdForPriceSet($priceSetId) : NULL;
    if (!empty($eventId) && Helper::eventHasLiveParticipants($eventId) && $form->elementExists('amount')) {
      $form->getElement('amount')->freeze();
    }
  }
}

function events_civicrm_tokens(&$tokens) {
  /*
  $tokens['event'] = [
    'event.bemas_evaluation_participant' => 'BEMAS url evaluatieformulier deelnemer',
    'event.bemas_evaluation_trainer' => 'BEMAS url evaluatieformulier trainer',
    'event.bemas_evaluation_participant_5_star' => 'BEMAS 5-star deelnemer'
  ];
  */
}

function events_civicrm_tokenValues(&$values, $cids, $job = null, $tokens = [], $context = null) {
  /*
  DOES NOT WORK!
  if ($eventId = CRM_Utils_Array::value('event', $values)) {
    $values['event.bemas_evaluation_participant'] = 'test ' . print_r($values['event'], TRUE);
  }
  */
}

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function events_civicrm_config(&$config) {
  _events_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function events_civicrm_install() {
  _events_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function events_civicrm_enable() {
  _events_civix_civicrm_enable();
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *

 // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 */
function events_civicrm_navigationMenu(&$menu) {
  _events_civix_navigationMenu($menu);
}
