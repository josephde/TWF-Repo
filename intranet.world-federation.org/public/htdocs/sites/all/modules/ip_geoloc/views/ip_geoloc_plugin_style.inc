<?php
/**
 * @file
 * ip_geoloc_plugin_style.inc
 *
 * Helper functions for Views style plugins, in particular to extract location
 * data (lat/long columns) from the associated view.
 *
 * @todo turn this into a common base clase for ip_geoloc_style_*.inc.
 */

define('IP_GEOLOC_MAP_CENTER_FIXED', 0);
define('IP_GEOLOC_MAP_CENTER_ON_FIRST_LOCATION', 1);
define('IP_GEOLOC_MAP_CENTER_ON_VISITOR', 2);
define('IP_GEOLOC_MAP_CENTER_OF_LOCATIONS', 3);
define('IP_GEOLOC_MAP_CENTER_OF_LOCATIONS_WEIGHTED', 4);
define('IP_GEOLOC_MAP_CENTER_ON_LOCATION_FROM_ARGUMENT', 5);

function ip_geoloc_plugin_style_bulk_of_form($views_plugin_style, &$form, &$form_state) {

  $form['ip_geoloc_views_plugin_latitude'] = array(
    '#title' => t('Name of latitude field in Views query'),
    '#type' => 'textfield',
    '#default_value' => $views_plugin_style->options['ip_geoloc_views_plugin_latitude'],
    '#description' => t('Use the default, <strong>ip_geoloc_latitude</strong>, unless your view involves other modules or relationships, in which case you may have to prefix the name with the relevant table name(s), delimited by underscores. For instance, if you use a view based on the <strong>Location</strong> module, use <strong>location_latitude</strong>. If the latitude is stored in a <strong>text field</strong>, then enter the field\'s machine name, e.g. when using the <strong>Geofield</strong>, <strong>Geolocation field</strong> or <strong>Get Locations</strong> modules enter <strong>field_<em>yourfieldname</em></strong>. Valid field names are on <a href="!url">this page</a>.', array(
      '!url' => url('admin/reports/fields/views-fields')))
  );
  $form['ip_geoloc_views_plugin_longitude'] = array(
    '#title' => t('Name of longitude field in Views query'),
    '#type' => 'textfield',
    '#default_value' => $views_plugin_style->options['ip_geoloc_views_plugin_longitude'],
    '#description' => t('See comments above. When using the <strong>Geofield</strong>, <strong>Geolocation field</strong> or <strong>Get Locations</strong> modules you can leave this field empty.')
  );

  $differentiator = isset($form_state['differentiator'])
    ? $form_state['differentiator']
    : $views_plugin_style->options['differentiator']['differentiator_field'];

  if (isset($form_state['triggering_element'])) {
    // Get here when any form element with #ajax was changed/clicked causing
    // an auto-rebuild of the form. Can't put this in an ajax handler, as these
    // are called AFTER the form rebuild, so too late.
    if (strpos($form_state['triggering_element']['#id'], 'differentiator-differentiator-field') > 0) {
      // Get here when is was the differentiator drop-down that was changed.
      $differentiator = $form_state['triggering_element']['#value'];
      unset($form_state['num_associations']);
    }
  }
  $form_state['differentiator'] = $differentiator;
  $form_state['no_cache'] = FALSE; // or AJAX won't work!

  // Add wrapper for differentiator drop-down, association table and buttons.
  // The id in the prefix must match the AJAX sbumit handlers below.
  $form['differentiator'] = array(
    '#type' => 'fieldset',
    '#title' => t('Location differentiator and color associations'),
    '#description' => t('You may designate one field as a <strong>location differentiator</strong>. Locations with the same differentiator value in the view will have the same marker colors on the map. Examples of location differentiators are content type, taxonomy terms, number of bed rooms, star rating etc... just about any enumeration field from your view may be selected as a location differentiator.'),
    '#prefix' => '<div id="differentiator-wrapper">',
    '#suffix' => '</div>'
  );

  $fields = ip_geoloc_get_display_fields($views_plugin_style->display->handler);
  $instance = ip_geoloc_get_field_instance($differentiator);

  if (empty($differentiator)) {
    $description = t('Optionally select a location differentiator.');
  }
  else {
    $description = t('Below associate "%differentiator" values with map marker colors.', array(
      '%differentiator' => isset($instance) ? $instance['label'] : $fields[$differentiator]
    )) . '<br/>';
  }
  $form['differentiator']['differentiator_field'] = array(
    '#title' => t('Location differentiator'),
    '#type' => 'select',
    '#default_value' => $differentiator,
    '#options' => $fields,
    '#description' => $description,
    '#ajax' => array(
      'callback' => '_ip_geoloc_plugin_style_refresh_color_table_js',
      'wrapper' => 'differentiator-wrapper'
    )
  );
  if (!empty($differentiator)) {
    // Container for the differentiator color associations table.
    $form['differentiator']['color_table'] = array(
      '#theme' => 'ip_geoloc_plugin_style_differentiator_color_table',
    //'#prefix' => '<div id="differentiator-color-table-wrapper">',
    //'#suffix' => '</div>'
    );

    _ip_geoloc_plugin_style_differentiator_color_table_form($form, $form_state);

    $form['differentiator']['add-another'] = array(
      '#type' => 'submit',
      '#value' => empty($form_state['num_associations']) ? t('Add an association') : t('Add another association'),
      '#weight' => 1,
      '#submit' => array('_ip_geoloc_plugin_style_add_association_submit'),
      '#ajax' => array(
        'callback' => '_ip_geoloc_plugin_style_refresh_color_table_js',
        'wrapper' => 'differentiator-wrapper',
        'effect' => 'fade', // or 'none' or 'slide'
        'speed' => 'fast',  // or 'slow' or number of millisec
      )
    );
    if (!empty($form_state['num_associations'])) {
      $form['differentiator']['remove'] = array(
        '#type' => 'submit',
        '#value' => t('Remove bottom association'),
        '#weight' => 2,
        '#submit' => array('_ip_geoloc_plugin_style_remove_association_submit'),
        '#ajax' => array(
          'callback' => '_ip_geoloc_plugin_style_refresh_color_table_js',
          'wrapper' => 'differentiator-wrapper',
          'effect' => 'none', // or 'fade' or 'slide'
          'speed' => 'fast',  // or 'slow' or number of millisec
        )
      );
    }
  }
  $is_openlayers = $form_state['renderer'] == 'openlayers';
  $form['default_marker_color'] = array(
    '#title' => t('Default location marker color'),
    '#type' => 'select',
    '#multiple' => FALSE, // !$is_openlayers,
    '#size' => 1, // $is_openlayers ? 1 : 2, // may not be granted, 4 is minimum in some browsers
    '#default_value' => $views_plugin_style->options['default_marker_color'],
    '#options' => $is_openlayers ? ip_geoloc_openlayers_marker_layers() : ip_geoloc_marker_colors(),
    '#description' => t('Select one color to apply to all location markers whose colors are not overridden by the <strong>Location differentiator</strong> above.'),
    '#attributes' => array(
      'class' => $is_openlayers ? array('marker-color ol') : array('marker-color gmap')),
    '#attached' => array(
      'css' => array(drupal_get_path('module', 'ip_geoloc') . '/ip_geoloc.css')
    )
  );
  $form['center_option'] = array(
    '#title' => t('Map centering options'),
    '#type' => 'radios',
    '#default_value' => $views_plugin_style->options['center_option'],
    '#options' => array(
      IP_GEOLOC_MAP_CENTER_FIXED => t('Fixed center; see note below *)'),
      IP_GEOLOC_MAP_CENTER_ON_FIRST_LOCATION => t('Use the first location returned by the view as the center of the map.'),
      IP_GEOLOC_MAP_CENTER_ON_VISITOR => t("Center the map on the visitor's current location."),
      IP_GEOLOC_MAP_CENTER_OF_LOCATIONS => t('Use the center of the rectangle whose sides are defined by the left-most, right-most, top and bottom locations (this option is insensitive to location clusters).'),
      IP_GEOLOC_MAP_CENTER_OF_LOCATIONS_WEIGHTED => t('Use the center of gravity based on all locations (this option is sensitive to location clusters)')
    ),
    '#description' => $is_openlayers
      ? t('*) If you want a fixed center, visit the "Center & Bounds" tab of the <a href="@url">map edit page</a>.', array(
          '@url' => url('admin/structure/openlayers/maps/' . $views_plugin_style->options['map'] . '/edit')))
      : t('*) If you choose the first option you may center the map via the special <strong>Map options</strong> <em>"centerLat"</em> and <em>"centerLng"</em> for example: %center_example', array(
          '%center_example' => '{ "mapTypeId":"roadmap", "centerLat":-37.8, "centerLng":145 }'))
  );

  return $form;
}

