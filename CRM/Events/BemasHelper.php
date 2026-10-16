<?php

/**
 * Generic data-access/codec helpers backing CRM_Events_BemasCatering.
 *
 * IMPORTANT: this install has true multilingual column-splitting
 * (civicrm_event.title, civicrm_price_set.title, civicrm_price_field_value.
 * label/description are each three physical columns: _en_US/_nl_NL/_fr_FR).
 * CiviCRM's DAO layer silently rewrites bare table/column names to whichever
 * locale is active for the current request - not guaranteed consistent
 * across admin actions, and a rewritten table's view doesn't expose other
 * locales' columns (so e.g. `title_nl_NL` fails once rewritten to
 * `civicrm_event_en_US`). So every query here that touches automation
 * bookkeeping (not UI cosmetics) names the real `_nl_NL` column AND passes
 * `$i18nRewrite = FALSE`, hitting the base table directly.
 */
class CRM_Events_BemasHelper {

  /**
   * This domain's configured multilingual locales (e.g. ['en_US','nl_NL','fr_FR']),
   * read from civicrm_domain.locales - the same source CiviCRM itself uses to
   * decide which "_xx_YY"-suffixed columns physically exist. Used instead of a
   * hardcoded list so writeToAllLocales() keeps working if a locale is ever
   * added or removed, with no code change needed here.
   *
   * @return array
   */
  private static function getConfiguredLocales() {
    static $locales = NULL;
    if ($locales === NULL) {
      $raw = (string) CRM_Core_DAO::singleValueQuery(
        'SELECT locales FROM civicrm_domain WHERE id = %1',
        [1 => [(int) CRM_Core_Config::domainID(), 'Integer']]
      );
      $locales = array_filter(explode(CRM_Core_DAO::VALUE_SEPARATOR, $raw));
    }
    return $locales;
  }

  /**
   * Write the same value to a locale-split column (e.g. price_set.title,
   * price_field_value.description) across every configured locale, so it
   * reads correctly regardless of which UI language a viewer is browsing in.
   *
   * @param string $table
   * @param string $column
   * @param int $id
   * @param string $value
   */
  private static function writeToAllLocales($table, $column, $id, $value) {
    $locales = self::getConfiguredLocales();
    if (empty($locales)) {
      return;
    }
    $setClauses = [];
    foreach ($locales as $locale) {
      $setClauses[] = "{$column}_{$locale} = %2";
    }
    CRM_Core_DAO::executeQuery(
      "UPDATE {$table} SET " . implode(', ', $setClauses) . ' WHERE id = %1',
      [
        1 => [(int) $id, 'Integer'],
        2 => [$value, 'String'],
      ],
      TRUE,
      NULL,
      FALSE,
      FALSE
    );
  }

  /**
   * Resolve an event's title, preferring the `_nl_NL` column (BEMAS's
   * operational language, and the only one automation logic should trust by
   * default) but falling back to `_en_US`/`_fr_FR` when the Dutch title
   * doesn't follow the "<code> - <description>" convention. This matters in
   * practice: events created via "Copy Previous Event" start with a
   * placeholder Dutch title (e.g. "Copy of Titel opleiding"), and staff
   * sometimes then rename the event while browsing the English admin UI -
   * which only updates `title_en_US`, leaving the stale Dutch placeholder
   * behind. Without this fallback, a validly-renamed event would silently
   * skip automation just because the wrong locale column got edited.
   *
   * @param int $eventId
   * @return string
   *   The first locale's title (in nl_NL, en_US, fr_FR order) that follows
   *   the "<code> - <description>" convention; the raw nl_NL title (which
   *   may be empty or not follow the convention) if none of them do.
   */
  private static function resolveEventTitle($eventId) {
    $titles = CRM_Core_DAO::executeQuery(
      'SELECT title_nl_NL, title_en_US, title_fr_FR FROM civicrm_event WHERE id = %1',
      [1 => [$eventId, 'Integer']],
      TRUE,
      NULL,
      FALSE,
      FALSE
    );
    if (!$titles->fetch()) {
      return '';
    }
    foreach ([$titles->title_nl_NL, $titles->title_en_US, $titles->title_fr_FR] as $candidate) {
      if (count(explode(' - ', trim((string) $candidate))) >= 2) {
        return trim((string) $candidate);
      }
    }
    return trim((string) $titles->title_nl_NL);
  }

