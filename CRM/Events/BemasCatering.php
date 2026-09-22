<?php

use CRM_Events_BemasHelper as Helper;

/**
 * Automates event/catering price-field derivation for BEMAS events: staff
 * keep typing the all-in price into the Regular Fees radio field, and this
 * derives the net Member/Non-member amount and keeps a CAT-<code> catering
 * field in sync. Only price field values marked as managed (JSON shadow in
 * their description column) are touched; flat tiers (Speaker, Responsible
 * Registration) are left alone. Locks once an event has live registrations.
 *
 * Generic data-access/codec helpers live in CRM_Events_BemasHelper.
 */
class CRM_Events_BemasCatering {

  const FINANCIAL_TYPE_EVENT_FEE = 4;
  const CATERING_RATE_EXCEPTION = 'Exception';
  const ACTIVITEIT_STATUS_CUSTOM_GROUP_ID = 25;

  // No UI field lets staff opt a row into automation, so it's inferred from
  // the label instead. Extend if BEMAS adds another flat tier.
  const FLAT_TIER_LABELS = [
    'lesgever/spreker',
    'responsible registration',
  ];

  // Shadow computed in onPriceFieldValuePre() for a new PriceFieldValue,
  // persisted in onPriceFieldValuePost() once its id is known.
  private static $pendingShadow = NULL;

  /**
   * hook_civicrm_customPre - fires before the write, so (unlike
   * onEventDetailsSave()'s hook_civicrm_custom) it can actually block a
   * locked event's Catering rate/days from changing, on any save path.
   */
  public static function onEventDetailsPre($op, $groupID, $entityID, array &$params) {
    if ((int) $groupID !== self::ACTIVITEIT_STATUS_CUSTOM_GROUP_ID || !in_array($op, ['create', 'edit'])) {
      return;
    }
    $eventId = (int) $entityID;
    if ($eventId <= 0 || !Helper::eventHasLiveParticipants($eventId)) {
      return;
    }

    $rateFieldId = (int) CRM_Invoicestoexact_Config::singleton()->getEventCateringRateCustomField('id');
    $daysFieldId = (int) CRM_Invoicestoexact_Config::singleton()->getEventCateringDaysCustomField('id');

    $reverted = FALSE;
    foreach ($params as &$field) {
      $fieldId = (int) ($field['custom_field_id'] ?? 0);
      if (!empty($rateFieldId) && $fieldId === $rateFieldId) {
        $field['value'] = Helper::getCateringRate($eventId);
        $reverted = TRUE;
      }
      elseif (!empty($daysFieldId) && $fieldId === $daysFieldId) {
        $field['value'] = Helper::getCateringDays($eventId);
        $reverted = TRUE;
      }
    }
    unset($field);

    if ($reverted) {
      CRM_Core_Session::setStatus(ts('This event already has registrations - catering configuration can no longer be changed.'), ts('Locked'), 'error');
    }
  }

  /**
   * Handle a save of the Catering rate / Catering days custom fields
   * (Activiteit_status group) - re-derive the CAT-* field and every
   * managed tier's net amount for this event.
   *
   * @param int $eventId
   * @param array $params
   *   Unused directly - the authoritative values are re-read from the DB,
   *   since a single hook_civicrm_custom call only carries the field(s)
   *   that were actually touched, not the full picture.
   *
   * @throws Exception when the event already has live registrations.
   */
  public static function onEventDetailsSave($eventId, $params) {
    $eventId = (int) $eventId;
    if ($eventId <= 0) {
      return;
    }

    if (Helper::eventHasLiveParticipants($eventId)) {
      throw new Exception(ts('This event already has registrations - catering configuration can no longer be changed.'));
    }

    $rate = Helper::getCateringRate($eventId);
    if ($rate === NULL || $rate === self::CATERING_RATE_EXCEPTION || !is_numeric($rate)) {
      return;
    }
    $rateValue = (float) $rate;
    $days = Helper::getCateringDays($eventId);

    $code = CRM_Invoicestoexact_ExactHelper::buildCateringCode(Helper::getEventCode($eventId));
    if ($code === '') {
      // Invalid/missing code: never derive a net amount with no catering line to show for it.
      Civi::log()->error('BEMAS catering automation: could not derive a catering code from the title of event ' . $eventId . ' - skipping automated pricing. Title must be "<code> - <description>" with a recognised code prefix (TC/TT/OT/T/A/E).');
      return;
    }

    $priceSetId = self::findOrCreatePriceSet($eventId);
    if (empty($priceSetId)) {
      return;
    }

    if (!self::hasRegularFeesField($priceSetId)) {
      // Regular Fees hasn't been configured for this event yet. Creating the
      // CAT-* field now would leave it as the price set's only field, which
      // breaks CiviCRM's own Regular Fees screen (CRM_Event_Form_ManageEvent_Fee
      // naively assumes a price set has exactly one field and would mistake
      // CAT-* for the fee field, corrupting it on save). Deferred instead -
      // onPriceFieldValuePre() creates CAT-* once a real fee is first entered.
      return;
    }

    self::findOrCreateCateringField($priceSetId, $code, $rateValue);
    self::recomputeManagedTiers($priceSetId, $rateValue, $days, $eventId);
  }