/**
 * Submit handler for the "Add another association" button.
 *
 * Increments the counter and forces a form rebuild.
 */
function _ip_geoloc_plugin_style_add_association_submit($form, &$form_state) {
  $form_state['num_associations']++;
  $form_state['rebuild'] = TRUE;
}

/**
 * Submit handler for the "Remove" button.
 *
 * Decrements the counter and forces a form rebuild.
 */
function _ip_geoloc_plugin_style_remove_association_submit($form, &$form_state) {
  $form_state['num_associations']--;
  $form_state['rebuild'] = TRUE;
}

/**
 * Ajax callback in response to new association rows being added or removed or
 * the differentiator drop-down being changed.
 *
 * At this point the $form has already been rebuilt. All we have to do here is
 * tell AJAX what part of the browser form needs to be updated.
 */
function _ip_geoloc_plugin_style_refresh_color_table_js($form, &$form_state) {
  // Return the updated table, so that ajax.inc can issue commands to the
  // browser to update only the targeted sections of the page.
  return $form['options']['style_options']['differentiator'];
}

/**
 * Submit handler as declared in ip_geoloc_form_alter().
 */
function ip_geoloc_plugin_style_differentiator_color_associations_submit($form, &$form_state) {
  if (empty($form_state['differentiator'])) {
    return;
  }
  $differentiator = $form_state['differentiator'];
  $differentiator_color_associations = array();
  if (!empty($form_state['values']['color_table'])) {
    foreach ($form_state['values']['color_table'] as $association) {

      if (is_array($association[$differentiator])) { // e.g mapping['field_file_under'][0]['tid']
        foreach ($association[$differentiator][LANGUAGE_NONE] as $key => $value_array) {
          $differentiator_value = reset($value_array);
          if (!empty($differentiator_value)) {
            $differentiator_color_associations[$differentiator_value] = $association['color'];
          }
        }
      }
      else { // plain text field
        $differentiator_value = trim($association[$differentiator]);
        if (!empty($differentiator_value)) {
          $differentiator_color_associations[$differentiator_value] = $association['color'];
        }
      }
    }
  }
  variable_set('ip_geoloc_' . $form_state['view']->name . '_' . $differentiator . '_color_mappings', $differentiator_color_associations);
}