  /**
   * Build a price set title that's easy to find in the (very long) admin
   * "Manage Price Sets" listing: the event title plus a searchable event id
   * marker.
   *
   * @param int $eventId
   * @return string
   */
  public static function buildFindableTitle($eventId) {
    return self::resolveEventTitle($eventId) . ' (Event #' . $eventId . ')';
  }

  /**
   * Make sure an already-existing price set (e.g. one CiviCRM's own Fees tab
   * created before this automation ever ran for this event) has a findable
   * title too - only rewrites it if it doesn't already carry the marker, so
   * this doesn't stomp on a title staff may have customized themselves.
   *
   * @param int $priceSetId
   * @param int $eventId
   */
  public static function ensureFindableTitle($priceSetId, $eventId) {
    $marker = '(Event #' . $eventId . ')';
    $currentTitle = (string) CRM_Core_DAO::singleValueQuery(
      'SELECT title_nl_NL FROM civicrm_price_set WHERE id = %1',
      [1 => [$priceSetId, 'Integer']],
      TRUE,
      FALSE
    );
    if (strpos($currentTitle, $marker) === FALSE) {
      self::writeToAllLocales('civicrm_price_set', 'title', $priceSetId, self::buildFindableTitle($eventId));
    }
  }

  /**
   * Look up a price field value's current label.
   *
   * @param int $priceFieldValueId
   * @return string|null
   */
  public static function getPriceFieldValueLabel($priceFieldValueId) {
    if (empty($priceFieldValueId)) {
      return NULL;
    }
    return CRM_Core_DAO::singleValueQuery(
      'SELECT label_nl_NL FROM civicrm_price_field_value WHERE id = %1',
      [1 => [$priceFieldValueId, 'Integer']],
      TRUE,
      FALSE
    );
  }

  /**
   * Resolve which price field a price field value belongs to.
   *
   * @param int $priceFieldValueId
   * @return int|null
   */
  public static function getPriceFieldIdForValueId($priceFieldValueId) {
    if (empty($priceFieldValueId)) {
      return NULL;
    }
    return (int) CRM_Core_DAO::singleValueQuery(
      'SELECT price_field_id FROM civicrm_price_field_value WHERE id = %1',
      [1 => [$priceFieldValueId, 'Integer']],
      TRUE,
      FALSE
    );
  }

  /**
   * Read an event's configured Catering rate custom field value.
   *
   * @param int $eventId
   * @return string|null
   *   '25', '65', 'Exception', or NULL when not configured.
   */
  public static function getCateringRate($eventId) {
    $fieldName = CRM_Invoicestoexact_Config::singleton()->getEventCateringRateCustomField('name');
    if (empty($fieldName)) {
      return NULL;
    }
    $fqn = 'Activiteit_status.' . $fieldName;

    $event = \Civi\Api4\Event::get(FALSE)
      ->addSelect($fqn)
      ->addWhere('id', '=', $eventId)
      ->execute()->first();

    $value = $event[$fqn] ?? NULL;
    return ($value === NULL || $value === '') ? NULL : (string) $value;
  }

  /**
   * Read an event's configured Catering days custom field value.
   *
   * @param int $eventId
   * @return int
   */
  public static function getCateringDays($eventId) {
    $fieldName = CRM_Invoicestoexact_Config::singleton()->getEventCateringDaysCustomField('name');
    if (empty($fieldName)) {
      return 0;
    }
    $fqn = 'Activiteit_status.' . $fieldName;

    $event = \Civi\Api4\Event::get(FALSE)
      ->addSelect($fqn)
      ->addWhere('id', '=', $eventId)
      ->execute()->first();

    $value = $event[$fqn] ?? NULL;
    return is_numeric($value) ? max(0, (int) $value) : 0;
  }

  /**
   * Extract the event code from an event's title.
   *
   * @param int $eventId
   * @return string
   *   The event code (the part of the title before " - "), e.g.
   *   "TC260310-05V - Some Title" -> "TC260310-05V". Empty string when the
   *   title doesn't follow the "<code> - <description>" convention.
   */
  public static function getEventCode($eventId) {
    $title = self::resolveEventTitle($eventId);
    $parts = explode(' - ', $title);
    return count($parts) >= 2 ? trim($parts[0]) : '';
  }