  /**
   * Handle a create/edit of a PriceFieldValue (a registration-fee radio
   * option's amount). Called from hook_civicrm_pre.
   *
   * @param string $op
   * @param int|null $id
   * @param array $params
   *
   * @throws Exception when the event already has live registrations AND a brand new
   *   price option (op = 'create') is being added - there's no prior amount to revert
   *   to, so this is the only way to block it. An edit to an *existing* option on a
   *   locked event is instead silently reverted to its current amount (no throw) - see
   *   the comment inline below for why.
   */
  public static function onPriceFieldValuePre($op, $id, array &$params) {
    if (!in_array($op, ['create', 'edit'])) {
      return;
    }

    $priceFieldId = (int) ($params['price_field_id'] ?? Helper::getPriceFieldIdForValueId((int) $id));
    if (empty($priceFieldId)) {
      return;
    }

    $fieldDao = CRM_Core_DAO::executeQuery(
      'SELECT price_set_id, html_type FROM civicrm_price_field WHERE id = %1',
      [1 => [$priceFieldId, 'Integer']],
      TRUE,
      NULL,
      FALSE,
      FALSE
    );
    if (!$fieldDao->fetch()) {
      return;
    }
    if (strtolower((string) $fieldDao->html_type) === 'text') {
      // The CAT-* field itself is managed by findOrCreateCateringField(), not this flow.
      return;
    }

    $eventId = self::resolveManagedEventForPriceSet((int) $fieldDao->price_set_id);
    if (empty($eventId)) {
      return;
    }

    if (Helper::eventHasLiveParticipants($eventId)) {
      if ($op === 'edit' && !empty($id)) {
        // Revert instead of throw: CRM_Price_Form_Option::postProcess() has no
        // try/catch, so a thrown exception here becomes an uncaught fatal-error
        // page. Reverting the amount makes the save a no-op without crashing.
        $params['amount'] = (float) CRM_Core_DAO::singleValueQuery(
          'SELECT amount FROM civicrm_price_field_value WHERE id = %1',
          [1 => [(int) $id, 'Integer']],
          TRUE,
          FALSE
        );
        CRM_Core_Session::setStatus(ts('This event already has registrations - pricing can no longer be changed.'), ts('Locked'), 'error');
        return;
      }
      throw new Exception(ts('This event already has registrations - pricing can no longer be changed.'));
    }

    $rate = Helper::getCateringRate($eventId);
    if ($rate === NULL || $rate === self::CATERING_RATE_EXCEPTION || !is_numeric($rate)) {
      return;
    }
    $rateValue = (float) $rate;
    $days = Helper::getCateringDays($eventId);

    $code = CRM_Invoicestoexact_ExactHelper::buildCateringCode(Helper::getEventCode($eventId));
    if ($code === '') {
      return;
    }
    // Safe to create CAT-* here (unlike onEventDetailsSave()): this fires on a
    // Radio-type PriceFieldValue save, so the price set already has its Regular
    // Fees field by now - CAT-* becomes the second field, never the first.
    self::findOrCreateCateringField((int) $fieldDao->price_set_id, $code, $rateValue);

    $existingShadow = ($op === 'edit' && !empty($id)) ? Helper::readShadow((int) $id) : NULL;
    if (!Helper::isShadowForEvent($existingShadow, $eventId)) {
      // Inherited from a different event (e.g. via "Copy Previous Event", which
      // duplicates the whole price set including this description) - not
      // applicable to this event. Treat as if this row has never been seen
      // here, so whatever's typed now is respected as a fresh all-in entry.
      $existingShadow = NULL;
    }

    $label = $params['label'] ?? (($op === 'edit' && !empty($id)) ? Helper::getPriceFieldValueLabel((int) $id) : NULL);
    if (empty($existingShadow) && !Helper::isManagedLabel($label, self::FLAT_TIER_LABELS)) {
      return;
    }

    if (!isset($params['amount']) || !is_numeric($params['amount'])) {
      return;
    }
    $incomingAmount = (float) $params['amount'];

    if (!empty($existingShadow) && abs($incomingAmount - (float) $existingShadow['net']) < 0.01) {
      // Unchanged since our own last derivation - nothing new to convert.
      return;
    }

    $allIn = $incomingAmount;
    $net = max(0.0, $allIn - ($rateValue * $days));
    $params['amount'] = $net;

    $shadow = Helper::buildShadow($allIn, $rateValue, $days, $net, $eventId);

    if ($op === 'edit' && !empty($id)) {
      Helper::writeShadow((int) $id, $shadow);
    }
    else {
      // No id yet for a create - persist once hook_civicrm_post fires.
      self::$pendingShadow = $shadow;
    }
  }