function _ip_geoloc_plugin_style_differentiator_color_table_form(&$form, &$form_state) {

  $is_openlayers = $form_state['renderer'] == 'openlayers';

  // First the saved rows...
  // @todo: if $field['cardinality'] > 1, compress multiple differentiator values
  // for the same color together in a single row
  $differentiator = $form_state['differentiator'];
  $row = 0;
  $differentiator_color_associations = variable_get('ip_geoloc_' . $form_state['view']->name . '_' . $differentiator . '_color_mappings', array('' => 'red'));
  foreach ($differentiator_color_associations as $differentiator_value => $color) {
    if (isset($form_state['num_associations']) && $row >= $form_state['num_associations']) {
      break;
    }
    $form['differentiator']['color_table'][$row] = _ip_geoloc_plugin_style_diff_color_table_row_form($is_openlayers, $row, $differentiator, $differentiator_value, $color);
    $row++;
  }
  // ... then the empty rows
  if (!isset($form_state['num_associations'])) {
    $form_state['num_associations'] = count($differentiator_color_associations);
  }
  while ($row < $form_state['num_associations']) {
    $form['differentiator']['color_table'][$row] = _ip_geoloc_plugin_style_diff_color_table_row_form($is_openlayers, $row, $differentiator);
    $row++;
  }
}