  /**
   * Decide whether a price field value's label marks it as automation-managed.
   *
   * @param string|null $label
   * @param array $flatTierLabels
   *   Labels of flat/administrative tiers that are never automation-managed
   *   (matched case-insensitively, trimmed) - see
   *   CRM_Events_BemasCatering::FLAT_TIER_LABELS.
   * @return bool TRUE unless the label matches one of $flatTierLabels.
   */
  public static function isManagedLabel($label, array $flatTierLabels) {
    $label = strtolower(trim((string) $label));
    if ($label === '') {
      return FALSE;
    }
    return !in_array($label, $flatTierLabels, TRUE);
  }

  /**
   * Build the shadow bookkeeping record stored per managed price field value.
   * Tagged with the event id it was computed for, so a shadow inherited from
   * a different event (e.g. via "Copy Previous Event", which duplicates the
   * whole price set including this description) is never mistaken for this
   * event's own history - see isShadowForEvent().
   *
   * @param float $allIn
   * @param float $rate
   * @param int $days
   * @param float $net
   * @param int $eventId
   * @return array
   */
  public static function buildShadow($allIn, $rate, $days, $net, $eventId) {
    return [
      'bemas_catering_managed' => 1,
      'event_id' => (int) $eventId,
      'all_in' => round((float) $allIn, 2),
      'rate' => round((float) $rate, 2),
      'days' => (int) $days,
      'net' => round((float) $net, 2),
    ];
  }

  /**
   * @param array|null $shadow
   * @param int $eventId
   * @return bool TRUE if the shadow was computed for this event, or was
   *   written before this tagging existed (no event_id - trusted as-is,
   *   rather than invalidating every already-correct historical shadow;
   *   it gets tagged on its next write). FALSE only for a shadow that IS
   *   tagged but for a different event - i.e. copied over via "Copy
   *   Previous Event" (which duplicates the whole price set, description
   *   included) - never trusted for comparison against this event.
   */
  public static function isShadowForEvent($shadow, $eventId) {
    if (empty($shadow)) {
      return FALSE;
    }
    if (!array_key_exists('event_id', $shadow)) {
      return TRUE;
    }
    return (int) $shadow['event_id'] === (int) $eventId;
  }

  /**
   * Human-readable summary line + the raw JSON on its own line, written to
   * the price field value's Description (visible via CiviCRM's own "Edit
   * Option" screen) so the original all-in figure isn't only ever visible by
   * querying the database directly.
   *
   * @return string
   */
  public static function formatShadowForStorage(array $shadow) {
    $summary = sprintf(
      'All-in: EUR %.2f - Catering: EUR %.2f x %d days = EUR %.2f - Net: EUR %.2f',
      $shadow['all_in'],
      $shadow['rate'],
      $shadow['days'],
      $shadow['rate'] * $shadow['days'],
      $shadow['net']
    );
    return $summary . "\n" . json_encode($shadow);
  }

  /**
   * Decode a shadow record, ignoring anything not written by this automation.
   * Tolerant of the human-readable summary line formatShadowForStorage()
   * prepends - extracts just the JSON part.
   *
   * @param string|null $raw
   * @return array|null
   */
  public static function decodeShadow($raw) {
    if (empty($raw)) {
      return NULL;
    }
    if (preg_match('/\{.*\}/s', $raw, $matches)) {
      $raw = $matches[0];
    }
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded) || empty($decoded['bemas_catering_managed'])) {
      return NULL;
    }
    return $decoded;
  }

  /**
   * Read and decode a price field value's stored shadow record.
   *
   * @param int $priceFieldValueId
   * @return array|null
   */
  public static function readShadow($priceFieldValueId) {
    $raw = CRM_Core_DAO::singleValueQuery(
      'SELECT description_nl_NL FROM civicrm_price_field_value WHERE id = %1',
      [1 => [$priceFieldValueId, 'Integer']],
      TRUE,
      FALSE
    );
    return self::decodeShadow($raw);
  }

  /**
   * Persist a shadow record onto a price field value's description column.
   * Written to every configured locale (not just nl_NL) so the human-readable
   * summary is visible via CiviCRM's own "Edit Option" screen regardless of
   * which UI language an admin is browsing in - that core screen reads via
   * the ambient locale, unlike readShadow()/decodeShadow() below, which stay
   * pinned to nl_NL for the automation's own determinism.
   *
   * @param int $priceFieldValueId
   * @param array $shadow
   */
  public static function writeShadow($priceFieldValueId, array $shadow) {
    self::writeToAllLocales('civicrm_price_field_value', 'description', $priceFieldValueId, self::formatShadowForStorage($shadow));
  }

}
