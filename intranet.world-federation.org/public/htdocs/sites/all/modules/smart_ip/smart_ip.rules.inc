<?php

/**
 * @file
 * Rules integration for the Smart IP module.
 *
 * @addtogroup rules
 * @{
 */

/**
 * Implements hook_rules_condition_info().
 */
function smart_ip_rules_condition_info() {
  $items = array();
  $items['smart_ip_condition_in_country'] = array(
    'label' => t('IP address is in country'),
    'group' => t('Smart IP'),
    'parameter' => array(
      'country' => array(
        'type' => 'list<text>',
        'label' => t('Country'),
        'description' => t('The countries to check for.'),
        'options list' => 'smart_ip_country_list',
      ),
    ),
  );
  return $items;
}

/**
 * Returns a list of countires from locale module.
 */
function smart_ip_country_list() {
  include_once DRUPAL_ROOT . '/includes/locale.inc';
  return country_get_list();
}

/**
 * Condition implementation for IP address in country.
 */
function smart_ip_condition_in_country($countries) {
  $ip = ip_address();
  $result = smart_ip_get_location($ip);
  if (isset($countries[$result['country_code']])) {
    return TRUE;
  }
  return FALSE;
}

/**
 * @}
 */