function _ip_geoloc_plugin_style_diff_color_table_row_form($is_openlayers, $row, $differentiator, $differentiator_value = '', $color = '') {

  if (drupal_substr($differentiator, 0, 6) == 'field_') {
    $field = field_info_field($differentiator);
    $instance = ip_geoloc_get_field_instance($differentiator);
    $instance['label'] = ''; // no label, unless other modules override this
    $instance['required'] = FALSE; // don't want asterisk to appear
    $items[0][key($field['columns'])] = $differentiator_value;
    $form['#parents'] = array();
    $form_state = array();
    $form = field_default_form($instance['entity_type'], NULL, $field, $instance, LANGUAGE_NONE, $items, $form, $form_state);
    $form[$differentiator]['#attributes']['class'][] = $is_openlayers ? 'differentiator ol' : 'differentiator gmap';
  }
  else {
    $form[$differentiator] = array(
      '#type' => 'textfield',
      '#default_value' => $differentiator_value,
      '#attributes' => array(
        'class' => $is_openlayers ? array('differentiator ol') : array('differentiator gmap')
      )
    );
  }
  $form['color'] = array(
    '#type' => 'select',
    '#multiple' => FALSE, // !$is_openlayers,
    '#size' => 1, // $is_openlayers ? 1 : 2, // may not be granted, 4 is minimum in some browsers
    '#default_value' => $color,
    '#options' => $is_openlayers ? ip_geoloc_openlayers_marker_layers() : ip_geoloc_marker_colors(),
    '#description' => NULL, // $is_openlayers ? NULL : t('Select one'),
    '#attributes' => array(
      'class' => $is_openlayers ? array('marker-color ol') : array('marker-color gmap')
    )
  );

  // We'll manually set the #parents property of these fields so that their
  // values appear in the $form_state['values']['color_table'] array.
  $form[$differentiator]['#parents'] = array('color_table', $row, $differentiator);
  $form['color']['#parents'] = array('color_table', $row, 'color');

  return $form;
}

/**
 * Return HTML for differentiator to color associations table.
 *
 * @param $variables
 *   An associative array containing $variables['form']: a render element
 *   representing the form.
 *
 * @ingroup themeable
 */
function theme_ip_geoloc_plugin_style_differentiator_color_table($variables) {
  // Use the first form child to find out the name of the differentiator.
  $form = $variables['form'];
  $form_children = element_children($form);
  if (empty($form_children)) {
    return '';
  }
  $key = reset($form_children);
  foreach ($form[$key] as $attribute_name => $element) {
    if (drupal_substr($attribute_name, 0, 1) != '#' && $attribute_name != 'color') {
      $differentiator = $attribute_name;
      break;
    }
  }
  if (empty($differentiator)) {
    return '';
  }
  $instance = ip_geoloc_get_field_instance($differentiator);
  $differentiator_label = isset($instance) ? $instance['label'] : $differentiator;

  $headers = array(
    t('%differentiator value', array('%differentiator' => $differentiator_label)),
    t('Associated marker color')
  );
  $rows = array();
  foreach ($form_children as $key) {
    $row = array('data' => array(), 'class' => array());
    $row['data'][] = drupal_render($form[$key][$differentiator]);
    $row['data'][] = drupal_render($form[$key]['color']);
    $rows[] = $row;
  }

  $output = theme('table', array('header' => $headers, 'rows' => $rows, 'attributes' => array('id' => 'differentiator-color-table')));
  $output .= drupal_render_children($form);
  return $output;
}