  /**
   * Handle the post-save of a newly created PriceFieldValue, to persist the
   * shadow computed in onPriceFieldValuePre() now that its id is known.
   *
   * @param int $id
   */
  public static function onPriceFieldValuePost($id) {
    if (!empty(self::$pendingShadow) && !empty($id)) {
      Helper::writeShadow((int) $id, self::$pendingShadow);
    }
    self::$pendingShadow = NULL;
  }

  /**
   * Find the event's price set, creating one (mirroring what CiviCRM's own
   * Fees tab does for a "quick config" event price set) if it doesn't exist
   * yet. Refuses (returns NULL, logs a warning) if the resolved price set is
   * shared by more than one event - writing a derived net amount into a
   * shared price set would silently corrupt sibling events' pricing.
   *
   * @param int $eventId
   * @return int|null
   */
  private static function findOrCreatePriceSet($eventId) {
    $priceSetId = CRM_Price_BAO_PriceSet::getFor('civicrm_event', $eventId);

    if (!empty($priceSetId)) {
      $sharedCount = (int) CRM_Core_DAO::singleValueQuery(
        'SELECT COUNT(DISTINCT entity_id) FROM civicrm_price_set_entity WHERE price_set_id = %1',
        [1 => [$priceSetId, 'Integer']]
      );
      if ($sharedCount > 1) {
        Civi::log()->warning('BEMAS catering automation: price set ' . $priceSetId . ' for event ' . $eventId . ' is shared by multiple events - skipping automated pricing.');
        return NULL;
      }
      Helper::ensureFindableTitle((int) $priceSetId, $eventId);
      return (int) $priceSetId;
    }

    $priceSet = CRM_Price_BAO_PriceSet::create([
      'title' => Helper::buildFindableTitle($eventId),
      'name' => 'bemas_event_' . $eventId . '_pricing',
      'is_quick_config' => 1,
      'financial_type_id' => self::FINANCIAL_TYPE_EVENT_FEE,
      'extends' => CRM_Core_Component::getComponentID('CiviEvent'),
    ]);
    CRM_Price_BAO_PriceSet::addTo('civicrm_event', $eventId, $priceSet->id);
    Helper::ensureFindableTitle((int) $priceSet->id, $eventId);

    return (int) $priceSet->id;
  }

  /**
   * @return bool TRUE if the price set already has an active Regular Fees
   *   (non-Text) field - i.e. staff have configured Fees at least once.
   */
  private static function hasRegularFeesField($priceSetId) {
    return (bool) CRM_Core_DAO::singleValueQuery(
      "SELECT 1 FROM civicrm_price_field WHERE price_set_id = %1 AND html_type <> 'Text' AND is_active = 1 LIMIT 1",
      [1 => [$priceSetId, 'Integer']]
    );
  }

  /**
   * @param int $priceSetId
   * @param string $code
   * @return array|null
   */
  private static function findCateringField($priceSetId, $code) {
    return \Civi\Api4\PriceField::get(FALSE)
      ->addWhere('price_set_id', '=', $priceSetId)
      ->addWhere('label', '=', $code)
      ->execute()->first();
  }