/**
 * Extract an array of locations from the supplied views_plugin_style.
 *
 * @param type $views_plugin_style
 * @return array of location objects, each containing lat/long and balloon_text
 */
function ip_geoloc_plugin_style_extract_locations($views_plugin_style) {

  $latitude  = trim($views_plugin_style->options['ip_geoloc_views_plugin_latitude' ]);
  $longitude = trim($views_plugin_style->options['ip_geoloc_views_plugin_longitude']);

  if (empty($views_plugin_style->options['differentiator']['differentiator_field'])) {
    $differentiator_name = NULL;
    $differentiator_color_associations = array();
  }
  else {
    $differentiator_name = $views_plugin_style->options['differentiator']['differentiator_field'];
    $differentiator_color_associations = variable_get('ip_geoloc_' . $views_plugin_style->view->name . '_' . $differentiator_name . '_color_mappings', array());
  }

  $locations = array();
  foreach ($views_plugin_style->view->result as $i => $row) {

    $location = _ip_geoloc_plugin_style_extract_lat_lng($row, $latitude, $longitude);

    if ($location) {
      // Remaining row values go into the balloon
      if (!empty($views_plugin_style->rendered_fields[$i])) {
        $location->balloon_text = implode('<br/>', $views_plugin_style->rendered_fields[$i]);
      }

      _ip_geoloc_plugin_style_set_marker_color($location, $row, $differentiator_name, $differentiator_color_associations);
      $locations[] = $location;
    }
  }
  return $locations;
}

function _ip_geoloc_plugin_style_extract_lat_lng($row, $latitude, $longitude) {
  $location = new stdClass();
  // First look for $latitude and $longitude as node FIELD values:
  $field_name = 'field_' . $latitude;
  if (!empty($row->{$field_name}[0]['raw'])) {
    // For Geofield, Geolocation Field or Get Location modules lat/long are as follows:
    // Geofield     : field_<field_name>[0]['raw']['lat']      and field_<field_name>[0]['raw']['lon']
    // Geolocation  : field_<field_name>[0]['raw']['lat']      and field_<field_name>[0]['raw']['lng']
    // Get Locations: field_<field_name>[0]['raw']['latitude'] and field_<field_name>[0]['raw']['longitude']
    if (isset($row->{$field_name}[0]['raw']['lon'])) { // Geofield
      $location->latitude  = $row->{$field_name}[0]['raw']['lat'];
      $location->longitude = $row->{$field_name}[0]['raw']['lon'];
    }
    elseif (isset($row->{$field_name}[0]['raw']['lng'])) { // GeoLocation
      $location->latitude  = $row->{$field_name}[0]['raw']['lat'];
      $location->longitude = $row->{$field_name}[0]['raw']['lng'];
    }
    elseif (isset($row->{$field_name}[0]['raw']['longitude'])) { // Get Locations
      $location->latitude  = $row->{$field_name}[0]['raw']['latitude'];
      $location->longitude = $row->{$field_name}[0]['raw']['longitude'];
    }
    else { // Other module
      // Field values tend to be inside [0]['raw']['value']:
      $location->latitude  = $row->{'field_' . $latitude }[0]['raw']['value'];
      $location->longitude = $row->{'field_' . $longitude}[0]['raw']['value'];
    }
  }
  elseif (!empty($row->{$latitude}) && !empty($row->{$longitude})) {
    // If not node fields then db table fields...
    $location->latitude  = $row->{$latitude};
    $location->longitude = $row->{$longitude};
  }
  return isset($location->latitude) ? $location : NULL;
}

function _ip_geoloc_plugin_style_set_marker_color(&$location, $row, $differentiator_name, $differentiator_color_associations) {
  if (!empty($differentiator_name)) {
    if (!empty($row->{$differentiator_name})) {
      $differentiator_value = $row->{$differentiator_name};
    }
    elseif (!empty($row->{'field_' . $differentiator_name})) {
      $differentiator_value = $row->{'field_' . $differentiator_name};
      if (is_array($differentiator_value)) {
        if (isset($differentiator_value[0]['raw']['tid'])) {
          $differentiator_value = $differentiator_value[0]['raw']['tid'];
        }
        elseif (isset($differentiator_value[0]['raw']['value'])) {
          $differentiator_value = $differentiator_value[0]['raw']['value'];
        }
        elseif (isset($differentiator_value[0]['rendered']['#markup'])) {
          $differentiator_value = $differentiator_value[0]['rendered']['#markup'];
        }
      }
    }
    else {
      $differentiator_value = '';
    }
    if (!empty($differentiator_color_associations[$differentiator_value])) {
      $location->marker_color = $differentiator_color_associations[$differentiator_value];
    }
  }
  if (isset($location->marker_color) && is_array($location->marker_color)) {
    $location->marker_color = reset($location->marker_color);
  }
}

/**
 * Perform token replacement, convert timestamps to date strings etc. for
 *
 * Store the rendered rows on the object passed in, which will typically be an
 * instance of class views_plugin_style or subclass.
 * Note that fields that have their Exclude box ticked, won't be rendered,
 * Typical candidates for exclusion are the latitude and longitude fields.
 *
 * @param $views_plugin_style
 * @param $result
 *   The result array on the view, e.g.
 */
function ip_geoloc_plugin_style_render_fields($views_plugin_style) {
  if (!$views_plugin_style->uses_fields()) {
    return;
  }
  if (!isset($views_plugin_style->rendered_fields)) {
    $views_plugin_style->rendered_fields = array();
    $field_ids = array_keys($views_plugin_style->view->field);
    foreach ($views_plugin_style->view->result as $i => $row) {
      $views_plugin_style->view->row_index = $i; // God knows why we need this...
      foreach ($field_ids as $field_id) {
        if ($views_plugin_style->view->field[$field_id]->options['exclude']) {
          continue;
        }
        // Add the field label if it's provided
        $label = $views_plugin_style->view->field[$field_id]->label();
        $element = '';
        $close_element = '';
        if ($label) {
          $label_type = $views_plugin_style->view->field[$field_id]->options['element_label_type'];
          if ($label_type) {
            $label_type = check_plain($label_type);
            $element = '<' . $label_type;
            $label_class = $views_plugin_style->view->field[$field_id]->options['element_label_class'];
            if ($label_class) {
              $element .= ' class="' . check_plain($label_class) . '"';
            }
            $element .= '>';
            $close_element = '</' . $label_type . '>';
          }
          if ($views_plugin_style->view->field[$field_id]->options['element_label_colon']) {
            $label .= ': ';
          }
          $views_plugin_style->rendered_fields[$i][$field_id] =
            $element . $label . $close_element . ' ' . $views_plugin_style->view->field[$field_id]->theme($row);
        } // otherwise render with no label
        else {
          $views_plugin_style->rendered_fields[$i][$field_id] = $views_plugin_style->view->field[$field_id]->theme($row);
        }
      }
      //$views_plugin_style->row_tokens[$i] = $views_plugin_style->view->field[$field_id]->get_render_tokens(array());
    }
    unset($views_plugin_style->view->row_index);
  }
  return $views_plugin_style->rendered_fields;
}

function ip_geoloc_get_display_fields($view_display) {
  $fields = array('' => '<' . t('none') . '>');
  foreach ($view_display->get_handlers('field') as $field_id => $field_handler) {
    if (drupal_substr($field_id, 0, 6) != 'field_') {
      // Example: 'title' becomes 'node_title'
      $field_id = $field_handler->table . "_$field_id";
    }
    $fields[$field_id] = $field_handler->ui_name();
  }
  return $fields;
}

function ip_geoloc_get_field_instance($field_name) {
  foreach (field_info_instances() as $type_bundles) {
    foreach ($type_bundles as $bundle_instances) {
      foreach ($bundle_instances as $f_name => $instance) {
        if ($f_name == $field_name) {
          return $instance;
        }
      }
    }
  }
  return NULL;
}