  /**
   * Find-or-create the CAT-<code> catering price field/value in the given
   * price set, and keep its unit price in sync with the current rate.
   *
   * @param int $priceSetId
   * @param string $code
   * @param float $rate
   */
  private static function findOrCreateCateringField($priceSetId, $code, $rate) {
    $field = self::findCateringField($priceSetId, $code);

    if (empty($field)) {
      $field = \Civi\Api4\PriceField::create(FALSE)
        ->setValues([
          'price_set_id' => $priceSetId,
          'name' => strtolower(str_replace('-', '_', $code)),
          'label' => $code,
          'html_type' => 'Text',
          'is_enter_qty' => TRUE,
          'is_display_amounts' => TRUE,
          'is_active' => TRUE,
          'weight' => 20,
        ])
        ->execute()->first();
    }

    $value = \Civi\Api4\PriceFieldValue::get(FALSE)
      ->addWhere('price_field_id', '=', $field['id'])
      ->execute()->first();

    if (empty($value)) {
      \Civi\Api4\PriceFieldValue::create(FALSE)
        ->setValues([
          'price_field_id' => $field['id'],
          'name' => str_replace('-', '_', $code),
          'label' => $code,
          'amount' => $rate,
          'financial_type_id' => self::FINANCIAL_TYPE_EVENT_FEE,
          'is_active' => TRUE,
        ])
        ->execute();
    }
    elseif (abs((float) $value['amount'] - $rate) > 0.001) {
      \Civi\Api4\PriceFieldValue::update(FALSE)
        ->addWhere('id', '=', $value['id'])
        ->setValues(['amount' => $rate])
        ->execute();
    }
  }

  /**
   * Re-derive the net amount for every automation-managed price field value
   * in the given price set, from its stored all-in figure - never from the
   * currently-displayed net amount, so a rate/days change never compounds.
   *
   * @param int $priceSetId
   * @param float $rateValue
   * @param int $days
   * @param int $eventId
   */
  private static function recomputeManagedTiers($priceSetId, $rateValue, $days, $eventId) {
    $dao = CRM_Core_DAO::executeQuery(
      "SELECT pfv.id, pfv.label_nl_NL AS label, pfv.amount, pfv.description_nl_NL AS description
       FROM civicrm_price_field_value pfv
       INNER JOIN civicrm_price_field pf ON pf.id = pfv.price_field_id
       WHERE pf.price_set_id = %1 AND pf.html_type <> 'Text'",
      [1 => [$priceSetId, 'Integer']],
      TRUE,
      NULL,
      FALSE,
      FALSE
    );

    while ($dao->fetch()) {
      $shadow = Helper::decodeShadow($dao->description);

      if (!empty($shadow) && !Helper::isShadowForEvent($shadow, $eventId)) {
        // Inherited from a different event (e.g. via "Copy Previous Event") -
        // the current amount is that other event's net, not a genuine all-in
        // for this one. Never guess; skip and let staff re-enter this fee's
        // all-in price once, which correctly converts it and re-tags the
        // shadow to this event via onPriceFieldValuePre().
        Civi::log()->warning('BEMAS catering automation: price field value ' . $dao->id . ' for event ' . $eventId . ' carries pricing inherited from a different event - skipping automatic recompute until the all-in price is re-entered for this event.');
        continue;
      }

      if (empty($shadow)) {
        // First time seen: bootstrap from the current amount as the all-in
        // figure, unless it's a flat tier (never touched).
        if (!Helper::isManagedLabel($dao->label, self::FLAT_TIER_LABELS)) {
          continue;
        }
        $allIn = (float) $dao->amount;
      }
      else {
        $allIn = (float) $shadow['all_in'];
      }

      $net = max(0.0, $allIn - ($rateValue * $days));

      CRM_Core_DAO::executeQuery(
        'UPDATE civicrm_price_field_value SET amount = %2 WHERE id = %1',
        [1 => [$dao->id, 'Integer'], 2 => [$net, 'Float']],
        TRUE,
        NULL,
        FALSE,
        FALSE
      );

      Helper::writeShadow((int) $dao->id, Helper::buildShadow($allIn, $rateValue, $days, $net, $eventId));
    }
  }

  /**
   * Resolve the event a price set belongs to, refusing (returning NULL,
   * logging a warning) when it's shared by more than one event.
   *
   * @param int $priceSetId
   * @return int|null
   */
  private static function resolveManagedEventForPriceSet($priceSetId) {
    $rows = CRM_Core_DAO::executeQuery(
      "SELECT entity_id FROM civicrm_price_set_entity WHERE price_set_id = %1 AND entity_table = 'civicrm_event'",
      [1 => [$priceSetId, 'Integer']]
    );

    $eventIds = [];
    while ($rows->fetch()) {
      $eventIds[] = (int) $rows->entity_id;
    }

    if (empty($eventIds)) {
      return NULL;
    }
    if (count($eventIds) > 1) {
      Civi::log()->warning('BEMAS catering automation: price set ' . $priceSetId . ' is shared by multiple events - skipping automated pricing.');
      return NULL;
    }

    return $eventIds[0];
  }

}
